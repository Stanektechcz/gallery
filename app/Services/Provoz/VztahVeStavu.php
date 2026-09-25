<?php

namespace App\Services\Provoz;

use App\Models\CoupleCoolingPurchase;
use App\Models\CoupleDecision;
use App\Models\CoupleDecisionRevision;
use App\Models\CoupleDisagreementPoint;
use App\Models\CoupleNudge;
use App\Models\CoupleNudgeReminder;
use App\Models\CouplePromise;
use App\Models\CoupleVeto;
use App\Models\CoupleVetoProposal;
use App\Models\GallerySpace;
use App\Models\User;
use App\Services\Auth\PristupDoGalerie;
use App\Support\Cas;
use App\Support\Tabulky;
use App\Support\Vejde;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

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
    public const SERVEROVE = [
        'decs', 'cools', 'sporMine', 'sporTheirs', 'vetoLog', 'vetoProps',
        'proms', 'nudges', 'patAuto',
    ];

    /** Kolik připomínek smí přibýt jedním zápisem (viz zapisPripominky()). */
    private const PRIPOMINEK_NARAZ = 5;

    /** Nejdelší rozvaha před nákupem v hodinách — rok; `cools_until` je `timestamp`. */
    private const ROZVAHA_NEJVIC_HODIN = 24 * 366;

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
        if (! Tabulky::je('couple_decisions')) {
            return;
        }

        $patch = Vejde::identifikatory($patch, ['decs', 'cools', 'proms', 'nudges', 'vetoProps']);

        // Jen dvojice: host se jménem v seznamu by jinak mohl slibovat,
        // žádat, navrhovat veto nebo o něčem rozhodnout za ně.
        $jmena = app(PristupDoGalerie::class)->dvojice($prostor)
            ->mapWithKeys(fn ($clen) => [(string) $clen->name => (int) $clen->id])->all();

        if (is_array($patch['decs'] ?? null)) {
            $this->zapisRozhodnuti($patch['decs'], $prostor, $jmena, $kdo);
        }

        if (is_array($patch['cools'] ?? null)) {
            $this->zapisRozvahy($patch['cools'], $prostor, $jmena, OdebraneVStavu::pro($patch, 'cools'), OdebraneVStavu::zmenene($patch, 'cools'));
        }

        /*
         * Jen vlastní body. `sporTheirs` jsou body druhého: obrazovka je jen
         * čte, a kdyby se zapisovaly, dalo by se druhému založit bod nebo
         * jeho podmínku přepsat na přání. Klíč se ze stavu zahodí jako dřív.
         */
        if (is_array($patch['sporMine'] ?? null)) {
            $this->zapisProtokol($patch['sporMine'], $prostor, $kdo, true);
        }

        if (is_array($patch['vetoProps'] ?? null)) {
            $this->zapisNavrhy($patch['vetoProps'], $prostor, $jmena);
        }

        if (is_array($patch['vetoLog'] ?? null)) {
            $this->zapisVeta($patch['vetoLog'], $prostor, $jmena);
        }

        if (is_array($patch['proms'] ?? null)) {
            $this->zapisSliby($patch['proms'], $prostor, $jmena, OdebraneVStavu::pro($patch, 'proms'), OdebraneVStavu::zmenene($patch, 'proms'));
        }

        if (is_array($patch['nudges'] ?? null)) {
            $this->zapisZadosti($patch['nudges'], $prostor, $jmena, $kdo, OdebraneVStavu::pro($patch, 'nudges'), OdebraneVStavu::zmenene($patch, 'nudges'));
        }

        if (is_array($patch['patAuto'] ?? null)) {
            $this->zapisPravidla($patch['patAuto'], $prostor);
        }
    }

    /**
     * Sliby.
     *
     * Slib, který zmizel ze seznamu, byl **zrušen po dohodě** — ne nedodržen.
     * Ten rozdíl je celý smysl téhle sekce a prototyp ho umí říct jen tím, že
     * řádek odebere; v databázi zůstává, protože se na něj nemá zapomenout.
     *
     * @param  array<int, mixed>  $radky
     * @param  array<string, int>  $jmena
     */
    private function zapisSliby(array $radky, GallerySpace $prostor, array $jmena, ?array $odebrane = null, ?array $zmenene = null): void
    {
        $vDatabazi = CouplePromise::where('gallery_space_id', $prostor->id)
            ->where('state', '!=', 'released')
            ->get();

        $podle = $this->podleId($vDatabazi);
        $prisly = [];

        foreach ($radky as $r) {
            if (! is_array($r) || ! isset($r['id'], $r['what'])) {
                continue;
            }

            $prisly[] = (string) $r['id'];
            // `late` je odvozený stav, ne uložený: po termínu se pozná z data.
            $stav = (string) ($r['state'] ?? 'open');
            $stav = $stav === 'late' ? 'open' : $stav;

            if ($podle->has($r['id'])) {
                $s = $podle[$r['id']];

                // Nezměněný slib se nepřepisuje starším opisem (viz OdebraneVStavu::zmenene()).
                if (! OdebraneVStavu::zmeneno($zmenene, $r['id'])) {
                    continue;
                }

                $s->update([
                    'what' => Vejde::do($r['what']),
                    'state' => in_array($stav, ['open', 'kept', 'broken'], true) ? $stav : $s->state,
                    // `due_label` má 120 znaků; při založení se ořezávalo, při úpravě ne.
                    'due_label' => isset($r['due']) ? Vejde::neboNic($r['due'], 120) : $s->due_label,
                    'due_on' => $this->terminSlibu($r, $s->due_on ? CarbonImmutable::parse($s->due_on) : null),
                    'settled_at' => in_array($stav, ['kept', 'broken'], true) ? ($s->settled_at ?? now()) : null,
                ]);

                continue;
            }

            $slibil = $jmena[$r['who'] ?? ''] ?? null;

            if (! $slibil) {
                continue;
            }

            CouplePromise::create([
                'client_id' => Vejde::do($r['id'], Vejde::KLIENT),
                'gallery_space_id' => $prostor->id,
                'promised_by' => $slibil,
                'promised_to' => $jmena[$r['to'] ?? ''] ?? null,
                'what' => Vejde::do($r['what']),
                'due_label' => Vejde::neboNic($r['due'] ?? null, 120),
                'due_on' => $this->terminSlibu($r, null),
                'state' => in_array($stav, ['open', 'kept', 'broken'], true) ? $stav : 'open',
                'said' => Vejde::neboNic($r['said'] ?? null),
                'settled_at' => in_array($stav, ['kept', 'broken'], true) ? now() : null,
            ]);
        }

        $vDatabazi
            ->reject(fn (CouplePromise $s) => in_array($s->uuid, $prisly, true)
                || ($s->client_id && in_array($s->client_id, $prisly, true)))
            // Jen sliby, které prohlížeč sám odebral — slib druhého v jeho seznamu chybí taky.
            ->filter(fn (CouplePromise $s) => OdebraneVStavu::smi($odebrane, $s->uuid, $s->client_id))
            ->each(fn (CouplePromise $s) => $s->update(['state' => 'released', 'settled_at' => now()]));
    }

    /**
     * Termín slibu.
     *
     * Prototyp posílá slova („do pátku") a k nim počet dní, který si sám
     * spočítal. Datum se bere z toho počtu — je to jediné číslo, které o termínu
     * doopravdy něco říká.
     *
     * @param  array<string, mixed>  $r
     */
    private function terminSlibu(array $r, ?CarbonImmutable $puvodni): ?CarbonImmutable
    {
        $dni = $r['days'] ?? null;

        // 99 je v prototypu „bez termínu", ne devadesát devět dní.
        if ($dni === null || (int) $dni === 99) {
            return str_contains(mb_strtolower((string) ($r['due'] ?? '')), 'bez termínu') ? null : $puvodni;
        }

        // „Do pátku" počítá prototyp od dneška dvojice, ne od dneška v UTC.
        return Cas::dnes()->addDays((int) $dni);
    }

    /**
     * Žádosti mezi partnery a připomínky k nim.
     *
     * Připomínka se do stavu vejde jen jako číslo; server z něj udělá záznam
     * s časem, protože přehled trpělivosti mluví o posledním měsíci a z čítače
     * se měsíc vyčíst nedá.
     *
     * @param  array<int, mixed>  $radky
     * @param  array<string, int>  $jmena
     */
    private function zapisZadosti(array $radky, GallerySpace $prostor, array $jmena, ?User $kdo, ?array $odebrane = null, ?array $zmenene = null): void
    {
        $vDatabazi = CoupleNudge::where('gallery_space_id', $prostor->id)->withCount('pripominky')->get();
        $podle = $this->podleId($vDatabazi);
        $prisly = [];

        foreach ($radky as $r) {
            if (! is_array($r) || ! isset($r['id'], $r['text'])) {
                continue;
            }

            $prisly[] = (string) $r['id'];
            $stav = (string) ($r['state'] ?? 'ceka');
            $stav = in_array($stav, ['ceka', 'prijato', 'odmitnuto', 'hotovo'], true) ? $stav : 'ceka';

            if ($podle->has($r['id'])) {
                $z = $podle[$r['id']];

                if (! OdebraneVStavu::zmeneno($zmenene, $r['id'])) {
                    continue;
                }

                $z->update([
                    'text' => Vejde::do($r['text']),
                    'kind' => Vejde::do($r['kind'] ?? $z->kind, 16),
                    'state' => $stav,
                    // `note` má 500 znaků a z prohlížeče může přijít i pole.
                    'note' => Vejde::neboNic($r['note'] ?? null, 500),
                    'closed_at' => in_array($stav, ['hotovo', 'odmitnuto'], true) ? ($z->closed_at ?? now()) : null,
                ]);

                $this->zapisPripominky($z, (int) ($r['rem'] ?? 0), $kdo);

                continue;
            }

            $odKoho = $jmena[$r['from'] ?? ''] ?? null;
            $komu = $jmena[$r['to'] ?? ''] ?? null;

            if (! $odKoho || ! $komu) {
                continue;
            }

            $nova = CoupleNudge::create([
                'client_id' => Vejde::do($r['id'], Vejde::KLIENT),
                'gallery_space_id' => $prostor->id,
                'asked_by' => $odKoho,
                'asked_of' => $komu,
                'text' => Vejde::do($r['text']),
                'kind' => Vejde::do($r['kind'] ?? 'cestou', 16),
                'state' => $stav,
                'note' => Vejde::neboNic($r['note'] ?? null, 500),
                'closed_at' => in_array($stav, ['hotovo', 'odmitnuto'], true) ? now() : null,
            ]);

            $this->zapisPripominky($nova, (int) ($r['rem'] ?? 0), $kdo);
        }

        // Zrušená žádost se maže — na rozdíl od slibu je to prosba, ne závazek,
        // a prototyp na ni má tlačítko „zrušit".
        $vDatabazi
            ->reject(fn (CoupleNudge $z) => in_array($z->uuid, $prisly, true)
                || ($z->client_id && in_array($z->client_id, $prisly, true)))
            // Jen žádosti, které prohlížeč sám zrušil — žádost druhého v jeho seznamu chybí taky.
            ->filter(fn (CoupleNudge $z) => OdebraneVStavu::smi($odebrane, $z->uuid, $z->client_id))
            ->each(fn (CoupleNudge $z) => $z->delete());
    }

    /**
     * Přibylé připomínky.
     *
     * Zapisuje se **rozdíl**: kolik jich klient hlásí navíc proti tomu, co je
     * uložené. Přepsat je znovu všechny by z jedné připomínky udělalo pět.
     */
    private function zapisPripominky(CoupleNudge $z, int $kolik, ?User $kdo): void
    {
        $uz = $z->pripominky_count ?? $z->pripominky()->count();

        /*
         * Nejvýš pár naráz.
         *
         * Číslo přichází z prohlížeče a nikdo ho nehlídal: `rem: 1000000`
         * založilo milion řádků v transakci, která drží zámek stavu dvojice —
         * a dokud neskončila, neuložil se nikomu z nich nic jiného. Jedno
         * klepnutí je jedna připomínka; strop nad jedničkou je jen pro frontu
         * v prohlížeči, která několik klepnutí bez sítě spojí do jednoho zápisu.
         */
        $chybi = min(self::PRIPOMINEK_NARAZ, $kolik - (int) $uz);

        if ($chybi <= 0) {
            return;
        }

        CoupleNudgeReminder::insert(array_fill(0, $chybi, [
            'couple_nudge_id' => $z->id,
            'reminded_by' => $kdo?->id,
            'created_at' => now(),
        ]));
    }

    /**
     * Co převzalo pravidlo.
     *
     * Prototyp si to pamatuje podle textu úkolu — vlastní identifikátor ta mapa
     * nemá. Věc, kterou obstarává automatizace, se nemá nikomu připomínat.
     *
     * @param  array<string, mixed>  $mapa
     */
    private function zapisPravidla(array $mapa, GallerySpace $prostor): void
    {
        // Text žádosti je v databázi ořezaný na šířku sloupce; klíče mapy taky.
        $automatizovane = array_map(fn ($text) => Vejde::do($text), array_keys(array_filter($mapa)));

        CoupleNudge::where('gallery_space_id', $prostor->id)
            ->get()
            ->each(function (CoupleNudge $z) use ($automatizovane) {
                $ma = in_array($z->text, $automatizovane, true);

                if ($ma === ($z->automated_at !== null)) {
                    return;
                }

                $z->update(['automated_at' => $ma ? now() : null]);
            });
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
            // `status` má 24 znaků — při založení se ořezával, při změně ne.
            $novyStav = Vejde::do($r['status'] ?? '', 24) ?: $zaznam->status;

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
            'client_id' => Vejde::do($r['id'], Vejde::KLIENT),
            'gallery_space_id' => $prostor->id,
            'title' => Vejde::do($r['title']),
            // Dnešek dvojice, ne UTC: po pražské půlnoci by UTC dalo včerejšek.
            'decided_on' => $this->datum((string) ($r['date'] ?? '')) ?? Cas::dnes(),
            'together' => $spolecne,
            'decided_by' => $spolecne ? null : ($jmena[$kdo] ?? null),
            'status' => Vejde::do($r['status'] ?? 'platí', 24),
            'why' => array_values(array_filter((array) ($r['why'] ?? []))),
            'rejected' => array_values(array_filter((array) ($r['rejected'] ?? []))),
            'review_note' => Vejde::neboNic($r['review'] ?? null, 120),
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
    private function zapisRozvahy(array $radky, GallerySpace $prostor, array $jmena, ?array $odebrane = null, ?array $zmenene = null): void
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
                if (! OdebraneVStavu::zmeneno($zmenene, $r['id'])) {
                    continue;
                }

                $podle[$r['id']]->update([
                    'opinion' => Vejde::neboNic($r['opinion'] ?? null),
                    'opinion_by' => isset($r['opinionBy']) ? ($jmena[$r['opinionBy']] ?? null) : null,
                    'verdict' => Vejde::neboNic($r['verdict'] ?? null, 24),
                ]);

                continue;
            }

            // Obří počet hodin by posunul `cools_until` za rok 2038, kam
            // `timestamp` nesahá; nula a záporné číslo znamenají výchozích 72.
            $hodin = Vejde::cislo($r['left'] ?? 72, 0, self::ROZVAHA_NEJVIC_HODIN);

            CoupleCoolingPurchase::create([
                'client_id' => Vejde::do($r['id'], Vejde::KLIENT),
                'gallery_space_id' => $prostor->id,
                'what' => Vejde::do($r['what']),
                'price' => Vejde::cislo($r['price'] ?? 0, 0, Vejde::INT),
                'requested_by' => $jmena[$r['who'] ?? ''] ?? null,
                'opened_at' => now(),
                'cools_until' => now()->addHours($hodin > 0 ? $hodin : 72),
                'opinion' => Vejde::neboNic($r['opinion'] ?? null),
                'opinion_by' => isset($r['opinionBy']) ? ($jmena[$r['opinionBy']] ?? null) : null,
                'verdict' => Vejde::neboNic($r['verdict'] ?? null, 24),
            ]);
        }

        $otevrene
            ->reject(fn (CoupleCoolingPurchase $n) => in_array($n->uuid, $prisly, true)
                || ($n->client_id && in_array($n->client_id, $prisly, true)))
            ->filter(fn (CoupleCoolingPurchase $n) => OdebraneVStavu::smi($odebrane, $n->uuid, $n->client_id))
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
            // Druhý z dvojice, ne kterýkoli jiný člen — host by jinak mohl být „ten druhý".
            : app(PristupDoGalerie::class)->dvojice($prostor)->first(fn ($clen) => (int) $clen->id !== (int) $kdo?->id)?->id;

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
            // Ořezaný jako v databázi (a pole jako klíč by shodilo celý zápis).
            $bod = $vDatabazi[Vejde::do($r['text'])] ?? null;

            if ($bod) {
                $bod->update(['kind' => Vejde::do($r['kind'] ?? $bod->kind, 16)]);

                continue;
            }

            CoupleDisagreementPoint::create([
                'gallery_space_id' => $prostor->id,
                'author_user_id' => $autor,
                'text' => Vejde::do($r['text']),
                'tag' => Vejde::neboNic($r['tag'] ?? null, 40),
                'kind' => Vejde::do($r['kind'] ?? 'podmínka', 16),
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
                $podle[$r['id']]->update(['outcome' => Vejde::neboNic($r['done'] ?? null, 16)]);

                continue;
            }

            $navrhl = $jmena[$r['by'] ?? ''] ?? null;

            if (! $navrhl) {
                continue;
            }

            CoupleVetoProposal::create([
                'client_id' => Vejde::do($r['id'], Vejde::KLIENT),
                'gallery_space_id' => $prostor->id,
                'proposed_by' => $navrhl,
                'text' => Vejde::do($r['text']),
                'price' => Vejde::cislo($r['price'] ?? 0, 0, Vejde::INT),
                'proposed_on' => now(),
                'outcome' => Vejde::neboNic($r['done'] ?? null, 16),
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

            $kdo = is_scalar($r['who']) ? ($jmena[(string) $r['who']] ?? null) : null;

            // Porovnává se s tím, co leží v databázi — tedy s ořezaným textem.
            // Dlouhé veto by se jinak nenašlo a založilo se znovu při každém zápisu.
            if (! $kdo || $zname->has($kdo.'|'.Vejde::do($r['text']))) {
                continue;
            }

            CoupleVeto::create([
                'gallery_space_id' => $prostor->id,
                'user_id' => $kdo,
                'text' => Vejde::do($r['text']),
                'used_on' => now(),
                'reason' => Vejde::neboNic($r['reason'] ?? null),
            ]);
        }
    }

    /** „14. 1. 2026" zpátky na datum; „dnes" je dnešek. */
    private function datum(string $text): ?CarbonImmutable
    {
        // „Dnes" dvojice, ne serveru: v UTC je po pražské půlnoci ještě včera.
        if (trim($text) === 'dnes') {
            return Cas::dnes();
        }

        // Tvar nestačí — „45. 13. 2026" by `createFromDate` tiše přetočil.
        if (! preg_match('/(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})/u', $text, $shoda)
            || ! checkdate((int) $shoda[2], (int) $shoda[1], (int) $shoda[3])) {
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
