<?php

namespace App\Services\Banking;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\GallerySpace;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TripFinancialInsightService
{
    public function available(): bool
    {
        return Schema::hasTable('bank_connections') && Schema::hasTable('trip_bank_transactions');
    }

    public function spaceOverview(GallerySpace $space): array
    {
        if (! $this->available()) {
            return ['available' => false, 'connections' => [], 'accounts' => [], 'imports' => [], 'rules' => []];
        }
        $connections = DB::table('bank_connections')->where('gallery_space_id', $space->id)->orderByDesc('id')->get()->map(fn ($row) => [
            'uuid' => $row->uuid, 'provider' => $row->provider, 'institution_name' => $row->institution_name,
            'status' => $row->status, 'sync_enabled' => (bool) $row->sync_enabled, 'consent_expires_at' => $row->consent_expires_at,
            'last_success_at' => $row->last_success_at, 'last_error' => $row->last_error, 'revoked_at' => $row->revoked_at,
        ]);
        $accounts = BankAccount::whereHas('connection', fn ($query) => $query->where('gallery_space_id', $space->id))->with('connection')->get()->map(fn ($account) => [
            'uuid' => $account->uuid, 'name' => $account->name, 'institution' => $account->connection->institution_name,
            'currency' => $account->currency, 'iban_masked' => $account->iban_last4 ? '•••• '.$account->iban_last4 : null,
            'is_joint' => $account->is_joint, 'current_balance' => $account->current_balance !== null ? (float) $account->current_balance : null,
            'available_balance' => $account->available_balance !== null ? (float) $account->available_balance : null,
            'balance_updated_at' => $account->balance_updated_at?->toIso8601String(), 'history_available_from' => $account->history_available_from?->toDateString(),
            'transactions_count' => $account->transactions()->count(),
        ]);
        $imports = DB::table('bank_imports')->where('gallery_space_id', $space->id)->latest()->limit(10)->get()->map(fn ($row) => [
            'uuid' => $row->uuid, 'filename' => $row->original_filename, 'status' => $row->status, 'rows_imported' => $row->rows_imported,
            'rows_duplicate' => $row->rows_duplicate, 'rows_failed' => $row->rows_failed, 'period_from' => $row->period_from,
            'period_to' => $row->period_to, 'created_at' => $row->created_at,
        ]);
        $rules = DB::table('bank_category_rules')->where('gallery_space_id', $space->id)->where('is_enabled', true)
            ->orderByDesc('priority')->get(['uuid', 'field', 'operator', 'pattern', 'category', 'trip_action', 'priority']);

        return ['available' => true, 'connections' => $connections, 'accounts' => $accounts, 'imports' => $imports, 'rules' => $rules,
            'transactions_count' => DB::table('bank_transactions as transaction')->join('bank_accounts as account', 'account.id', '=', 'transaction.bank_account_id')
                ->join('bank_connections as connection', 'connection.id', '=', 'account.bank_connection_id')->where('connection.gallery_space_id', $space->id)->count()];
    }

