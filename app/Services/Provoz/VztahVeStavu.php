<?php

namespace App\Services\Provoz;

use App\Models\CoupleCoolingPurchase;
use App\Models\CoupleDecision;
use App\Models\CoupleDecisionRevision;
use App\Models\CoupleDisagreementPoint;
use App\Models\CoupleVeto;
use App\Models\CoupleVetoProposal;
use App\Models\GallerySpace;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Mechanismy vztahu, které přišly jako změna stavu.
 *
 * Stejný důvod jako u domácnosti: rozhodnutí, rozvahy, protokol nesouhlasu ani
 * veto banka nemají v aplikaci jiného vlastníka, takže by tabulka bez zápisu
 * jen znamenala druhou pravdu. Záměr se přečte, provede a ze stavu vyhodí.
 *
 * Jedna věc je tu jinak než u domácnosti: **rozhodnutí se nemažou.** Když
 * zmizí ze seznamu, je to změna stavu, ne smazání — celý smysl paměti
 * rozhodnutí je, že za rok víte, co jste si tehdy mysleli.
 */
class VztahVeStavu
{
    /** Klíče, které patří databázi. Do stavu se neukládají. */
    public const SERVEROVE = ['decs', 'cools', 'sporMine', 'sporTheirs', 'vetoLog', 'vetoProps'];

    public function tykaSe(array $patch): bool
    {
        return array_intersect(self::SERVEROVE, array_keys($patch)) !== [];
    }

    /** @return array<string, mixed> patch bez klíčů, které si bere databáze */
    public function bezVztahu(array $patch): array
    {
        return array_diff_key($patch, array_flip(self::SERVEROVE));
    }

    public function zpracuj(array $patch, GallerySpace $prostor, ?User $kdo): void
    {
        if (! Schema::hasTable('couple_decisions')) {
            return;
        }

        $jmena = array_flip($prostor->members()->pluck('users.name', 'users.id')->all());

        if (is_array($patch['decs'] ?? null)) {
            $this->zapisRozhodnuti($patch['decs'], $prostor, $jmena, $kdo);
        }

        if (is_array($patch['cools'] ?? null)) {
            $this->zapisRozvahy($patch['cools'], $prostor, $jmena);
        }

        foreach (['sporMine' => true, 'sporTheirs' => false] as $klic => $moje) {
            if (is_array($patch[$klic] ?? null)) {
                $this->zapisProtokol($patch[$klic], $prostor, $kdo, $moje);
            }
        }

        if (is_array($patch['vetoProps'] ?? null)) {
            $this->zapisNavrhy($patch['vetoProps'], $prostor, $jmena);
        }

        if (is_array($patch['vetoLog'] ?? null)) {
            $this->zapisVeta($patch['vetoLog'], $prostor, $jmena);
        }
    }

    /**
     * Paměť rozhodnutí: nová se zakládají, změněná dostanou revizi.
     *
     * @param  array<int, mixed>  $radky
     * @param  array<string, int>  $jmena
     */
    private function zapisRozhodnuti(array $radky, GallerySpace $prostor, array $jmena, ?User $kdo): void
    {
        $vDatabazi = CoupleDecision::where('gallery_space_id', $prostor->id)->get();
        $podle = $this->podleId($vDatabazi);

        foreach ($radky as $r) {
            if (! is_array($r) || ! isset($r['id'], $r['title'])) {
                continue;
            }

            if (! $podle->has($r['id'])) {
                $this->zaloz($r, $prostor, $jmena);

                continue;
            }

            $zaznam = $podle[$r['id']];
            $novyStav = (string) ($r['status'] ?? $zaznam->status);

            /*
             * Změna stavu zakládá revizi.
             *
             * Původní znění se nepřepisuje — právě proto, aby za rok bylo vidět,
             * co jste si tehdy mysleli. Podruhé už se stejná revize nezakládá.
             */
            if ($novyStav !== $zaznam->status && $novyStav === 'změněno') {
                CoupleDecisionRevision::firstOrCreate([
                    'couple_decision_id' => $zaznam->id,
                    'wording' => $zaznam->title,
                ], [
                    'changed_by' => $kdo?->id,
                    'valid_from' => $zaznam->decided_on,
                ]);

                $zaznam->changed_at = now();
            }

            $zaznam->status = $novyStav;
            $zaznam->save();
        }
    }

