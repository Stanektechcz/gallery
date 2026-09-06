<?php

namespace App\Services\Obsah;

use App\Models\CoupleCoolingPurchase;
use App\Models\CoupleDecision;
use App\Models\CoupleDisagreementPoint;
use App\Models\CoupleVeto;
use App\Models\CoupleVetoProposal;
use App\Models\GallerySpace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Mechanismy vztahu ve tvaru, ve kterém je kreslí prototyp.
 *
 * Paměť rozhodnutí, rozvaha před nákupem, protokol nesouhlasu a veto banka jsou
 * jediné čtyři, které se v prototypu dají měnit — a proto jediné, které mají
 * tabulku. Zbytek (tiché dohody, kdo mluví za nás, premortem) zůstává v katalogu:
 * tabulka, do které nikdo nepíše, je horší než žádná.
 *
 * Přehled arbitráže i záznam verzí se **odvozují z rozhodnutí**, ne z druhého
 * seznamu — ten by se s rozhodnutími dřív nebo později rozešel.
 */
class Vztah implements PoskytovatelObsahu
{
    private const MESICE = [1 => 'ledna', 'února', 'března', 'dubna', 'května', 'června',
        'července', 'srpna', 'září', 'října', 'listopadu', 'prosince'];

    public function skupina(): string
    {
        return 'vztah';
    }

    public function uplne(): array
    {
        return [];
    }

    public function kolekce(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('couple_decisions')) {
            return [];
        }

        $jmena = $prostor->members()->pluck('users.name', 'users.id')->all();
        $rozhodnuti = $this->rozhodnuti($prostor);
        $body = $this->body($prostor);

