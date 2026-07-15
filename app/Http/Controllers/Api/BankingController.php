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
use App\Services\Banking\TripBankReconciliationService;
use App\Services\Banking\TripFinancialInsightService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BankingController extends Controller
{
    public function __construct(private readonly BankingIntegrationService $banking, private readonly RevolutStatementImportService $imports,
        private readonly TripFinancialInsightService $insights, private readonly TripBankReconciliationService $reconciliation) {}

    public function overview(Request $request): JsonResponse
    {
        $space = $this->space($request, $request->integer('gallery_space_id'));

        return response()->json($this->insights->spaceOverview($space));
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
        $data = $request->validate(['gallery_space_id' => 'required|integer', 'statement' => 'required|file|max:20480|mimes:csv,txt,xlsx']);
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

    private function write(Request $request): void
    {
        abort_if($request->user()->read_only_mode, 403, 'V režimu pouze pro čtení nelze bankovní napojení měnit.');
    }
}
