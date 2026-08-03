<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class GiftBudgetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['gallery_space_id' => 'required|integer', 'year' => 'nullable|integer|between:2000,2100']);
        $spaceId = $this->spaceId($request, (int) $data['gallery_space_id']);
        $year = (int) ($data['year'] ?? now('Europe/Prague')->year);
        abort_unless(Schema::hasTable('gift_budgets'), 503, 'Rozpočty dárků budou dostupné po dokončení aktualizace databáze.');

        $budgets = DB::table('gift_budgets')->where('gallery_space_id', $spaceId)
            ->where('budget_year', $year)
            ->where(fn ($visible) => $visible->where('scope_key', 'shared')->orWhere('owner_user_id', $request->user()->id))
            ->orderBy('scope_key')->orderBy('title')->get();
        $gifts = $this->giftsForYear($spaceId, $year);

        return response()->json([
            'year' => $year,
            'budgets' => $budgets->map(fn (object $budget) => $this->payload($budget, $gifts, $request->user()->id))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['gallery_space_id' => 'required|integer', 'year' => 'required|integer|between:2000,2100', 'scope' => 'required|in:shared,personal', 'title' => 'required|string|max:120', 'occasion' => 'nullable|string|max:80', 'planned_amount' => 'required|numeric|min:0|max:999999999', 'currency' => 'nullable|string|size:3']);
        $spaceId = $this->spaceId($request, (int) $data['gallery_space_id']);
        abort_unless(Schema::hasTable('gift_budgets'), 503, 'Rozpočty dárků budou dostupné po dokončení aktualizace databáze.');
        $user = $request->user(); $scopeKey = $data['scope'] === 'shared' ? 'shared' : 'personal:'.$user->id;
        $now = now();
        $id = DB::table('gift_budgets')->insertGetId([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $spaceId, 'created_by' => $user->id,
            'owner_user_id' => $data['scope'] === 'personal' ? $user->id : null, 'scope_key' => $scopeKey,
            'budget_year' => $data['year'], 'title' => trim($data['title']), 'occasion' => blank($data['occasion'] ?? null) ? null : trim($data['occasion']),
            'planned_amount' => $data['planned_amount'], 'currency' => strtoupper($data['currency'] ?? 'CZK'), 'created_at' => $now, 'updated_at' => $now,
        ]);
        return response()->json(['budget' => $this->payload(DB::table('gift_budgets')->find($id), $this->giftsForYear($spaceId, (int) $data['year']), $user->id)], 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['gallery_space_id' => 'required|integer', 'title' => 'sometimes|string|max:120', 'occasion' => 'nullable|string|max:80', 'planned_amount' => 'sometimes|numeric|min:0|max:999999999', 'currency' => 'sometimes|string|size:3']);
        $spaceId = $this->spaceId($request, (int) $data['gallery_space_id']);
        abort_unless(Schema::hasTable('gift_budgets'), 503, 'Rozpočty dárků budou dostupné po dokončení aktualizace databáze.');
        $budget = DB::table('gift_budgets')->where('uuid', $uuid)->where('gallery_space_id', $spaceId)->firstOrFail();
        abort_unless($budget->scope_key === 'shared' || (int) $budget->owner_user_id === (int) $request->user()->id, 404);
        foreach (['title', 'occasion'] as $field) if (array_key_exists($field, $data)) $data[$field] = blank($data[$field]) ? null : trim($data[$field]);
        if (isset($data['currency'])) $data['currency'] = strtoupper($data['currency']);
        unset($data['gallery_space_id']);
        DB::table('gift_budgets')->where('id', $budget->id)->update($data + ['updated_at' => now()]);
        $updated = DB::table('gift_budgets')->find($budget->id);
        return response()->json(['budget' => $this->payload($updated, $this->giftsForYear($spaceId, (int) $updated->budget_year), $request->user()->id)]);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['gallery_space_id' => 'required|integer']);
        $spaceId = $this->spaceId($request, (int) $data['gallery_space_id']);
        abort_unless(Schema::hasTable('gift_budgets'), 503, 'Rozpočty dárků budou dostupné po dokončení aktualizace databáze.');
        $budget = DB::table('gift_budgets')->where('uuid', $uuid)->where('gallery_space_id', $spaceId)->firstOrFail();
        abort_unless($budget->scope_key === 'shared' || (int) $budget->owner_user_id === (int) $request->user()->id, 404);
        DB::table('gift_budgets')->where('id', $budget->id)->delete();
        return response()->json(['status' => 'deleted']);
    }

    private function spaceId(Request $request, int $spaceId): int
    {
        abort_unless($request->user()->gallerySpaces()->whereKey($spaceId)->exists(), 404);
        return $spaceId;
    }

    private function giftsForYear(int $spaceId, int $year): Collection
    {
        if (!Schema::hasTable('gift_ideas')) return collect();
        return DB::table('gift_ideas')->where('gallery_space_id', $spaceId)->whereNotIn('status', ['archived'])
            ->where(fn ($period) => $period->whereBetween('due_date', ["{$year}-01-01", "{$year}-12-31"])->orWhere(fn ($undated) => $undated->whereNull('due_date')->whereBetween('created_at', ["{$year}-01-01", "{$year}-12-31 23:59:59"])))
            ->get(['occasion', 'budget', 'currency', 'status', 'visibility', 'private_to_user_id']);
    }

    private function payload(object $budget, Collection $gifts, int $userId): array
    {
        $scope = $budget->scope_key === 'shared' ? 'shared' : 'personal';
        $matching = $gifts->filter(function (object $gift) use ($budget, $scope, $userId): bool {
            if (strtoupper($gift->currency ?? 'CZK') !== strtoupper($budget->currency)) return false;
            if (filled($budget->occasion) && strcasecmp((string) ($gift->occasion ?? ''), (string) $budget->occasion) !== 0) return false;
            $private = ($gift->visibility ?? 'shared') === 'private';
            return $scope === 'shared' ? !$private : ($private && (int) $gift->private_to_user_id === $userId);
        });
        $planned = round((float) $matching->sum(fn (object $gift) => (float) ($gift->budget ?? 0)), 2);
        $spent = round((float) $matching->filter(fn (object $gift) => $gift->status === 'purchased')->sum(fn (object $gift) => (float) ($gift->budget ?? 0)), 2);
        $limit = (float) $budget->planned_amount;
        return ['uuid' => $budget->uuid, 'title' => $budget->title, 'occasion' => $budget->occasion, 'scope' => $scope, 'year' => (int) $budget->budget_year, 'planned_amount' => $limit, 'currency' => $budget->currency, 'gift_plan_amount' => $planned, 'spent_amount' => $spent, 'remaining_amount' => round($limit - $spent, 2), 'over_limit' => $spent > $limit];
    }
}