        return array_filter([
            'DEC_LIST' => $this->pamet($rozhodnuti, $jmena),
            'ARB' => $this->arbitraz($rozhodnuti, $jmena),
            'VERSIONS' => $this->verze($rozhodnuti, $jmena),
            'DEC_COOL' => $this->rozvahy($prostor, $jmena),
            // Protokol nesouhlasu se dělí podle toho, kdo se dívá.
            'SPOR_MINE' => $this->protokol($body, $this->ja(), true),
            'SPOR_THEIRS' => $this->protokol($body, $this->ja(), false),
            'VETO_USED' => $this->veta($prostor, $jmena),
            'VETO_PROP' => $this->navrhy($prostor, $jmena),
        ], fn ($v) => $v !== null && $v !== []);
    }

    /**
     * Kdo se dívá.
     *
     * Protokol nesouhlasu je jediná kolekce, která vypadá jinak pro každého
     * z dvojice: „moje podmínky" a „jeho podmínky" jsou tytéž řádky obrácené.
     */
    private function ja(): ?int
    {
        return auth()->id();
    }

    /** @return Collection<int, CoupleDecision> */
    private function rozhodnuti(GallerySpace $prostor): Collection
    {
        return CoupleDecision::where('gallery_space_id', $prostor->id)
            ->with('revize')
            ->orderByDesc('decided_on')
            ->limit(60)
            ->get();
    }

    /**
     * Paměť rozhodnutí: `{ id, title, date, by, status, why, rejected, review }`.
     *
     * @param  Collection<int, CoupleDecision>  $rozhodnuti
     * @param  array<int, string>  $jmena
     * @return list<array<string, mixed>>
     */
    private function pamet(Collection $rozhodnuti, array $jmena): array
    {
        return $rozhodnuti->map(fn (CoupleDecision $r) => array_filter([
            'id' => $r->uuid,
            'title' => $r->title,
            'date' => CarbonImmutable::parse($r->decided_on)->format('j. n. Y'),
            'by' => $r->together ? implode(' a ', array_values($jmena)) : ($jmena[$r->decided_by] ?? 'oba'),
            'status' => $r->status,
            'why' => $r->why ?: ['Důvod zapíšeme později.'],
            'rejected' => $r->rejected ?: ['Nic dalšího jsme nezvažovali'],
            'review' => $r->review_note ?: ($r->review_on
                ? 'v '.self::MESICE[CarbonImmutable::parse($r->review_on)->month].' '.CarbonImmutable::parse($r->review_on)->year
                : 'bez revize'),
            'changedAt' => $r->changed_at ? CarbonImmutable::parse($r->changed_at)->format('j. n. Y') : null,
        ], fn ($v) => $v !== null))->values()->all();
    }

    /**
     * Kdo měl poslední slovo: `{ q, w, date, how }`.
     *
     * Odvozuje se z rozhodnutí, která mají arbitra — ne z druhého seznamu.
     *
     * @param  Collection<int, CoupleDecision>  $rozhodnuti
     * @param  array<int, string>  $jmena
     * @return list<array<string, mixed>>
     */
    private function arbitraz(Collection $rozhodnuti, array $jmena): array
    {
        return $rozhodnuti
            ->filter(fn (CoupleDecision $r) => $r->arbiter_user_id !== null)
            ->map(fn (CoupleDecision $r) => [
                'q' => $r->title,
                'w' => $jmena[$r->arbiter_user_id] ?? '—',
                'date' => CarbonImmutable::parse($r->decided_on)->format('Y-m-d'),
                'how' => $r->arbiter_method ?: 'poslední slovo',
            ])
            ->values()
            ->all();
    }

    /**
     * Záznam verzí: `{ dec, steps: [{ v, by, when }] }`.
     *
     * @param  Collection<int, CoupleDecision>  $rozhodnuti
     * @param  array<int, string>  $jmena
     * @return list<array<string, mixed>>
     */
    private function verze(Collection $rozhodnuti, array $jmena): array
    {
        return $rozhodnuti
            ->filter(fn (CoupleDecision $r) => $r->revize->isNotEmpty())
            ->map(fn (CoupleDecision $r) => [
                'dec' => $r->title,
                'steps' => $r->revize->map(fn ($v) => [
                    'v' => $v->wording,
                    'by' => $jmena[$v->changed_by] ?? 'oba',
                    'when' => CarbonImmutable::parse($v->valid_from)->format('Y-m-d'),
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Rozvaha před nákupem: `{ id, what, price, who, opened, left, opinion, opinionBy }`.
     *
     * `left` jsou **hodiny do konce lhůty**, spočítané teď — ne uložené číslo,
     * které by po zavření prohlížeče zamrzlo.
     *
     * @param  array<int, string>  $jmena
     * @return list<array<string, mixed>>
     */
    private function rozvahy(GallerySpace $prostor, array $jmena): array
    {
        $ted = CarbonImmutable::now();

        return CoupleCoolingPurchase::where('gallery_space_id', $prostor->id)
            ->whereNull('closed_at')
            ->orderBy('cools_until')
            ->get()
            ->map(fn (CoupleCoolingPurchase $n) => [
                'id' => $n->uuid,
                'what' => $n->what,
                'price' => (int) $n->price,
                'who' => $jmena[$n->requested_by] ?? 'oba',
                'opened' => $this->kdy(CarbonImmutable::parse($n->opened_at)),
                // Nahoru, ne dolů: začatá hodina se ještě počítá, jinak by
                // rozvaha otevřená před vteřinou hlásila o hodinu míň.
                'left' => max(0, (int) ceil($ted->diffInHours(CarbonImmutable::parse($n->cools_until), false))),
                'opinion' => $n->opinion,
                'opinionBy' => $n->opinion ? ($jmena[$n->opinion_by] ?? null) : null,
                'verdict' => $n->verdict,
            ])
            ->values()
            ->all();
    }

    /** @return Collection<int, CoupleDisagreementPoint> */
    private function body(GallerySpace $prostor): Collection
    {
        return CoupleDisagreementPoint::where('gallery_space_id', $prostor->id)
            ->orderBy('id')
            ->get();
    }

    /**
     * Protokol nesouhlasu z jedné strany: `{ text, tag, kind }`.
     *
     * @param  Collection<int, CoupleDisagreementPoint>  $body
     * @return list<array<string, mixed>>
     */
    private function protokol(Collection $body, ?int $ja, bool $moje): array
    {
        return $body
            ->filter(fn (CoupleDisagreementPoint $b) => $moje
                ? $b->author_user_id === $ja
                : $b->author_user_id !== $ja)
            ->map(fn (CoupleDisagreementPoint $b) => [
                'text' => $b->text,
                'tag' => (string) ($b->tag ?? ''),
                'kind' => $b->kind,
            ])
            ->values()
            ->all();
    }

    /**
     * Použitá veta: `{ who, text, date, reason }`.
     *
     * @param  array<int, string>  $jmena
     * @return list<array<string, mixed>>
     */
    private function veta(GallerySpace $prostor, array $jmena): array
    {
        return CoupleVeto::where('gallery_space_id', $prostor->id)
            ->orderByDesc('used_on')
            ->get()
            ->map(fn (CoupleVeto $v) => [
                'who' => $jmena[$v->user_id] ?? '—',
                'text' => $v->text,
                'date' => $this->denCesky(CarbonImmutable::parse($v->used_on)),
                'reason' => (string) ($v->reason ?? ''),
            ])
            ->values()
            ->all();
    }

    /**
     * Návrhy k vetu: `{ id, by, text, date, price, done }`.
     *
     * @param  array<int, string>  $jmena
     * @return list<array<string, mixed>>
     */
    private function navrhy(GallerySpace $prostor, array $jmena): array
    {
        return CoupleVetoProposal::where('gallery_space_id', $prostor->id)
            ->orderByDesc('proposed_on')
            ->get()
            ->map(fn (CoupleVetoProposal $n) => array_filter([
                'id' => $n->uuid,
                'by' => $jmena[$n->proposed_by] ?? '—',
                'text' => $n->text,
                'date' => CarbonImmutable::parse($n->proposed_on)->day.'. '
                    .self::MESICE[CarbonImmutable::parse($n->proposed_on)->month],
                'price' => (int) $n->price,
                'done' => $n->outcome,
            ], fn ($v) => $v !== null))
            ->values()
            ->all();
    }

    // ——— formát ———

    /** „dnes 7:40", „včera 20:15", jinak datum. */
    private function kdy(CarbonImmutable $kdy): string
    {
        $dni = (int) $kdy->startOfDay()->diffInDays(CarbonImmutable::now()->startOfDay());

        return match (true) {
            $dni === 0 => 'dnes '.$kdy->format('G:i'),
            $dni === 1 => 'včera '.$kdy->format('G:i'),
            default => $kdy->format('j. n.'),
        };
    }

    private function denCesky(CarbonImmutable $den): string
    {
        return $den->day.'. '.self::MESICE[$den->month].' '.$den->year;
    }
}
