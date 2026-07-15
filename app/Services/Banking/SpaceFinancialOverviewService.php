<?php

namespace App\Services\Banking;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\GallerySpace;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SpaceFinancialOverviewService
{
    public function dashboard(GallerySpace $space, array $filters): array
    {
        if (! $this->available()) {
            return $this->unavailable();
        }

        $from = Carbon::parse($filters['from'] ?? now()->subDays(89)->toDateString())->startOfDay();
        $to = Carbon::parse($filters['to'] ?? now()->toDateString())->endOfDay();
        abort_if($from->gt($to), 422, 'Začátek období musí být před jeho koncem.');
        abort_if($from->diffInDays($to) > 3660, 422, 'Jedno období může mít nejvýše deset let.');

        $accounts = BankAccount::query()
            ->whereHas('connection', fn ($query) => $query->where('gallery_space_id', $space->id))
            ->with('connection')->orderBy('currency')->orderBy('name')->get();
        $account = ! empty($filters['account_uuid']) ? $accounts->firstWhere('uuid', $filters['account_uuid']) : null;
        abort_if(! empty($filters['account_uuid']) && ! $account, 404, 'Bankovní účet nebyl nalezen.');

        $query = BankTransaction::query()->with('account.connection')
            ->whereHas('account.connection', fn ($builder) => $builder->where('gallery_space_id', $space->id))
            ->whereBetween('booked_at', [$from, $to]);
        if ($account) {
            $query->where('bank_account_id', $account->id);
        }
        if (! empty($filters['category']) && $filters['category'] !== 'all') {
            $query->where('category', $filters['category']);
        }
        if (! empty($filters['direction']) && $filters['direction'] !== 'all') {
            $query->where('direction', $filters['direction']);
        }
        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        $rows = $query->orderByDesc('booked_at')->orderByDesc('id')->get();
        if ($search = Str::lower(trim((string) ($filters['query'] ?? '')))) {
            $rows = $rows->filter(function (BankTransaction $transaction) use ($search) {
                $haystack = Str::lower(implode(' ', array_filter([
                    $transaction->merchant_name, $transaction->counterparty_name, $transaction->description,
                    $transaction->category, $transaction->transaction_type,
                ])));

                return str_contains($haystack, $search);
            })->values();
        }

        $transactionIds = $rows->pluck('id');
        $links = $transactionIds->isEmpty() ? collect() : DB::table('trip_bank_transactions as link')
            ->join('trips as trip', 'trip.id', '=', 'link.trip_id')
            ->whereIn('link.bank_transaction_id', $transactionIds)
            ->get(['link.id', 'link.bank_transaction_id', 'link.trip_id', 'link.status', 'link.confidence', 'link.allocated_amount',
                'link.category', 'link.timing', 'trip.name as trip_name', 'trip.start_date', 'trip.end_date', 'trip.currency as trip_currency']);
        $linksByTransaction = $links->groupBy('bank_transaction_id');
        $review = $filters['review'] ?? 'all';
        if ($review !== 'all') {
            $linkedIds = $links->where('status', 'confirmed')->pluck('bank_transaction_id')->unique();
            $suggestedIds = $links->where('status', 'suggested')->pluck('bank_transaction_id')->unique();
            $rows = $rows->filter(fn (BankTransaction $transaction) => match ($review) {
                'linked' => $linkedIds->contains($transaction->id),
                'suggested' => $suggestedIds->contains($transaction->id),
                'unlinked' => ! $linksByTransaction->has($transaction->id) && $transaction->trip_action !== 'exclude',
                default => true,
            })->values();
            $visibleIds = $rows->pluck('id');
            $links = $links->whereIn('bank_transaction_id', $visibleIds)->values();
            $linksByTransaction = $links->groupBy('bank_transaction_id');
        }

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(10, (int) ($filters['per_page'] ?? 40)));
        $total = $rows->count();
        $pageRows = $rows->slice(($page - 1) * $perPage, $perPage)->values();