    public function tripSnapshot(object $trip): array
    {
        if (! $this->available()) {
            return ['available' => false, 'connected' => false];
        }
        $start = Carbon::parse($trip->start_date)->startOfDay();
        $end = Carbon::parse($trip->end_date)->endOfDay();
        $accounts = BankAccount::whereHas('connection', fn ($query) => $query->where('gallery_space_id', $trip->gallery_space_id))
            ->where('is_enabled', true)->with('connection')->get();
        $balances = $accounts->map(function (BankAccount $account) use ($start, $end) {
            $before = $this->balanceAt($account, $start->copy()->subSecond());
            $after = $this->balanceAt($account, $end);

            return ['account_uuid' => $account->uuid, 'name' => $account->name, 'currency' => $account->currency,
                'before' => $before, 'after' => $after, 'change' => $before['amount'] !== null && $after['amount'] !== null ? round($after['amount'] - $before['amount'], 2) : null];
        })->values();
        $rows = DB::table('trip_bank_transactions as link')->join('bank_transactions as transaction', 'transaction.id', '=', 'link.bank_transaction_id')
            ->join('bank_accounts as account', 'account.id', '=', 'transaction.bank_account_id')
            ->leftJoin('trip_activities as activity', 'activity.id', '=', 'link.trip_activity_id')
            ->leftJoin('places as place', 'place.id', '=', 'link.place_id')
            ->where('link.trip_id', $trip->id)->orderBy('transaction.booked_at')
            ->get(['link.id as link_id', 'link.status as link_status', 'link.confidence', 'link.reason', 'link.allocated_amount',
                'link.category as link_category', 'link.timing', 'link.note', 'link.trip_expense_id', 'transaction.id as transaction_id',
                'transaction.uuid', 'transaction.direction', 'transaction.amount', 'transaction.currency', 'transaction.original_amount',
                'transaction.original_currency', 'transaction.fee_amount', 'transaction.booked_at', 'transaction.merchant_name',
                'transaction.counterparty_name', 'transaction.category', 'transaction.is_internal_transfer',
                'transaction.is_refund', 'transaction.is_fee', 'transaction.is_cash_withdrawal', 'account.name as account_name',
                'activity.id as activity_id', 'activity.title as activity_title', 'place.id as place_id', 'place.name as place_name']);
        $decryptedTransactions = BankTransaction::whereIn('id', $rows->pluck('transaction_id'))->get()->keyBy('id');
        $rows->each(function ($row) use ($decryptedTransactions) {
            $transaction = $decryptedTransactions->get($row->transaction_id);
            $row->merchant_name = $transaction?->merchant_name;
            $row->counterparty_name = $transaction?->counterparty_name;
        });
        $confirmed = $rows->where('link_status', 'confirmed');
        $debits = $confirmed->filter(fn ($row) => $row->direction === 'debit' && ! $row->is_internal_transfer && ! $row->is_refund);
        $refunds = $confirmed->where('is_refund', true);
        $spentByCurrency = $debits->groupBy('currency')->map(fn ($group) => round($group->sum(fn ($row) => (float) ($row->allocated_amount ?? abs($row->amount))), 2));
        $refundByCurrency = $refunds->groupBy('currency')->map(fn ($group) => round($group->sum(fn ($row) => abs((float) $row->amount)), 2));
        $categories = $debits->groupBy(fn ($row) => $row->link_category ?: $row->category)->map(fn ($group, $category) => [
            'category' => $category, 'amounts' => $group->groupBy('currency')->map(fn ($items) => round($items->sum(fn ($row) => (float) ($row->allocated_amount ?? abs($row->amount))), 2)), 'count' => $group->count(),
        ])->values();
        $days = $confirmed->groupBy(fn ($row) => Carbon::parse($row->booked_at)->toDateString().'|'.$row->currency)->map(fn ($group) => [
            'date' => Carbon::parse($group->first()->booked_at)->toDateString(), 'spent' => round($group->filter(fn ($row) => $row->direction === 'debit' && ! $row->is_internal_transfer)->sum(fn ($row) => (float) ($row->allocated_amount ?? abs($row->amount))), 2),
            'refunds' => round($group->where('is_refund', true)->sum(fn ($row) => abs((float) $row->amount)), 2), 'currency' => $group->first()->currency,
        ])->values();
        $merchants = $debits->groupBy(fn ($row) => ($row->merchant_name ?: $row->counterparty_name ?: 'Ostatní').'|'.$row->currency)->map(fn ($group) => [
            'name' => $group->first()->merchant_name ?: $group->first()->counterparty_name ?: 'Ostatní', 'amount' => round($group->sum(fn ($row) => (float) ($row->allocated_amount ?? abs($row->amount))), 2),
            'currency' => $group->first()->currency, 'count' => $group->count(),
        ])->sortByDesc('amount')->take(8)->values();

        return ['available' => true, 'connected' => $accounts->isNotEmpty(), 'balances' => $balances,
            'summary' => ['spent_by_currency' => $spentByCurrency, 'refunds_by_currency' => $refundByCurrency,
                'fees_by_currency' => $confirmed->groupBy('currency')->map(fn ($group) => round($group->sum(fn ($row) => abs((float) ($row->fee_amount ?? 0)) + ($row->is_fee ? abs((float) $row->amount) : 0)), 2)),
                'cash_withdrawals' => $confirmed->where('is_cash_withdrawal', true)->count(), 'internal_transfers' => $confirmed->where('is_internal_transfer', true)->count(),
                'suggested_count' => $rows->where('link_status', 'suggested')->count(), 'confirmed_count' => $confirmed->count(),
                'before_count' => $confirmed->where('timing', 'before')->count(), 'during_count' => $confirmed->where('timing', 'during')->count(), 'after_count' => $confirmed->where('timing', 'after')->count()],
            'categories' => $categories, 'days' => $days, 'merchants' => $merchants,
            'transactions' => $rows->map(fn ($row) => ['link_id' => $row->link_id, 'uuid' => $row->uuid, 'status' => $row->link_status,
                'confidence' => round(((int) $row->confidence) / 100, 2), 'reason' => $row->reason, 'timing' => $row->timing, 'direction' => $row->direction,
                'amount' => (float) $row->amount, 'allocated_amount' => $row->allocated_amount !== null ? (float) $row->allocated_amount : null,
                'currency' => $row->currency, 'booked_at' => $row->booked_at, 'merchant' => $row->merchant_name ?: $row->counterparty_name ?: 'Bankovní transakce',
                'category' => $row->link_category ?: $row->category, 'is_internal_transfer' => (bool) $row->is_internal_transfer,
                'is_refund' => (bool) $row->is_refund, 'is_fee' => (bool) $row->is_fee, 'is_cash_withdrawal' => (bool) $row->is_cash_withdrawal,
                'account_name' => $row->account_name, 'activity' => $row->activity_id ? ['id' => $row->activity_id, 'title' => $row->activity_title] : null,
                'place' => $row->place_id ? ['id' => $row->place_id, 'name' => $row->place_name] : null])->values()];
    }

