<?php

namespace App\Services\Banking;

use App\Models\AuditLog;
use App\Models\GallerySpace;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SharedExpenseWriteService
{
    public function __construct(private readonly SharedExpenseSettlementService $settlements) {}

    /**
     * Creates a shared expense through the single write path used by manual and assistant inputs.
     * The caller already controls the surrounding transaction when multiple linked records are made.
     */
    public function create(GallerySpace $space, User $actor, array $attributes, string $source = 'manual', ?string $sourceReference = null): object
    {
        abort_unless(Schema::hasTable('shared_expenses'), 404);

        $amount = round((float) ($attributes['amount'] ?? 0), 2);
        abort_if($amount <= 0, 422, 'Částka společného výdaje musí být vyšší než nula.');
        $category = (string) ($attributes['category'] ?? 'other');
        abort_unless(in_array($category, ['transport', 'accommodation', 'food', 'activities', 'insurance', 'other'], true), 422, 'Kategorie společného výdaje není platná.');

        $members = $this->settlements->members($space->id);
        $memberIds = $members->pluck('id')->all();
        $payerId = (int) ($attributes['paid_by_user_id'] ?? $actor->id);
        abort_unless(in_array($payerId, $memberIds, true), 422, 'Plátce musí být členem společného prostoru.');

        $tripId = ! empty($attributes['trip_id']) ? (int) $attributes['trip_id'] : null;
        if ($tripId) {
            abort_unless(DB::table('trips')->where('id', $tripId)->where('gallery_space_id', $space->id)->exists(), 422, 'Vybraná cesta nepatří do tohoto společného prostoru.');
        }

        $splitMode = (string) ($attributes['split_mode'] ?? 'equal');
        abort_unless(in_array($splitMode, ['equal', 'custom', 'gift'], true), 422, 'Způsob rozdělení výdaje není platný.');
        $shares = $this->settlements->shares($amount, $memberIds, $splitMode, (array) ($attributes['split'] ?? []));
        $occurredAt = Carbon::parse($attributes['occurred_at'] ?? now(), 'Europe/Prague');
        $metadata = array_merge(['source' => $source], (array) ($attributes['metadata'] ?? []));

        $id = DB::table('shared_expenses')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $space->id,
            'created_by' => $actor->id,
            'paid_by_user_id' => $payerId,
            'calendar_event_id' => $attributes['calendar_event_id'] ?? null,
            'trip_id' => $tripId,
            'title' => Str::limit(trim((string) ($attributes['title'] ?? 'Výdaj')), 255, ''),
            'category' => $category,
            'amount' => $amount,
            'currency' => strtoupper((string) ($attributes['currency'] ?? 'CZK')),
            'occurred_at' => $occurredAt,
            'source' => $source,
            'split_mode' => $splitMode,
            'split' => json_encode($shares),
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (Schema::hasColumn('shared_expenses', 'created_from')) {
            DB::table('shared_expenses')->where('id', $id)->update(['created_from' => $source, 'source_reference' => $sourceReference]);
        }

        $expense = DB::table('shared_expenses')->where('id', $id)->firstOrFail();
        AuditLog::record('finance.shared_expense.create', null, ['gallery_space_id' => $space->id, 'expense_uuid' => $expense->uuid, 'trip_id' => $tripId, 'source' => $source]);

        return $expense;
    }
}