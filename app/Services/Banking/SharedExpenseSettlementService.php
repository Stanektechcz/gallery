<?php

namespace App\Services\Banking;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SharedExpenseSettlementService
{
    public function members(int $spaceId): Collection
    {
        return DB::table('gallery_space_user as membership')->join('users', 'users.id', '=', 'membership.user_id')
            ->where('membership.gallery_space_id', $spaceId)->orderBy('membership.joined_at')->orderBy('users.id')
            ->get(['users.id', 'users.name'])->map(fn ($member) => ['id' => (int) $member->id, 'name' => $member->name])->values();
    }

    public function shares(float $amount, array $memberIds, string $mode, array $submitted = []): array
    {
        if ($mode === 'gift') return [];
        if ($mode !== 'custom') return $this->equal($amount, $memberIds);
        $shares = collect($submitted)->filter(fn ($share) => is_array($share) && in_array((int) ($share['user_id'] ?? 0), $memberIds, true) && is_numeric($share['amount'] ?? null) && (float) $share['amount'] >= 0)
            ->map(fn ($share) => ['user_id' => (int) $share['user_id'], 'amount' => round((float) $share['amount'], 2)])->values()->all();
        abort_if(count(array_unique(array_column($shares, 'user_id'))) !== count($shares), 422, 'Podíl každého partnera může být uveden jen jednou.');
        abort_if(abs(array_sum(array_column($shares, 'amount')) - $amount) > 0.009, 422, 'Součet vlastních podílů musí přesně odpovídat částce výdaje.');
        return $shares;
    }

    public function snapshot(int $spaceId, ?string $from = null, ?string $to = null): array
    {
        $members = $this->members($spaceId);
        $memberIds = $members->pluck('id')->all();
        $expenses = DB::table('shared_expenses')->where('gallery_space_id', $spaceId)
            ->when($from && $to, fn ($query) => $query->whereBetween('occurred_at', [$from, $to]))->get();
        $currencies = $expenses->pluck('currency')->filter()->map(fn ($value) => strtoupper((string) $value))->unique()->values();
        return $currencies->map(function (string $currency) use ($expenses, $members, $memberIds) {
            $balances = $members->mapWithKeys(fn ($member) => [$member['id'] => ['user_id' => $member['id'], 'name' => $member['name'], 'paid' => 0.0, 'owed' => 0.0, 'balance' => 0.0]])->all();
            foreach ($expenses->where('currency', $currency) as $expense) {
                $payer = (int) ($expense->paid_by_user_id ?? 0);
                if (! isset($balances[$payer])) continue;
                $balances[$payer]['paid'] += (float) $expense->amount;
                $shares = json_decode($expense->split ?? '[]', true);
                $shares = is_array($shares) ? $shares : [];
                if (($expense->split_mode ?? 'equal') !== 'gift' && abs(array_sum(array_map(fn ($share) => (float) ($share['amount'] ?? 0), $shares)) - (float) $expense->amount) > 0.009) $shares = $this->equal((float) $expense->amount, $memberIds);
                foreach ($shares as $share) if (isset($balances[(int) ($share['user_id'] ?? 0)])) $balances[(int) $share['user_id']]['owed'] += (float) $share['amount'];
            }
            foreach ($balances as &$balance) { $balance['paid'] = round($balance['paid'],2); $balance['owed'] = round($balance['owed'],2); $balance['balance'] = round($balance['paid'] - $balance['owed'],2); } unset($balance);
            return ['currency' => $currency, 'members' => array_values($balances), 'proposals' => $this->proposals($balances, $currency)];
        })->values()->all();
    }

    private function equal(float $amount, array $memberIds): array
    {
        if (! $memberIds) return [];
        $cents=(int) round($amount*100);$base=intdiv($cents,count($memberIds));$extra=$cents-$base*count($memberIds);
        return array_map(fn ($id,$index) => ['user_id'=>(int)$id,'amount'=>($base+($index<$extra?1:0))/100], array_values($memberIds), array_keys(array_values($memberIds)));
    }

    private function proposals(array $balances, string $currency): array
    {
        $creditors=array_values(array_filter($balances,fn($row)=>$row['balance']>0.004));$debtors=array_values(array_filter($balances,fn($row)=>$row['balance']<-0.004));$result=[];
        foreach($debtors as &$debtor) foreach($creditors as &$creditor) { if($debtor['balance']>=-0.004||$creditor['balance']<=0.004) continue; $amount=round(min(-$debtor['balance'],$creditor['balance']),2);$result[]=['from_user_id'=>$debtor['user_id'],'from_name'=>$debtor['name'],'to_user_id'=>$creditor['user_id'],'to_name'=>$creditor['name'],'amount'=>$amount,'currency'=>$currency];$debtor['balance']+=$amount;$creditor['balance']-=$amount; } unset($debtor,$creditor);
        return $result;
    }
}