    /**
     * @param  array<string, mixed>  $r
     * @param  array<string, int>  $jmena
     */
    private function zaloz(array $r, GallerySpace $prostor, array $jmena): void
    {
        $kdo = (string) ($r['by'] ?? '');
        // „Adrian a Makinka" znamená společné rozhodnutí; jedno jméno jednoho autora.
        $spolecne = str_contains($kdo, ' a ') || $kdo === '';

        CoupleDecision::create([
            'client_id' => (string) $r['id'],
            'gallery_space_id' => $prostor->id,
            'title' => (string) $r['title'],
            'decided_on' => $this->datum((string) ($r['date'] ?? '')) ?? CarbonImmutable::now(),
            'together' => $spolecne,
            'decided_by' => $spolecne ? null : ($jmena[$kdo] ?? null),
            'status' => (string) ($r['status'] ?? 'platí'),
            'why' => array_values(array_filter((array) ($r['why'] ?? []))),
            'rejected' => array_values(array_filter((array) ($r['rejected'] ?? []))),
            'review_note' => $r['review'] ?? null,
        ]);
    }

    /**
     * Rozvaha před nákupem: nová se zakládá, zavřená dostane `closed_at`.
     *
     * Nemaže se: „koupili jsme to po 72 hodinách" je informace, kterou má smysl
     * mít i za rok.
     *
     * @param  array<int, mixed>  $radky
     * @param  array<string, int>  $jmena
     */
    private function zapisRozvahy(array $radky, GallerySpace $prostor, array $jmena): void
    {
        $otevrene = CoupleCoolingPurchase::where('gallery_space_id', $prostor->id)
            ->whereNull('closed_at')
            ->get();

        $podle = $this->podleId($otevrene);
        $prisly = [];

        foreach ($radky as $r) {
            if (! is_array($r) || ! isset($r['id'], $r['what'])) {
                continue;
            }

            $prisly[] = (string) $r['id'];

            if ($podle->has($r['id'])) {
                $podle[$r['id']]->update([
                    'opinion' => $r['opinion'] ?? null,
                    'opinion_by' => isset($r['opinionBy']) ? ($jmena[$r['opinionBy']] ?? null) : null,
                    'verdict' => $r['verdict'] ?? null,
                ]);

                continue;
            }

            $hodin = (int) ($r['left'] ?? 72);

            CoupleCoolingPurchase::create([
                'client_id' => (string) $r['id'],
                'gallery_space_id' => $prostor->id,
                'what' => (string) $r['what'],
                'price' => (int) ($r['price'] ?? 0),
                'requested_by' => $jmena[$r['who'] ?? ''] ?? null,
                'opened_at' => now(),
                'cools_until' => now()->addHours($hodin > 0 ? $hodin : 72),
                'opinion' => $r['opinion'] ?? null,
                'opinion_by' => isset($r['opinionBy']) ? ($jmena[$r['opinionBy']] ?? null) : null,
                'verdict' => $r['verdict'] ?? null,
            ]);
        }

        $otevrene
            ->reject(fn (CoupleCoolingPurchase $n) => in_array($n->uuid, $prisly, true)
                || ($n->client_id && in_array($n->client_id, $prisly, true)))
            ->each(fn (CoupleCoolingPurchase $n) => $n->update(['closed_at' => now()]));
    }

