<?php

namespace App\Services\Obsah;

use App\Models\GallerySpace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pravidla, vzpomínky a jejich historie.
 *
 * Obrazovka „Automatizace a pravidla" ukazovala historii běhů, kterou nikdo
 * nezapisoval — a selhání se nedozvěděl vůbec nikdo. Motor teď každý běh
 * zaznamená (`automation_runs`) a tenhle poskytovatel ho kreslí.
 */
class Pravidla implements PoskytovatelObsahu
{
    private const MESICE = [1 => 'ledna', 'února', 'března', 'dubna', 'května', 'června',
        'července', 'srpna', 'září', 'října', 'listopadu', 'prosince'];

    public function skupina(): string
    {
        return 'pravidla';
    }

    public function uplne(): array
    {
        return [];
    }

    public function kolekce(GallerySpace $prostor): array
    {
        return array_filter([
            'RULEDEF' => $this->pravidla($prostor),
            'RULOG' => $this->historie($prostor),
            'MEMS' => $this->vzpominky($prostor),
        ], fn ($v) => $v !== null && $v !== []);
    }

    /**
     * Pravidla: `{ id, name, trig, targ, act, aarg, on, who, runs, last }`.
     *
     * @return list<array<string, mixed>>
     */
    private function pravidla(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('automation_rules')) {
            return [];
        }

        $jmena = $prostor->members()->pluck('users.name', 'users.id')->all();

        return DB::table('automation_rules')
            ->where('gallery_space_id', $prostor->id)
            ->orderBy('id')
            ->get()
            ->map(function (object $p) use ($jmena) {
                $nastaveni = json_decode((string) ($p->action_config ?? '{}'), true) ?: [];
                $podminky = json_decode((string) ($p->conditions ?? '[]'), true) ?: [];

                return [
                    'id' => $p->uuid,
                    'name' => $p->name,
                    'trig' => $this->spoustec((string) $p->trigger),
                    'targ' => (string) ($podminky[0]['value'] ?? ''),
                    'act' => $this->akce((string) $p->action),
                    'aarg' => (string) ($nastaveni['title'] ?? ''),
                    'on' => (bool) $p->is_enabled,
                    'who' => $p->created_by ? ($jmena[$p->created_by] ?? 'oba') : 'oba',
                    'runs' => (int) $p->run_count,
                    'last' => $p->last_run_at ? $this->kdy(CarbonImmutable::parse($p->last_run_at)) : 'zatím nikdy',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Historie běhů: `{ id, rule, time, text, ok }`.
     *
     * Selhání se posílá stejně jako úspěch — pravidlo, které tři týdny padá,
     * nemá vypadat jako pravidlo, na které nic nesedlo.
     *
     * @return list<array<string, mixed>>
     */
    private function historie(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('automation_runs')) {
            return [];
        }

        return DB::table('automation_runs as b')
            ->join('automation_rules as p', 'p.id', '=', 'b.automation_rule_id')
            ->where('b.gallery_space_id', $prostor->id)
            ->orderByDesc('b.created_at')
            ->limit(40)
            ->get(['b.id', 'b.succeeded', 'b.message', 'b.created_at', 'p.uuid AS pravidlo'])
            ->map(fn (object $b) => [
                'id' => 'run-'.$b->id,
                'rule' => $b->pravidlo,
                'time' => $this->kdy(CarbonImmutable::parse($b->created_at)),
                'text' => $b->message,
                'ok' => (bool) $b->succeeded,
            ])
            ->values()
            ->all();
    }

    /**
     * Vzpomínky: `[id, druh, před kolika lety, název, datum, místo, fotek, text, barva]`.
     *
     * @return list<array<int, mixed>>
     */
    private function vzpominky(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('generated_memories')) {
            return [];
        }

        return DB::table('generated_memories')
            ->where('gallery_space_id', $prostor->id)
            ->orderByDesc('occurs_on')
            ->limit(40)
            ->get()
            ->map(function (object $v) {
                $kdy = CarbonImmutable::parse($v->occurs_on);
                $fotek = count(json_decode((string) ($v->media_ids ?? '[]'), true) ?: []);

                return [
                    $v->uuid,
                    (string) $v->kind,
                    (int) ($v->years_ago ?? CarbonImmutable::now()->year - $kdy->year),
                    $v->title,
                    $this->denCesky($kdy),
                    (string) ($v->subtitle ?? ''),
                    $fotek,
                    (string) ($v->subtitle ?? ''),
                    (int) $v->id,
                ];
            })
            ->values()
            ->all();
    }

    // ——— překlady ———

    /** Spouštěč motoru → druh, který prototyp umí nakreslit. */
    private function spoustec(string $spoustec): string
    {
        return match ($spoustec) {
            'media.uploaded' => 'tag',
            'todo.completed' => 'task',
            'event.created' => 'anniv',
            default => 'week',
        };
    }

    private function akce(string $akce): string
    {
        return match ($akce) {
            'todo.create' => 'task',
            'journal.entry' => 'diary',
            default => 'notify',
        };
    }

    private function kdy(CarbonImmutable $kdy): string
    {
        $dni = (int) $kdy->startOfDay()->diffInDays(CarbonImmutable::now()->startOfDay());

        return match (true) {
            $dni === 0 => 'dnes '.$kdy->format('G:i'),
            $dni === 1 => 'včera '.$kdy->format('G:i'),
            default => $kdy->format('j. n.').' '.$kdy->format('G:i'),
        };
    }

    private function denCesky(CarbonImmutable $den): string
    {
        return $den->day.'. '.self::MESICE[$den->month].' '.$den->year;
    }
}
