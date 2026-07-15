<?php

namespace App\Services\Banking;

use App\Models\BankTransaction;
use App\Models\GallerySpace;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TripBankReconciliationService
{
    public function __construct(private readonly BankTransactionClassifier $classifier) {}

    public function reconcileSpace(GallerySpace $space): int
    {
        if (! Schema::hasTable('trip_bank_transactions')) {
            return 0;
        }
        $transactions = BankTransaction::query()->whereHas('account.connection', fn ($query) => $query->where('gallery_space_id', $space->id))
            ->where('status', 'booked')->orderBy('booked_at')->get();
        $count = 0;
        foreach ($transactions as $transaction) {
            $count += $this->reconcile($space, $transaction);
        }

        return $count;
    }

    public function reconcile(GallerySpace $space, BankTransaction $transaction): int
    {
        if ($transaction->trip_action === 'exclude') {
            return 0;
        }
        $date = $transaction->booked_at->copy()->startOfDay();
        $trips = DB::table('trips')->where('gallery_space_id', $space->id)
            ->whereDate('start_date', '<=', $date->copy()->addDays(45)->toDateString())
            ->whereDate('end_date', '>=', $date->copy()->subDays(7)->toDateString())->get();
        $candidates = $trips->map(function ($trip) use ($transaction, $date) {
            $start = Carbon::parse($trip->start_date)->startOfDay();
            $end = Carbon::parse($trip->end_date)->endOfDay();
            $timing = $date->betweenIncluded($start, $end) ? 'during' : ($date->lt($start) ? 'before' : 'after');
            $score = $timing === 'during' ? 80 : 0;
            $reasons = $timing === 'during' ? ['platba proběhla během cesty'] : [];
            if ($timing === 'before' && $date->diffInDays($start) <= 45 && in_array($transaction->category, ['transport', 'accommodation', 'insurance', 'activities'], true)) {
                $score = 70;
                $reasons[] = 'předem placená cestovní služba';
            }
            if ($timing === 'after' && $end->diffInDays($date) <= 7 && in_array($transaction->category, ['transport', 'accommodation'], true)) {
                $score = 65;
                $reasons[] = 'doplatek krátce po cestě';
            }
            [$activityId, $placeId, $context] = $this->contextMatch($trip, $transaction);
            if ($context) {
                $score += 20;
                $reasons[] = $context;
            }
            if ($timing === 'during' && $transaction->category !== 'other') {
                $score += 5;
            }

            return compact('trip', 'timing', 'score', 'reasons', 'activityId', 'placeId');
        })->filter(fn ($row) => $row['score'] >= 50)->sortByDesc('score')->values();

        $created = 0;
        $confirmedUsed = DB::table('trip_bank_transactions')->where('bank_transaction_id', $transaction->id)->where('status', 'confirmed')->exists();
        foreach ($candidates as $index => $candidate) {
            $existing = DB::table('trip_bank_transactions')->where('trip_id', $candidate['trip']->id)->where('bank_transaction_id', $transaction->id)->first();
            if ($existing && $existing->linked_by) {
                continue;
            }
            $minimumAutoScore = $transaction->trip_action === 'include' ? 50 : 80;
            $eligibleForAutomaticConfirmation = $transaction->direction === 'debit' || $transaction->is_refund;
            $status = $existing?->status === 'confirmed' ? 'confirmed'
                : (! $confirmedUsed && $index === 0 && $candidate['score'] >= $minimumAutoScore
                    && ! $transaction->is_internal_transfer && $eligibleForAutomaticConfirmation ? 'confirmed' : 'suggested');
            $values = ['trip_activity_id' => $candidate['activityId'], 'place_id' => $candidate['placeId'], 'status' => $status,
                'confidence' => min(100, $candidate['score']), 'reason' => implode(' · ', $candidate['reasons']),
                'allocated_amount' => abs((float) $transaction->amount), 'category' => $transaction->category,
                'timing' => $candidate['timing'], 'updated_at' => now()];
            if ($existing) {
                DB::table('trip_bank_transactions')->where('id', $existing->id)->update($values);
            } else {
                $id = DB::table('trip_bank_transactions')->insertGetId($values + ['trip_id' => $candidate['trip']->id,
                    'bank_transaction_id' => $transaction->id, 'created_at' => now()]);
                $existing = DB::table('trip_bank_transactions')->find($id);
                $created++;
            }
            if ($status === 'confirmed') {
                $confirmedUsed = true;
                $link = DB::table('trip_bank_transactions')->where('trip_id', $candidate['trip']->id)->where('bank_transaction_id', $transaction->id)->first();
                $this->syncExpense($link, $transaction, (int) ($transaction->account->connection->connected_by ?: $space->owner_id));
            }
        }

        return $created;
    }

    public function updateLink(object $link, BankTransaction $transaction, array $data, int $userId): object
    {
        $targetStatus = $data['status'] ?? $link->status;
        $targetAmount = array_key_exists('allocated_amount', $data) ? (float) $data['allocated_amount']
            : ($link->allocated_amount !== null ? (float) $link->allocated_amount : abs((float) $transaction->amount));
        if ($targetStatus === 'confirmed') {
            $allocatedElsewhere = (float) DB::table('trip_bank_transactions')->where('bank_transaction_id', $transaction->id)
                ->where('id', '!=', $link->id)->where('status', 'confirmed')->sum('allocated_amount');
            abort_if($allocatedElsewhere + $targetAmount > abs((float) $transaction->amount) + 0.0001, 422,
                'Tato platba je už plně přiřazená jiné cestě. Nejprve upravte rozdělenou částku.');
        }
        $values = array_filter(['status' => $data['status'] ?? null, 'category' => $data['category'] ?? null,
            'allocated_amount' => $data['allocated_amount'] ?? null, 'trip_activity_id' => $data['trip_activity_id'] ?? null,
            'place_id' => $data['place_id'] ?? null, 'note' => $data['note'] ?? null], fn ($value) => $value !== null);
        $values += ['linked_by' => $userId, 'updated_at' => now()];
        DB::table('trip_bank_transactions')->where('id', $link->id)->update($values);
        $link = DB::table('trip_bank_transactions')->find($link->id);
        if ($link->status === 'confirmed') {
            $this->syncExpense($link, $transaction, $userId);
        } elseif ($link->trip_expense_id) {
            DB::table('trip_expenses')->where('id', $link->trip_expense_id)->where('automation_source', 'bank_transaction')->delete();
            DB::table('trip_bank_transactions')->where('id', $link->id)->update(['trip_expense_id' => null]);
            $link->trip_expense_id = null;
        }

        return $link;
    }

    private function syncExpense(object $link, BankTransaction $transaction, int $userId): void
    {
        if ($transaction->direction !== 'debit' || $transaction->is_internal_transfer || $transaction->is_refund) {
            return;
        }
        if ($link->allocated_amount !== null && (float) $link->allocated_amount <= 0) {
            if ($link->trip_expense_id) {
                DB::table('trip_expenses')->where('id', $link->trip_expense_id)->where('automation_source', 'bank_transaction')->delete();
            }
            DB::table('trip_bank_transactions')->where('id', $link->id)->update(['trip_expense_id' => null]);

            return;
        }
        $title = $transaction->merchant_name ?: $transaction->counterparty_name ?: 'Bankovní platba';
        $values = ['title' => $title, 'category' => $link->category ?: $transaction->category,
            'amount' => $link->allocated_amount !== null ? $link->allocated_amount : abs((float) $transaction->amount), 'currency' => $transaction->currency,
            'paid_by' => 'Společný bankovní účet', 'state' => 'actual', 'occurred_at' => $transaction->booked_at,
            'automation_source' => 'bank_transaction', 'automation_key' => $transaction->uuid, 'updated_at' => now()];
        if (Schema::hasColumn('trip_expenses', 'payment_source')) {
            $values['payment_source'] = 'joint';
            $values['paid_by_user_id'] = null;
            $values['split'] = null;
        }
        $expenseId = $link->trip_expense_id;
        if ($expenseId && DB::table('trip_expenses')->where('id', $expenseId)->exists()) {
            DB::table('trip_expenses')->where('id', $expenseId)->update($values);
        } else {
            $expenseId = DB::table('trip_expenses')->insertGetId($values + ['trip_id' => $link->trip_id, 'created_by' => $userId, 'created_at' => now()]);
            DB::table('trip_bank_transactions')->where('id', $link->id)->update(['trip_expense_id' => $expenseId]);
        }
    }

    private function contextMatch(object $trip, BankTransaction $transaction): array
    {
        $haystack = $this->classifier->normalize(implode(' ', array_filter([$transaction->merchant_name, $transaction->counterparty_name, $transaction->description])));
        if ($haystack === '') {
            return [null, null, null];
        }
        $activities = DB::table('trip_activities as activity')->join('trip_days as day', 'day.id', '=', 'activity.trip_day_id')
            ->where('day.trip_id', $trip->id)->get(['activity.id', 'activity.title', 'activity.place_name']);
        foreach ($activities as $activity) {
            foreach ([$activity->place_name, $activity->title] as $label) {
                $needle = $this->classifier->normalize($label);
                if (strlen($needle) >= 4 && str_contains($haystack, $needle)) {
                    return [$activity->id, null, 'shoda s bodem itineráře'];
                }
            }
        }
        $places = DB::table('places')->where('gallery_space_id', $trip->gallery_space_id)->get(['id', 'name']);
        foreach ($places as $place) {
            $needle = $this->classifier->normalize($place->name);
            if (strlen($needle) >= 4 && str_contains($haystack, $needle)) {
                return [null, $place->id, 'shoda s uloženým místem'];
            }
        }
        if (Schema::hasTable('trip_travel_choices')) {
            foreach (DB::table('trip_travel_choices')->where('trip_id', $trip->id)->get(['title', 'provider', 'reference']) as $choice) {
                foreach ([$choice->provider, $choice->reference, $choice->title] as $label) {
                    $needle = $this->classifier->normalize($label);
                    if (strlen($needle) >= 4 && str_contains($haystack, $needle)) {
                        return [null, null, 'shoda s rezervací nebo dopravou'];
                    }
                }
            }
        }

        return [null, null, null];
    }
}