    public function tripPrompt(object $trip): array
    {
        $snapshot = $this->tripSnapshot($trip);
        if (! ($snapshot['available'] ?? false)) {
            return ['available' => false, 'connected' => false];
        }

        return [
            'available' => true,
            'connected' => $snapshot['connected'],
            'balances' => $snapshot['balances'],
            'spent_by_currency' => $snapshot['summary']['spent_by_currency'],
            'refunds_by_currency' => $snapshot['summary']['refunds_by_currency'],
            'suggested_count' => $snapshot['summary']['suggested_count'],
            'confirmed_count' => $snapshot['summary']['confirmed_count'],
        ];
    }

    private function balanceAt(BankAccount $account, Carbon $at): array
    {
        $transaction = DB::table('bank_transactions')->where('bank_account_id', $account->id)->where('status', 'booked')
            ->where('booked_at', '<=', $at)->whereNotNull('balance_after')->orderByDesc('booked_at')->first(['balance_after', 'booked_at']);
        if ($transaction) {
            return ['amount' => (float) $transaction->balance_after, 'method' => 'transaction', 'as_of' => $transaction->booked_at, 'estimated' => false];
        }
        $snapshot = DB::table('bank_balance_snapshots')->where('bank_account_id', $account->id)->where('captured_at', '<=', $at)
            ->whereNotNull('booked_balance')->orderByDesc('captured_at')->first(['booked_balance', 'captured_at']);
        if ($snapshot) {
            return ['amount' => (float) $snapshot->booked_balance, 'method' => 'snapshot', 'as_of' => $snapshot->captured_at, 'estimated' => false];
        }
        if ($account->current_balance !== null && $account->history_available_from && Carbon::parse($account->history_available_from)->lte($at)) {
            $after = DB::table('bank_transactions')->where('bank_account_id', $account->id)->where('status', 'booked')->where('booked_at', '>', $at)->sum('amount');

            return ['amount' => round((float) $account->current_balance - (float) $after, 2), 'method' => 'reconstructed', 'as_of' => $at->toIso8601String(), 'estimated' => true];
        }

        return ['amount' => null, 'method' => 'unavailable', 'as_of' => null, 'estimated' => true];
    }
}