        return [
            'available' => true,
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $from->diffInDays($to) + 1],
            'accounts' => $accounts->map(fn (BankAccount $item) => $this->account($item))->values(),
            'summary' => $this->summary($rows, $links),
            'categories' => $this->categories($rows),
            'cashflow' => $this->cashflow($rows),
            'daily_cashflow' => $this->dailyCashflow($rows),
            'top_merchants' => $this->merchants($rows),
            'balance_series' => $this->balanceSeries($accounts, $from, $to, $account?->id),
            'trips' => $this->tripSummaries($rows, $links),
            'events' => $this->linkedEvents($space, $links, $from, $to),
            'trip_options' => DB::table('trips')->where('gallery_space_id', $space->id)->orderByDesc('start_date')->limit(100)
                ->get(['id', 'name', 'start_date', 'end_date', 'currency']),
            'transactions' => [
                'data' => $pageRows->map(fn (BankTransaction $transaction) => $this->transaction($transaction, $linksByTransaction->get($transaction->id, collect())))->values(),
                'meta' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $total,
                    'last_page' => max(1, (int) ceil($total / $perPage))],
            ],
        ];
    }

    private function available(): bool
    {
        return Schema::hasTable('bank_connections') && Schema::hasTable('bank_accounts')
            && Schema::hasTable('bank_transactions') && Schema::hasTable('trip_bank_transactions');
    }

    private function unavailable(): array
    {
        return ['available' => false, 'period' => null, 'accounts' => [], 'summary' => ['currencies' => [], 'transaction_count' => 0,
            'linked_count' => 0, 'suggested_count' => 0, 'unlinked_count' => 0], 'categories' => [], 'cashflow' => [], 'daily_cashflow' => [],
            'top_merchants' => [], 'balance_series' => [], 'trips' => [], 'events' => [], 'trip_options' => [],
            'transactions' => ['data' => [], 'meta' => ['current_page' => 1, 'per_page' => 40, 'total' => 0, 'last_page' => 1]]];
    }

    private function account(BankAccount $account): array
    {
        return ['uuid' => $account->uuid, 'name' => $account->name ?: 'Revolut účet', 'institution' => $account->connection->institution_name,
            'currency' => $account->currency, 'iban_masked' => $account->iban_last4 ? '•••• '.$account->iban_last4 : null,
            'is_joint' => (bool) $account->is_joint, 'is_enabled' => (bool) $account->is_enabled,
            'current_balance' => $account->current_balance !== null ? (float) $account->current_balance : null,
            'available_balance' => $account->available_balance !== null ? (float) $account->available_balance : null,
            'balance_updated_at' => $account->balance_updated_at?->toIso8601String(),
            'history_available_from' => $account->history_available_from?->toDateString(),
            'transactions_count' => $account->transactions()->count()];
    }

    private function summary(Collection $rows, Collection $links): array
    {
        $currencies = $rows->groupBy('currency')->map(function (Collection $items, string $currency) {
            $ordinaryExpenses = $items->filter(fn (BankTransaction $item) => $item->direction === 'debit' && ! $item->is_internal_transfer && ! $item->is_refund);
            $ordinaryIncome = $items->filter(fn (BankTransaction $item) => $item->direction === 'credit' && ! $item->is_internal_transfer && ! $item->is_refund);

            return ['currency' => $currency,
                'expenses' => round($ordinaryExpenses->sum(fn ($item) => abs((float) $item->amount)), 2),
                'income' => round($ordinaryIncome->sum(fn ($item) => abs((float) $item->amount)), 2),
                'refunds' => round($items->where('is_refund', true)->sum(fn ($item) => abs((float) $item->amount)), 2),
                'fees' => round($items->sum(fn ($item) => abs((float) ($item->fee_amount ?? 0)) + ($item->is_fee ? abs((float) $item->amount) : 0)), 2),
                'net_change' => round($items->where('is_internal_transfer', false)->sum(fn ($item) => (float) $item->amount), 2),
                'transactions' => $items->count()];
        })->values();
        $linkedIds = $links->pluck('bank_transaction_id')->unique();

        return ['currencies' => $currencies, 'transaction_count' => $rows->count(),
            'linked_count' => $links->where('status', 'confirmed')->pluck('bank_transaction_id')->unique()->count(),
            'suggested_count' => $links->where('status', 'suggested')->count(),
            'unlinked_count' => $rows->filter(fn (BankTransaction $item) => ! $linkedIds->contains($item->id) && $item->trip_action !== 'exclude')->count(),
            'internal_transfers' => $rows->where('is_internal_transfer', true)->count(),
            'cash_withdrawals' => $rows->where('is_cash_withdrawal', true)->count()];
    }

    private function categories(Collection $rows): Collection
    {
        return $rows->filter(fn (BankTransaction $item) => $item->direction === 'debit' && ! $item->is_internal_transfer && ! $item->is_refund)
            ->groupBy(fn ($item) => $item->category.'|'.$item->currency)->map(function (Collection $items) {
                return ['category' => $items->first()->category, 'currency' => $items->first()->currency,
                    'amount' => round($items->sum(fn ($item) => abs((float) $item->amount)), 2), 'count' => $items->count()];
            })->sortByDesc('amount')->values();
    }

    private function cashflow(Collection $rows): Collection
    {
        return $rows->groupBy(fn (BankTransaction $item) => $item->booked_at->format('Y-m').'|'.$item->currency)
            ->map(function (Collection $items) {
                $expenses = $items->filter(fn ($item) => $item->direction === 'debit' && ! $item->is_internal_transfer && ! $item->is_refund)
                    ->sum(fn ($item) => abs((float) $item->amount));
                $income = $items->filter(fn ($item) => $item->direction === 'credit' && ! $item->is_internal_transfer && ! $item->is_refund)
                    ->sum(fn ($item) => abs((float) $item->amount));

                return ['month' => $items->first()->booked_at->format('Y-m'), 'currency' => $items->first()->currency,
                    'income' => round($income, 2), 'expenses' => round($expenses, 2),
                    'refunds' => round($items->where('is_refund', true)->sum(fn ($item) => abs((float) $item->amount)), 2),
                    'net' => round($income - $expenses, 2)];
            })->sortBy('month')->values();
    }

    private function dailyCashflow(Collection $rows): Collection
    {
        $running = [];

        return $rows->groupBy(fn (BankTransaction $item) => $item->booked_at->format('Y-m-d').'|'.$item->currency)
            ->map(function (Collection $items) {
                $ordinary = $items->where('is_internal_transfer', false);
                $expenses = $ordinary->filter(fn ($item) => $item->direction === 'debit' && ! $item->is_refund)
                    ->sum(fn ($item) => abs((float) $item->amount));
                $income = $ordinary->filter(fn ($item) => $item->direction === 'credit' && ! $item->is_refund)
                    ->sum(fn ($item) => abs((float) $item->amount));

                return ['date' => $items->first()->booked_at->format('Y-m-d'), 'currency' => $items->first()->currency,
                    'income' => round($income, 2), 'expenses' => round($expenses, 2), 'net' => round($income - $expenses, 2),
                    'transactions' => $items->count()];
            })->sortBy('date')->values()->map(function (array $item) use (&$running) {
                $running[$item['currency']] = round(($running[$item['currency']] ?? 0) + $item['net'], 2);
                $item['running_net'] = $running[$item['currency']];

                return $item;
            });
    }

    private function merchants(Collection $rows): Collection
    {
        return $rows->filter(fn (BankTransaction $item) => $item->direction === 'debit' && ! $item->is_internal_transfer && ! $item->is_refund)
            ->groupBy(fn (BankTransaction $item) => ($item->merchant_name ?: $item->counterparty_name ?: $item->description ?: 'Ostatní').'|'.$item->currency)
            ->map(function (Collection $items) {
                $first = $items->first();

                return ['name' => $first->merchant_name ?: $first->counterparty_name ?: $first->description ?: 'Ostatní',
                    'currency' => $first->currency, 'amount' => round($items->sum(fn ($item) => abs((float) $item->amount)), 2),
                    'count' => $items->count()];
            })->sortByDesc('amount')->take(12)->values();
    }

    private function balanceSeries(Collection $accounts, Carbon $from, Carbon $to, ?int $accountId): Collection
    {
        return $accounts->when($accountId, fn (Collection $items) => $items->where('id', $accountId))->map(function (BankAccount $account) use ($from, $to) {
            $prior = BankTransaction::where('bank_account_id', $account->id)->where('booked_at', '<', $from)
                ->whereNotNull('balance_after')->latest('booked_at')->first();
            $transactions = BankTransaction::where('bank_account_id', $account->id)->whereBetween('booked_at', [$from, $to])
                ->whereNotNull('balance_after')->orderBy('booked_at')->get()->map(fn ($item) => ['at' => $item->booked_at, 'amount' => (float) $item->balance_after, 'source' => 'transaction']);
            $snapshots = DB::table('bank_balance_snapshots')->where('bank_account_id', $account->id)->whereBetween('captured_at', [$from, $to])
                ->whereNotNull('booked_balance')->orderBy('captured_at')->get()->map(fn ($item) => ['at' => Carbon::parse($item->captured_at), 'amount' => (float) $item->booked_balance, 'source' => $item->source]);
            $points = collect($prior ? [['at' => $from->copy(), 'amount' => (float) $prior->balance_after, 'source' => 'opening']] : [])
                ->concat($transactions)->concat($snapshots)->sortBy('at')->groupBy(fn ($item) => $item['at']->toDateString())
                ->map(fn ($items) => ['date' => $items->last()['at']->toDateString(), 'amount' => $items->last()['amount'], 'source' => $items->last()['source']])->values();
            if ($points->isEmpty() && $account->current_balance !== null) {
                $points->push(['date' => ($account->balance_updated_at ?: now())->toDateString(), 'amount' => (float) $account->current_balance, 'source' => 'current']);
            }

            return ['account_uuid' => $account->uuid, 'name' => $account->name ?: 'Revolut účet', 'currency' => $account->currency, 'points' => $points];
        })->values();
    }

    private function tripSummaries(Collection $rows, Collection $links): Collection
    {
        $transactions = $rows->keyBy('id');

        return $links->groupBy('trip_id')->map(function (Collection $tripLinks) use ($transactions) {
            $first = $tripLinks->first();
            $confirmed = $tripLinks->where('status', 'confirmed');
            $amounts = $confirmed->groupBy(fn ($link) => $transactions->get($link->bank_transaction_id)?->currency ?: $first->trip_currency)
                ->map(fn ($items) => round($items->sum(function ($link) use ($transactions) {
                    $transaction = $transactions->get($link->bank_transaction_id);
                    if (! $transaction || $transaction->direction !== 'debit' || $transaction->is_internal_transfer || $transaction->is_refund) {
                        return 0;
                    }

                    return (float) ($link->allocated_amount ?? abs((float) $transaction->amount));
                }), 2));

            return ['id' => $first->trip_id, 'name' => $first->trip_name, 'start_date' => $first->start_date, 'end_date' => $first->end_date,
                'spent_by_currency' => $amounts, 'confirmed_count' => $confirmed->count(), 'suggested_count' => $tripLinks->where('status', 'suggested')->count()];
        })->sortByDesc('start_date')->values();
    }

    private function linkedEvents(GallerySpace $space, Collection $links, Carbon $from, Carbon $to): Collection
    {
        if (! Schema::hasTable('calendar_events') || ! Schema::hasColumn('calendar_events', 'trip_id')) {
            return collect();
        }
        $tripIds = $links->pluck('trip_id')->filter()->unique()->values();
        if ($tripIds->isEmpty()) {
            return collect();
        }
        $hasSourceTrip = Schema::hasColumn('calendar_events', 'source_trip_id');
        $tripNames = DB::table('trips')->whereIn('id', $tripIds)->pluck('name', 'id');
        $events = DB::table('calendar_events')->where('gallery_space_id', $space->id)
            ->whereBetween('starts_at', [$from, $to])
            ->where(function ($query) use ($tripIds, $hasSourceTrip) {
                $query->whereIn('trip_id', $tripIds);
                if ($hasSourceTrip) {
                    $query->orWhereIn('source_trip_id', $tripIds);
                }
            })->orderByDesc('starts_at')->limit(12)
            ->get($hasSourceTrip
                ? ['uuid', 'title', 'starts_at', 'type', 'place_name', 'trip_id', 'source_trip_id']
                : ['uuid', 'title', 'starts_at', 'type', 'place_name', 'trip_id']);

        return $events->map(function ($event) use ($tripNames, $hasSourceTrip) {
            $tripId = (int) ($event->trip_id ?: ($hasSourceTrip ? $event->source_trip_id : null));

            return ['uuid' => $event->uuid, 'title' => $event->title, 'starts_at' => Carbon::parse($event->starts_at)->toIso8601String(),
                'type' => $event->type, 'place_name' => $event->place_name, 'trip_id' => $tripId,
                'trip_name' => $tripNames[$tripId] ?? 'Propojená cesta'];
        })->values();
    }

    private function transaction(BankTransaction $transaction, Collection $links): array
    {
        return ['uuid' => $transaction->uuid, 'booked_at' => $transaction->booked_at?->toIso8601String(),
            'value_date' => $transaction->value_date?->toDateString(), 'status' => $transaction->status, 'direction' => $transaction->direction,
            'amount' => (float) $transaction->amount, 'currency' => $transaction->currency,
            'original_amount' => $transaction->original_amount !== null ? (float) $transaction->original_amount : null,
            'original_currency' => $transaction->original_currency, 'fee_amount' => $transaction->fee_amount !== null ? (float) $transaction->fee_amount : null,
            'balance_after' => $transaction->balance_after !== null ? (float) $transaction->balance_after : null,
            'merchant' => $transaction->merchant_name ?: $transaction->counterparty_name ?: $transaction->description ?: 'Bankovní transakce',
            'counterparty' => $transaction->counterparty_name, 'description' => $transaction->description,
            'category' => $transaction->category, 'trip_action' => $transaction->trip_action,
            'category_is_manual' => (bool) $transaction->category_is_manual,
            'is_internal_transfer' => (bool) $transaction->is_internal_transfer, 'is_refund' => (bool) $transaction->is_refund,
            'is_fee' => (bool) $transaction->is_fee, 'is_cash_withdrawal' => (bool) $transaction->is_cash_withdrawal,
            'account' => ['uuid' => $transaction->account->uuid, 'name' => $transaction->account->name ?: 'Revolut účet', 'currency' => $transaction->account->currency],
            'trip_links' => $links->map(fn ($link) => ['id' => $link->id, 'trip_id' => $link->trip_id, 'trip_name' => $link->trip_name,
                'status' => $link->status, 'confidence' => round(((int) $link->confidence) / 100, 2),
                'allocated_amount' => $link->allocated_amount !== null ? (float) $link->allocated_amount : null,
                'category' => $link->category, 'timing' => $link->timing])->values()];
    }
}