    /**
     * Protokol nesouhlasu.
     *
     * `sporMine` patří tomu, kdo píše, `sporTheirs` druhému z dvojice — a to je
     * jediné místo, kde na přihlášeném člověku doopravdy záleží.
     *
     * @param  array<int, mixed>  $radky
     */
    private function zapisProtokol(array $radky, GallerySpace $prostor, ?User $kdo, bool $moje): void
    {
        $autor = $moje
            ? $kdo?->id
            : $prostor->members()->where('users.id', '!=', $kdo?->id)->value('users.id');

        if (! $autor) {
            return;
        }

        $vDatabazi = CoupleDisagreementPoint::where('gallery_space_id', $prostor->id)
            ->where('author_user_id', $autor)
            ->get()
            ->keyBy('text');

        foreach ($radky as $r) {
            if (! is_array($r) || ! isset($r['text'])) {
                continue;
            }

            // Text je tu identifikátorem: prototyp body nečísluje a jediné, co
            // se na nich mění, je právě to, jestli je z podmínky přání.
            $bod = $vDatabazi[$r['text']] ?? null;

            if ($bod) {
                $bod->update(['kind' => (string) ($r['kind'] ?? $bod->kind)]);

                continue;
            }

            CoupleDisagreementPoint::create([
                'gallery_space_id' => $prostor->id,
                'author_user_id' => $autor,
                'text' => (string) $r['text'],
                'tag' => $r['tag'] ?? null,
                'kind' => (string) ($r['kind'] ?? 'podmínka'),
            ]);
        }
    }

    /**
     * Návrhy k vetu.
     *
     * @param  array<int, mixed>  $radky
     * @param  array<string, int>  $jmena
     */
    private function zapisNavrhy(array $radky, GallerySpace $prostor, array $jmena): void
    {
        $podle = $this->podleId(CoupleVetoProposal::where('gallery_space_id', $prostor->id)->get());

        foreach ($radky as $r) {
            if (! is_array($r) || ! isset($r['id'], $r['text'])) {
                continue;
            }

            if ($podle->has($r['id'])) {
                $podle[$r['id']]->update(['outcome' => $r['done'] ?? null]);

                continue;
            }

            $navrhl = $jmena[$r['by'] ?? ''] ?? null;

            if (! $navrhl) {
                continue;
            }

            CoupleVetoProposal::create([
                'client_id' => (string) $r['id'],
                'gallery_space_id' => $prostor->id,
                'proposed_by' => $navrhl,
                'text' => (string) $r['text'],
                'price' => (int) ($r['price'] ?? 0),
                'proposed_on' => now(),
                'outcome' => $r['done'] ?? null,
            ]);
        }
    }

    /**
     * Použitá veta.
     *
     * Prototyp je jen přidává na začátek seznamu a datum si nese jako text.
     * Skutečný den vzniká tady — bez něj by se nedalo spočítat, kolik jich komu
     * zbývá, protože veto se vrací po dvanácti měsících.
     *
     * @param  array<int, mixed>  $radky
     * @param  array<string, int>  $jmena
     */
    private function zapisVeta(array $radky, GallerySpace $prostor, array $jmena): void
    {
        $zname = CoupleVeto::where('gallery_space_id', $prostor->id)
            ->get()
            ->map(fn (CoupleVeto $v) => $v->user_id.'|'.$v->text)
            ->flip();

        foreach (array_reverse($radky) as $r) {
            if (! is_array($r) || ! isset($r['text'], $r['who'])) {
                continue;
            }

            $kdo = $jmena[$r['who']] ?? null;

            if (! $kdo || $zname->has($kdo.'|'.$r['text'])) {
                continue;
            }

            CoupleVeto::create([
                'gallery_space_id' => $prostor->id,
                'user_id' => $kdo,
                'text' => (string) $r['text'],
                'used_on' => now(),
                'reason' => $r['reason'] ?? null,
            ]);
        }
    }

    /** „14. 1. 2026" zpátky na datum; „dnes" je dnešek. */
    private function datum(string $text): ?CarbonImmutable
    {
        if (trim($text) === 'dnes') {
            return CarbonImmutable::now();
        }

        if (! preg_match('/(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})/u', $text, $shoda)) {
            return null;
        }

        return CarbonImmutable::createFromDate((int) $shoda[3], (int) $shoda[2], (int) $shoda[1]);
    }

    /**
     * Řádky klíčované uuid i identifikátorem klienta.
     *
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  Collection<int, T>  $radky
     * @return Collection<string, T>
     */
    private function podleId(Collection $radky): Collection
    {
        $mapa = collect();

        foreach ($radky as $r) {
            $mapa[$r->uuid] = $r;

            if ($r->client_id) {
                $mapa[$r->client_id] = $r;
            }
        }

        return $mapa;
    }
}
