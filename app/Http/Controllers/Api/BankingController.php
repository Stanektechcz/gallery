<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BankCategoryRule;
use App\Models\BankConnection;
use App\Models\BankTransaction;
use App\Models\GallerySpace;
use App\Services\Banking\BankingIntegrationService;
use App\Services\Banking\RevolutStatementImportService;
use App\Services\Banking\SpaceFinancialOverviewService;
use App\Services\Banking\TripBankReconciliationService;
use App\Services\Banking\TripFinancialInsightService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BankingController extends Controller
{
    public function __construct(private readonly BankingIntegrationService $banking, private readonly RevolutStatementImportService $imports,
        private readonly TripFinancialInsightService $insights, private readonly TripBankReconciliationService $reconciliation,
        private readonly SpaceFinancialOverviewService $financialOverview) {}

    public function overview(Request $request): JsonResponse
    {
        $space = $this->space($request, $request->integer('gallery_space_id'));

        return response()->json($this->insights->spaceOverview($space));
    }

    public function dashboard(Request $request): JsonResponse
    {
        $data = $request->validate(['gallery_space_id' => 'required|integer', 'from' => 'nullable|date', 'to' => 'nullable|date',
            'account_uuid' => 'nullable|uuid', 'category' => 'nullable|in:all,transport,accommodation,food,activities,insurance,other',
            'direction' => 'nullable|in:all,debit,credit', 'status' => 'nullable|in:all,booked,pending,cancelled',
            'review' => 'nullable|in:all,linked,suggested,unlinked',
            'query' => 'nullable|string|max:160', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|between:10,100']);
        $space = $this->space($request, (int) $data['gallery_space_id']);

        return response()->json($this->financialOverview->dashboard($space, $data));
    }

    public function institutions(Request $request): JsonResponse
    {
        $this->space($request, $request->integer('gallery_space_id'));

        return response()->json($this->banking->institutions($request->string('country', 'CZ')->toString()));
    }

    public function connect(Request $request): JsonResponse
    {
        $this->write($request);
        $data = $request->validate(['gallery_space_id' => 'required|integer', 'institution_id' => 'required|string|max:160',
            'country' => 'nullable|string|size:2', 'return_trip_id' => 'nullable|integer']);
        $space = $this->space($request, (int) $data['gallery_space_id']);
        if (! empty($data['return_trip_id'])) {
            DB::table('trips')->where('id', $data['return_trip_id'])->where('gallery_space_id', $space->id)->firstOrFail();
        }
        $result = $this->banking->connect($space, $request->user(), $data);
        AuditLog::record('bank.connection.start', $result['connection'], ['provider' => 'gocardless']);

        return response()->json(['connection_uuid' => $result['connection']->uuid, 'authorization_url' => $result['authorization_url']], 201);
    }

    public function sync(Request $request, string $uuid): JsonResponse
    {
        $this->write($request);
        $connection = $this->connection($request, $uuid);
        $result = $this->banking->sync($connection);
        AuditLog::record('bank.connection.sync', $connection, collect($result)->except('connection')->all());

        return response()->json($result);
    }

    public function disconnect(Request $request, string $uuid): JsonResponse
    {
        $this->write($request);
        $connection = $this->connection($request, $uuid);
        $result = $this->banking->disconnect($connection);
        AuditLog::record('bank.connection.disconnect', $connection, $result);

        return response()->json($result);
    }

    public function import(Request $request): JsonResponse
    {
        $this->write($request);
        $data = $request->validate(['gallery_space_id' => 'required|integer', 'statement' => 'required|file|max:51200|mimes:csv,txt,xls,xlsx']);
        $space = $this->space($request, (int) $data['gallery_space_id']);
        $result = $this->imports->import($space, $request->user(), $request->file('statement'));
        AuditLog::record('bank.statement.import', null, ['space_id' => $space->id, 'import_uuid' => $result['import']['uuid'], 'duplicate' => $result['duplicate_file']]);

        return response()->json($result, $result['duplicate_file'] ? 200 : 201);
    }

    public function storeRule(Request $request): JsonResponse
    {
        $this->write($request);
        $data = $request->validate(['gallery_space_id' => 'required|integer', 'field' => 'required|in:merchant,counterparty,description,type',
            'operator' => 'nullable|in:contains,equals,starts_with', 'pattern' => 'required|string|max:255',
            'category' => 'required|in:transport,accommodation,food,activities,insurance,other', 'trip_action' => 'nullable|in:suggest,include,exclude', 'priority' => 'nullable|integer|between:1,1000']);
        $space = $this->space($request, (int) $data['gallery_space_id']);
        $rule = BankCategoryRule::create($data + ['gallery_space_id' => $space->id, 'created_by' => $request->user()->id]);

        return response()->json($rule, 201);
    }

    public function destroyRule(Request $request, string $uuid): JsonResponse
    {
        $this->write($request);
        $rule = BankCategoryRule::where('uuid', $uuid)->whereIn('gallery_space_id', $request->user()->gallerySpaces()->pluck('gallery_spaces.id'))->firstOrFail();
        $rule->delete();

        return response()->json(['deleted' => true]);
    }

    public function updateTransaction(Request $request, string $uuid): JsonResponse
    {
        $this->write($request);
        $data = $request->validate(['category' => 'sometimes|required|in:transport,accommodation,food,activities,insurance,other',
            'trip_action' => 'sometimes|required|in:suggest,include,exclude']);
        $transaction = $this->transaction($request, $uuid);
        $updates = $data;
        if (array_key_exists('category', $data)) {
            $updates['category_is_manual'] = true;
        }
        $transaction->update($updates);

        $links = DB::table('trip_bank_transactions')->where('bank_transaction_id', $transaction->id)->get();
        foreach ($links as $link) {
            $linkUpdates = [];
            if (isset($data['category'])) {
                $linkUpdates['category'] = $data['category'];
            }
            if (($data['trip_action'] ?? null) === 'exclude') {
                $linkUpdates['status'] = 'excluded';
            }
            if ($linkUpdates) {
                $this->reconciliation->updateLink($link, $transaction, $linkUpdates, $request->user()->id);
            }
        }
        if (($data['trip_action'] ?? null) !== 'exclude') {
            $this->reconciliation->reconcile($transaction->account->connection->space, $transaction);
        }
        AuditLog::record('bank.transaction.update', $transaction, $data);

        return response()->json(['updated' => true, 'uuid' => $transaction->uuid]);
    }

    public function linkTransactionToTrip(Request $request, string $uuid): JsonResponse
    {
        $this->write($request);
        $data = $request->validate(['trip_id' => 'required|integer', 'allocated_amount' => 'nullable|numeric|min:0|max:999999999',
            'category' => 'nullable|in:transport,accommodation,food,activities,insurance,other']);
        $transaction = $this->transaction($request, $uuid);
        $spaceId = $transaction->account->connection->gallery_space_id;
        $trip = DB::table('trips')->where('id', $data['trip_id'])->where('gallery_space_id', $spaceId)->firstOrFail();
        $date = $transaction->booked_at->copy()->startOfDay();
        $timing = $date->lt(Carbon::parse($trip->start_date)) ? 'before' : ($date->gt(Carbon::parse($trip->end_date)) ? 'after' : 'during');
        $existing = DB::table('trip_bank_transactions')->where('trip_id', $trip->id)->where('bank_transaction_id', $transaction->id)->first();
        if (! $existing) {
            $id = DB::table('trip_bank_transactions')->insertGetId(['trip_id' => $trip->id, 'bank_transaction_id' => $transaction->id,
                'linked_by' => $request->user()->id, 'status' => 'suggested', 'confidence' => 100,
                'reason' => 'Ručně přiřazeno ve finančním přehledu.', 'category' => $data['category'] ?? $transaction->category,
                'timing' => $timing, 'created_at' => now(), 'updated_at' => now()]);
            $existing = DB::table('trip_bank_transactions')->find($id);
        }
        $transaction->update(['trip_action' => 'include']);
        $updated = $this->reconciliation->updateLink($existing, $transaction, ['status' => 'confirmed',
            'category' => $data['category'] ?? $transaction->category, 'allocated_amount' => $data['allocated_amount'] ?? abs((float) $transaction->amount)], $request->user()->id);
        AuditLog::record('bank.transaction.trip-link', $transaction, ['trip_id' => $trip->id, 'link_id' => $updated->id]);

        return response()->json(['linked' => true, 'trip_id' => $trip->id, 'link_id' => $updated->id], 201);
    }

    public function trip(Request $request, int $tripId): JsonResponse
    {
        $trip = DB::table('trips')->where('id', $tripId)->whereIn('gallery_space_id', $request->user()->gallerySpaces()->pluck('gallery_spaces.id'))->firstOrFail();

        return response()->json($this->insights->tripSnapshot($trip));
    }

    public function updateTripLink(Request $request, int $tripId, int $linkId): JsonResponse
    {
        $this->write($request);
        $trip = DB::table('trips')->where('id', $tripId)->whereIn('gallery_space_id', $request->user()->gallerySpaces()->pluck('gallery_spaces.id'))->firstOrFail();
        $data = $request->validate(['status' => 'sometimes|required|in:suggested,confirmed,excluded',
            'category' => 'sometimes|required|in:transport,accommodation,food,activities,insurance,other',
            'allocated_amount' => 'nullable|numeric|min:0|max:999999999', 'trip_activity_id' => 'nullable|integer', 'place_id' => 'nullable|integer',
            'note' => 'nullable|string|max:2000']);
        $link = DB::table('trip_bank_transactions')->where('id', $linkId)->where('trip_id', $trip->id)->firstOrFail();
        if (! empty($data['trip_activity_id'])) {
            abort_unless(DB::table('trip_activities as a')->join('trip_days as d', 'd.id', '=', 'a.trip_day_id')->where('a.id', $data['trip_activity_id'])->where('d.trip_id', $trip->id)->exists(), 422, 'Bod itineráře nepatří do této cesty.');
        }
        if (! empty($data['place_id'])) {
            abort_unless(DB::table('places')->where('id', $data['place_id'])->where('gallery_space_id', $trip->gallery_space_id)->exists(), 422, 'Místo nepatří do společného prostoru.');
        }
        $transaction = BankTransaction::findOrFail($link->bank_transaction_id);
        $updated = $this->reconciliation->updateLink($link, $transaction, $data, $request->user()->id);
        AuditLog::record('bank.trip-link.update', $transaction, ['trip_id' => $tripId, 'status' => $updated->status, 'category' => $updated->category]);

        return response()->json($this->insights->tripSnapshot($trip));
    }

    private function space(Request $request, ?int $id): GallerySpace
    {
        $query = $request->user()->gallerySpaces();

        return $id ? $query->whereKey($id)->firstOrFail() : $query->firstOrFail();
    }

    private function connection(Request $request, string $uuid): BankConnection
    {
        return BankConnection::where('uuid', $uuid)->whereIn('gallery_space_id', $request->user()->gallerySpaces()->pluck('gallery_spaces.id'))->firstOrFail();
    }

    private function transaction(Request $request, string $uuid): BankTransaction
    {
        return BankTransaction::where('uuid', $uuid)->whereHas('account.connection', fn ($query) => $query
            ->whereIn('gallery_space_id', $request->user()->gallerySpaces()->pluck('gallery_spaces.id')))->firstOrFail();
    }

    private function write(Request $request): void
    {
        abort_if($request->user()->read_only_mode, 403, 'V režimu pouze pro čtení nelze bankovní napojení měnit.');
    }
}
