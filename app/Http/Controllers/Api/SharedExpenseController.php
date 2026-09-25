<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\GallerySpace;
use App\Services\Auth\PristupDoGalerie;
use App\Services\Banking\SharedExpenseSettlementService;
use App\Services\Banking\SharedExpenseWriteService;
use App\Support\Cas;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SharedExpenseController extends Controller
{
    public function __construct(private readonly SharedExpenseWriteService $expenses,
        private readonly SharedExpenseSettlementService $settlements) {}

    public function store(Request $request): JsonResponse
    {
        $this->write($request);
        abort_unless(Schema::hasTable('shared_expenses'), 404);

        $data = $request->validate([
            'gallery_space_id' => 'required|integer',
            'title' => 'required|string|max:255',
            'category' => 'required|in:transport,accommodation,food,activities,insurance,other',
            'amount' => 'required|numeric|min:0.01|max:99999999.99',
            'currency' => 'required|string|size:3',
            'occurred_at' => 'required|date',
            'trip_id' => 'nullable|integer',
            'paid_by_user_id' => 'nullable|integer',
            'split_mode' => 'nullable|in:equal,custom,gift',
            'split' => 'nullable|array|max:20',
            'split.*.user_id' => 'required_with:split|integer',
            'split.*.amount' => 'required_with:split|numeric|min:0',
            'force' => 'nullable|boolean',
        ]);

        $spaceId = (int) $data['gallery_space_id'];
        // Jen prostor, kde účet patří do dvojice — host cizí galerie do jejích výdajů nezapisuje.
        abort_unless(in_array($spaceId, app(PristupDoGalerie::class)->idProstoruDvojice($request->user()), true), 403);
        if (! empty($data['trip_id'])) {
            abort_unless(DB::table('trips')->where('id', $data['trip_id'])->where('gallery_space_id', $spaceId)->exists(), 422, 'Vybraná cesta nepatří do tohoto společného prostoru.');
        }

        $data['currency'] = strtoupper($data['currency']);
        $data['title'] = trim($data['title']);
        $data['occurred_at'] = $this->occurredAt($data['occurred_at']);
        $duplicates = $this->duplicates($spaceId, $data);
        if ($duplicates->isNotEmpty() && empty($data['force'])) {
            return response()->json([
                'message' => 'Podobný výdaj už je zapsaný. Zkontrolujte jej před uložením další položky.',
                'duplicates' => $duplicates->values(),
            ], 409);
        }
        $space = GallerySpace::findOrFail($spaceId);
        $expense = $this->expenses->create($space, $request->user(), $data + [
            'metadata' => ['duplicate_override' => $duplicates->isNotEmpty()],
        ], 'manual');

        return response()->json($expense, 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $this->write($request);
        $expense = $this->expense($request, $uuid);
        $data = $request->validate([
            'title' => 'sometimes|string|max:255',
            'category' => 'sometimes|in:transport,accommodation,food,activities,insurance,other',
            'amount' => 'sometimes|numeric|min:0.01|max:99999999.99',
            'currency' => 'sometimes|string|size:3',
            'occurred_at' => 'sometimes|date',
            'paid_by_user_id' => 'sometimes|integer',
            'split_mode' => 'sometimes|in:equal,custom,gift',
            'split' => 'sometimes|array|max:20',
            'split.*.user_id' => 'required_with:split|integer',
            'split.*.amount' => 'required_with:split|numeric|min:0',
        ]);

        $values = collect($data)->only(['title', 'category', 'amount', 'currency'])->all();
        if (array_key_exists('currency', $values)) {
            $values['currency'] = strtoupper($values['currency']);
        }
        if (array_key_exists('title', $values)) {
            $values['title'] = trim($values['title']);
        }
        // Stejně jako při založení: čas podle hodin dvojice, uložený jako
        // `Y-m-d H:i:s`. Surový vstup („25 September 2026", ISO s posunem)
        // MySQL ve striktním režimu do sloupce datetime nepustí.
        if (array_key_exists('occurred_at', $data)) {
            $values['occurred_at'] = $this->occurredAt($data['occurred_at'])->format('Y-m-d H:i:s');
        }
        if (array_key_exists('paid_by_user_id', $data)) {
            $couple = $this->settlements->members((int) $expense->gallery_space_id)->pluck('id')->all();
            abort_unless(in_array((int) $data['paid_by_user_id'], $couple, true), 422, 'Plátce musí být jeden z partnerů společného prostoru.');
            $values['paid_by_user_id'] = (int) $data['paid_by_user_id'];
        }
        $values += $this->expenses->updatedSplit($expense, $data);
        DB::table('shared_expenses')->where('id', $expense->id)->update($values + ['updated_at' => now()]);
        $updated = DB::table('shared_expenses')->where('id', $expense->id)->firstOrFail();
        AuditLog::record('finance.shared_expense.update', null, ['gallery_space_id' => $expense->gallery_space_id, 'expense_uuid' => $uuid, 'changed' => array_keys($values)]);

        return response()->json($updated);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $this->write($request);
        $expense = $this->expense($request, $uuid);
        DB::table('shared_expenses')->where('id', $expense->id)->delete();
        AuditLog::record('finance.shared_expense.delete', null, ['gallery_space_id' => $expense->gallery_space_id, 'expense_uuid' => $uuid, 'title' => $expense->title]);

        return response()->json(['deleted' => true]);
    }

    /**
     * Čas výdaje podle hodin dvojice.
     *
     * Vstup bez pásma se čte jako pražský čas, vstup s posunem (ISO z
     * prohlížeče) se převede na pražské hodiny — ne na hodiny serveru (UTC).
     */
    private function occurredAt(string $value): Carbon
    {
        return Carbon::parse($value, Cas::pasmo())->setTimezone(Cas::pasmo());
    }

    private function duplicates(int $spaceId, array $data)
    {
        $date = Carbon::parse($data['occurred_at'])->toDateString();
        $needle = Str::lower(preg_replace('/\s+/u', ' ', trim($data['title'])));

        return DB::table('shared_expenses')
            ->where('gallery_space_id', $spaceId)
            ->where('currency', $data['currency'])
            ->where('amount', $data['amount'])
            ->whereDate('occurred_at', $date)
            ->orderByDesc('id')->limit(5)
            ->get(['uuid', 'title', 'amount', 'currency', 'occurred_at', 'category'])
            ->filter(function ($expense) use ($needle) {
                $title = Str::lower(preg_replace('/\s+/u', ' ', trim($expense->title)));

                return $title === $needle || Str::contains($title, $needle) || Str::contains($needle, $title);
            })->map(fn ($expense) => ['uuid' => $expense->uuid, 'title' => $expense->title, 'amount' => (float) $expense->amount, 'currency' => $expense->currency, 'occurred_at' => $expense->occurred_at, 'category' => $expense->category]);
    }

    private function expense(Request $request, string $uuid): object
    {
        abort_unless(Schema::hasTable('shared_expenses'), 404);

        return DB::table('shared_expenses')->where('uuid', $uuid)
            ->whereIn('gallery_space_id', app(PristupDoGalerie::class)->idProstoruDvojice($request->user()))
            ->firstOrFail();
    }

    private function write(Request $request): void
    {
        abort_if($request->user()->read_only_mode, 403, 'V režimu pouze pro čtení nelze společné výdaje měnit.');
    }
}
