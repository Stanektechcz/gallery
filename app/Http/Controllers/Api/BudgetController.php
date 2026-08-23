<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Budget;
use App\Models\BudgetCategory;
use App\Models\BudgetEntry;
use App\Models\GallerySpace;
use App\Models\MoneyRequest;
use App\Services\Finance\BudgetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BudgetController extends Controller
{
    public function __construct(private readonly BudgetService $budgets) {}

    public function index(Request $request): JsonResponse
    {
        $space = $this->space($request);
        $user = $request->user();

        return response()->json([
            'budgets' => $this->budgets->forUser($space, $user)->map(fn (Budget $budget) => [
                'uuid' => $budget->uuid,
                'name' => $budget->name,
                'currency' => $budget->currency,
                'starts_on' => $budget->starts_on->toDateString(),
                'ends_on' => $budget->ends_on?->toDateString(),
                'is_shared' => $budget->is_shared,
                'is_mine' => $budget->owner_user_id === $user->id,
                'owner' => $budget->owner?->name,
            ])->values(),
            'requests' => $this->requests($space, $user),
            'members' => $space->members()->get(['users.id', 'users.name'])
                ->reject(fn ($member) => $member->id === $user->id)
                ->map(fn ($member) => ['id' => $member->id, 'name' => $member->name])
                ->values(),
        ]);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $budget = $this->budget($request, $uuid);

        return response()->json($this->budgets->overview($budget));
    }

    public function store(Request $request): JsonResponse
    {
        $this->write($request);
        $space = $this->space($request);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'currency' => 'required|string|size:3',
            'starts_on' => 'required|date',
            'ends_on' => 'nullable|date|after_or_equal:starts_on',
            'monthly_income' => 'nullable|numeric|min:0',
            'note' => 'nullable|string|max:5000',
            'is_shared' => 'sometimes|boolean',
            'personal' => 'sometimes|boolean',
        ]);

        $budget = Budget::create([
            'gallery_space_id' => $space->id,
            // Osobní rozpočet patří tomu, kdo ho založil; společný nikomu.
            'owner_user_id' => ($data['personal'] ?? true) ? $request->user()->id : null,
            'name' => $data['name'],
            'currency' => strtoupper($data['currency']),
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'] ?? null,
            'monthly_income' => $data['monthly_income'] ?? null,
            'note' => $data['note'] ?? null,
            'is_shared' => $data['is_shared'] ?? false,
            'created_by' => $request->user()->id,
        ]);

        return response()->json($this->budgets->overview($budget), 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $this->write($request);
        $budget = $this->budget($request, $uuid, owned: true);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'currency' => 'sometimes|string|size:3',
            'starts_on' => 'sometimes|date',
            'ends_on' => 'nullable|date',
            'monthly_income' => 'nullable|numeric|min:0',
            'note' => 'nullable|string|max:5000',
            'is_shared' => 'sometimes|boolean',
        ]);

        $budget->update($data);

        return response()->json($this->budgets->overview($budget->fresh()));
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $this->write($request);
        $this->budget($request, $uuid, owned: true)->delete();

        return response()->json(['ok' => true]);
    }

    public function storeCategory(Request $request, string $uuid): JsonResponse
    {
        $this->write($request);
        $budget = $this->budget($request, $uuid, owned: true);

        $data = $request->validate([
            'name' => 'required|string|max:120',
            'planned_monthly' => 'nullable|numeric|min:0',
            'color' => 'nullable|string|max:20',
            'icon' => 'nullable|string|max:50',
        ]);

        BudgetCategory::create($data + [
            'budget_id' => $budget->id,
            'planned_monthly' => $data['planned_monthly'] ?? 0,
            'sort_order' => (int) $budget->categories()->max('sort_order') + 10,
        ]);

        return response()->json($this->budgets->overview($budget->fresh()), 201);
    }

    public function destroyCategory(Request $request, string $uuid, int $categoryId): JsonResponse
    {
        $this->write($request);
        $budget = $this->budget($request, $uuid, owned: true);
        $budget->categories()->whereKey($categoryId)->delete();

        return response()->json($this->budgets->overview($budget->fresh()));
    }

    public function storeEntry(Request $request, string $uuid): JsonResponse
    {
        $this->write($request);
        $budget = $this->budget($request, $uuid);

        $data = $request->validate([
            'kind' => 'required|in:expense,income',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'nullable|string|size:3',
            'spent_on' => 'required|date',
            'budget_category_id' => 'nullable|integer',
            'note' => 'nullable|string|max:500',
            'is_recurring' => 'sometimes|boolean',
        ]);

        // Kategorie musí patřit tomuhle rozpočtu, jinak by šlo zapsat do cizího.
        if (! empty($data['budget_category_id'])) {
            abort_unless($budget->categories()->whereKey($data['budget_category_id'])->exists(), 422, 'Kategorie do tohoto rozpočtu nepatří.');
        }

        BudgetEntry::create($data + [
            'budget_id' => $budget->id,
            'user_id' => $request->user()->id,
            'currency' => strtoupper($data['currency'] ?? $budget->currency),
        ]);

        return response()->json($this->budgets->overview($budget->fresh()), 201);
    }

    public function destroyEntry(Request $request, string $uuid, string $entryUuid): JsonResponse
    {
        $this->write($request);
        $budget = $this->budget($request, $uuid);
        $budget->entries()->where('uuid', $entryUuid)->delete();

        return response()->json($this->budgets->overview($budget->fresh()));
    }

    public function requestMoney(Request $request): JsonResponse
    {
        $this->write($request);
        $space = $this->space($request);

        $data = $request->validate([
            'to_user_id' => 'required|integer',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'required|string|size:3',
            'reason' => 'nullable|string|max:500',
        ]);

        $recipient = $space->members()->whereKey($data['to_user_id'])->firstOrFail();

        $this->budgets->requestMoney($space, $request->user(), $recipient, $data);

        return response()->json(['requests' => $this->requests($space, $request->user())], 201);
    }

    public function respondToRequest(Request $request, string $uuid): JsonResponse
    {
        $this->write($request);
        $space = $this->space($request);

        $data = $request->validate([
            'status' => 'required|in:sent,declined,cancelled',
            'settled_amount' => 'nullable|numeric|min:0',
            'settled_currency' => 'nullable|string|size:3',
            'exchange_rate' => 'nullable|numeric|min:0',
            'response_note' => 'nullable|string|max:500',
        ]);

        $money = MoneyRequest::where('gallery_space_id', $space->id)->where('uuid', $uuid)->firstOrFail();

        // Zrušit může jen ten, kdo žádal; poslat nebo zamítnout jen ten, koho se to týká.
        $user = $request->user();
        abort_unless(
            $data['status'] === 'cancelled' ? $money->from_user_id === $user->id : $money->to_user_id === $user->id,
            403,
            'O této žádosti nemůžete rozhodovat.',
        );

        $this->budgets->respond($money, $user, $data['status'], $data);

        return response()->json(['requests' => $this->requests($space, $user)]);
    }

    private function requests(GallerySpace $space, $user): array
    {
        return MoneyRequest::where('gallery_space_id', $space->id)
            ->where(fn ($q) => $q->where('from_user_id', $user->id)->orWhere('to_user_id', $user->id))
            ->with(['requester:id,name', 'recipient:id,name'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (MoneyRequest $money) => [
                'uuid' => $money->uuid,
                'amount' => (float) $money->amount,
                'currency' => $money->currency,
                'settled_amount' => $money->settled_amount !== null ? (float) $money->settled_amount : null,
                'settled_currency' => $money->settled_currency,
                'exchange_rate' => $money->exchange_rate !== null ? (float) $money->exchange_rate : null,
                'reason' => $money->reason,
                'status' => $money->status,
                'response_note' => $money->response_note,
                'created_at' => $money->created_at?->toIso8601String(),
                'responded_at' => $money->responded_at?->toIso8601String(),
                'from' => $money->requester?->name,
                'to' => $money->recipient?->name,
                'mine' => $money->from_user_id === $user->id,
            ])->values()->all();
    }

    private function budget(Request $request, string $uuid, bool $owned = false): Budget
    {
        $space = $this->space($request);
        $budget = Budget::where('gallery_space_id', $space->id)->where('uuid', $uuid)->firstOrFail();

        abort_unless($budget->isVisibleTo($request->user()), 403, 'Tento rozpočet vám není zpřístupněný.');

        if ($owned) {
            abort_unless(
                $budget->owner_user_id === null || $budget->owner_user_id === $request->user()->id,
                403,
                'Upravovat může jen vlastník rozpočtu.',
            );
        }

        return $budget;
    }

    private function space(Request $request): GallerySpace
    {
        return $request->user()->gallerySpaces()->firstOrFail();
    }

    private function write(Request $request): void
    {
        abort_if($request->user()->read_only_mode, 403, 'V režimu pouze pro čtení nelze rozpočty měnit.');
    }
}
