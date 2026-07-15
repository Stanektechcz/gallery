<?php

namespace App\Services\Planning;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TripPartnerFinanceService
{
    public function snapshot(object $trip): array
    {
        $members = DB::table('gallery_space_user as membership')
            ->join('users', 'users.id', '=', 'membership.user_id')
            ->where('membership.gallery_space_id', $trip->gallery_space_id)
            ->orderBy('membership.joined_at')->orderBy('users.id')
            ->get(['users.id', 'users.name']);
        $memberIds = $members->pluck('id')->map(fn ($id) => (int) $id)->all();
        $memberNames = $members->mapWithKeys(fn ($member) => [(int) $member->id => $member->name]);
        $hasPaymentSource = Schema::hasColumn('trip_expenses', 'payment_source');
        $expenses = DB::table('trip_expenses')->where('trip_id', $trip->id)->where('state', 'actual')->get();
        $settlements = DB::table('trip_settlements')->where('trip_id', $trip->id)->orderByDesc('id')->get();
        $currencies = $expenses->pluck('currency')->merge($settlements->pluck('currency'))
            ->push(strtoupper((string) ($trip->currency ?: 'CZK')))->filter()->map(fn ($currency) => strtoupper((string) $currency))->unique()->values();

        $snapshots = $currencies->map(function (string $currency) use ($expenses, $settlements, $members, $memberIds, $memberNames, $hasPaymentSource) {
            $balances = $members->mapWithKeys(fn ($member) => [(int) $member->id => [
                'user_id' => (int) $member->id, 'name' => $member->name, 'paid' => 0.0, 'owed' => 0.0, 'balance' => 0.0,
            ]])->all();
            $unassigned = [];
            $jointPaid = 0.0;

            foreach ($expenses->where('currency', $currency) as $expense) {
                $source = $hasPaymentSource ? ($expense->payment_source ?: 'personal') : 'personal';
                if ($source === 'joint') {
                    $jointPaid += (float) $expense->amount;
                    continue;
                }
                $payerId = (int) ($expense->paid_by_user_id ?? 0);
                if (! $payerId || ! array_key_exists($payerId, $balances)) {
                    $unassigned[] = (int) $expense->id;
                    continue;
                }
                $balances[$payerId]['paid'] += (float) $expense->amount;
                $shares = $this->validShares($expense->split, $memberIds);
                if ($shares === [] || abs(array_sum(array_column($shares, 'amount')) - (float) $expense->amount) >= 0.01) {
                    $shares = $this->equalShares((float) $expense->amount, $memberIds);
                }
                foreach ($shares as $share) {
                    $balances[$share['user_id']]['owed'] += $share['amount'];
                }
            }

            foreach ($settlements->where('currency', $currency)->where('status', 'settled') as $settlement) {
                $from = (int) $settlement->from_user_id;
                $to = (int) $settlement->to_user_id;
                if (isset($balances[$from], $balances[$to])) {
                    $balances[$from]['balance'] += (float) $settlement->amount;
                    $balances[$to]['balance'] -= (float) $settlement->amount;
                }
            }
            foreach ($balances as &$balance) {
                $balance['paid'] = round($balance['paid'], 2);
                $balance['owed'] = round($balance['owed'], 2);
                $balance['balance'] = round($balance['balance'] + $balance['paid'] - $balance['owed'], 2);
            }
            unset($balance);

            $proposals = $this->proposals(collect($balances), $currency);
            $pending = $settlements->where('currency', $currency)->where('status', 'suggested');
            $proposals = array_map(function (array $proposal) use ($pending) {
                $saved = $pending->first(fn ($settlement) => (int) $settlement->from_user_id === $proposal['from_user_id']
                    && (int) $settlement->to_user_id === $proposal['to_user_id']
                    && abs((float) $settlement->amount - $proposal['amount']) < 0.01);
                return $proposal + ['settlement_id' => $saved?->id, 'status' => $saved ? 'suggested' : 'calculated'];
            }, $proposals);

            return [
                'currency' => $currency,
                'members' => array_values($balances),
                'proposals' => $proposals,
                'joint_paid' => round($jointPaid, 2),
                'unassigned_expense_ids' => $unassigned,
                'settled_total' => round((float) $settlements->where('currency', $currency)->where('status', 'settled')->sum('amount'), 2),
            ];
        })->values();

        $baseCurrency = strtoupper((string) ($trip->currency ?: 'CZK'));
        $base = $snapshots->firstWhere('currency', $baseCurrency) ?? $snapshots->first() ?? [
            'members' => [], 'proposals' => [], 'unassigned_expense_ids' => [], 'joint_paid' => 0.0,
        ];
        $settlementRows = $settlements->map(fn ($settlement) => [
            'id' => (int) $settlement->id,
            'from_user_id' => (int) $settlement->from_user_id,
            'from_name' => $memberNames[(int) $settlement->from_user_id] ?? 'Neznámý člen',
            'to_user_id' => (int) $settlement->to_user_id,
            'to_name' => $memberNames[(int) $settlement->to_user_id] ?? 'Neznámý člen',
            'amount' => (float) $settlement->amount,
            'currency' => $settlement->currency,
            'status' => $settlement->status,
            'settled_at' => $settlement->settled_at,
            'note' => $settlement->note ?? null,
        ])->values();

        return [
            'members' => $base['members'],
            'proposals' => $base['proposals'],
            'unassigned_expense_ids' => $base['unassigned_expense_ids'],
            'joint_paid' => $base['joint_paid'],
            'currencies' => $snapshots->all(),
            'settlements' => $settlementRows,
        ];
    }

    public function equalShares(float $amount, array $memberIds): array
    {
        if ($memberIds === []) {
            return [];
        }
        $cents = (int) round($amount * 100);
        $base = intdiv($cents, count($memberIds));
        $remainder = $cents - ($base * count($memberIds));

        return array_map(function (int $userId, int $index) use ($base, $remainder) {
            return ['user_id' => $userId, 'amount' => ($base + ($index < $remainder ? 1 : 0)) / 100];
        }, array_values($memberIds), array_keys(array_values($memberIds)));
    }

    private function validShares(?string $encoded, array $memberIds): array
    {
        $shares = json_decode($encoded ?: '[]', true);
        if (! is_array($shares)) {
            return [];
        }

        return collect($shares)->filter(fn ($share) => is_array($share) && in_array((int) ($share['user_id'] ?? 0), $memberIds, true)
            && is_numeric($share['amount'] ?? null) && (float) $share['amount'] >= 0)
            ->map(fn ($share) => ['user_id' => (int) $share['user_id'], 'amount' => round((float) $share['amount'], 2)])->values()->all();
    }

    private function proposals(Collection $balances, string $currency): array
    {
        $creditors = $balances->filter(fn ($row) => $row['balance'] > 0.004)->values()->all();
        $debtors = $balances->filter(fn ($row) => $row['balance'] < -0.004)->values()->all();
        $proposals = [];
        foreach ($debtors as &$debtor) {
            foreach ($creditors as &$creditor) {
                if ($debtor['balance'] >= -0.004 || $creditor['balance'] <= 0.004) {
                    continue;
                }
                $amount = round(min(-$debtor['balance'], $creditor['balance']), 2);
                $proposals[] = [
                    'from_user_id' => $debtor['user_id'], 'to_user_id' => $creditor['user_id'],
                    'amount' => $amount, 'currency' => $currency,
                ];
                $debtor['balance'] += $amount;
                $creditor['balance'] -= $amount;
            }
        }
        unset($debtor, $creditor);

        return $proposals;
    }
}
