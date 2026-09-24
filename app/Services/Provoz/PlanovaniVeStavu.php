<?php

namespace App\Services\Provoz;

use App\Models\CalendarEvent;
use App\Models\GallerySpace;
use App\Models\SharedTodo;
use App\Models\User;
use App\Services\Obsah\Planovani;
use App\Support\Cas;
use App\Support\Tabulky;
use App\Support\Vejde;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Kalendář a úkoly, které přišly jako změna stavu.
 *
 * Tady je to jinak než u domácnosti nebo rozhodnutí: `calendar_events`
 * a `shared_todos` **vlastní aplikace sama**. Připomínky, automatizace, cesty
 * i výroční přehled se na ně ptají a píší do nich. Prototyp ale všechno drží
 * ve svém stavu, takže bez téhle vrstvy má dvojice dva kalendáře — jeden
 * v prohlížeči a druhý v databázi, a ten druhý o jejich změnách neví.
 *
 * Právě proto, že tabulky vlastní někdo jiný, je tenhle zápis **opatrnější**
 * než ostatní: ukázkové řádky se neimportují a maže se jen to, co server sám
 * poslal. Zapisuje se přesně to, co prototyp umí — nic navíc.
 */
class PlanovaniVeStavu
{
    /**
     * Klíče, které patří databázi. Do stavu se neukládají.
     *
     * `…Zmenene`, `…Zrusene` a `evObnovene` jsou rozdíl, který spočítá
     * prohlížeč: co se v jeho seznamu změnilo, co z něj zmizelo a co se do
     * něj vrátilo tlačítkem Zpět. Celý seznam je totiž kopie z doby načtení —
     * co mezitím přidal ten druhý nebo co se do seznamu nevešlo, v něm
     * chybí, a mazat podle něj znamenalo mazat cizí události.
     */
    public const SERVEROVE = [
        'evList', 'evDoneMap', 'xBoard', 'hsLater',
        'evZmenene', 'evZrusene', 'evObnovene', 'xBoardZmenene', 'xBoardZrusene',
    ];

    /** Jak dlouho zpátky se nový záznam páruje s identifikátorem z prohlížeče. */
    private const DNU_KLIENT = 14;

    /** Ukázkový úkol nástěnky: `all0-3` (klíč, sloupec, pořadí). */
    private const UKAZKOVY_UKOL = '/^[a-z]+\d+-\d+$/';

    public function tykaSe(array $patch): bool
    {
        return array_intersect(self::SERVEROVE, array_keys($patch)) !== [];
    }

    /** @return array<string, mixed> patch bez klíčů, které si bere databáze */
    public function bezPlanovani(array $patch): array
    {
        return array_diff_key($patch, array_flip(self::SERVEROVE));
    }

    public function zpracuj(array $patch, GallerySpace $prostor, ?User $kdo): void
    {
        if (! $kdo) {
            return;
        }

        if (is_array($patch['evList'] ?? null)) {
            $this->zapisUdalosti(
                $patch['evList'], $patch['evDoneMap'] ?? null, $prostor, $kdo,
                $this->idcka($patch['evZmenene'] ?? null), $this->idcka($patch['evObnovene'] ?? null) ?? [],
            );
        } elseif (is_array($patch['evDoneMap'] ?? null)) {
            // Odškrtnutí přijde samo, bez seznamu událostí.
            $this->zapisOdskrtnuti($patch['evDoneMap'], $prostor);
        }

        if (is_array($patch['evZrusene'] ?? null)) {
            $this->smazUdalosti($this->idcka($patch['evZrusene']) ?? [], $prostor);
        }

        if (is_array($patch['xBoard'] ?? null)) {
            $this->zapisNastenku($patch['xBoard'], $prostor, $kdo, $this->idcka($patch['xBoardZmenene'] ?? null));
        }

        if (is_array($patch['xBoardZrusene'] ?? null)) {
            $this->zrusUkoly($this->idcka($patch['xBoardZrusene']) ?? [], $prostor);
        }

        if (is_array($patch['hsLater'] ?? null)) {
            $this->zapisNekdy($patch['hsLater'], $prostor, $kdo);
        }
    }

    // ——— kalendář ———

    /**
     * Události z kalendáře.
     *
     * Prototyp posílá celý seznam a vedle něj rozdíl (`evZmenene`). Zapisuje
     * se jen to, co se změnilo: seznam v prohlížeči je kopie z doby načtení
     * a úprava jedné události by jinak přepsala i ty, které mezitím změnil
     * ten druhý. Bez rozdílu (starší klient) se bere celý seznam.
     *
     * Nová událost se pozná podle předpony prototypu (`ev-n1`, `ev-d4`…)
     * a pamatuje si ji: prohlížeč ji pod tímhle jménem posílá až do obnovení
     * stránky a každé další odeslání ji dřív založilo znovu. Ukázkové řádky
     * (`ev0`, `ev1`) se ignorují. Mazání je jen výslovné — viz smazUdalosti().
     *
     * @param  array<int, mixed>  $radky
     * @param  array<string, mixed>|null  $odskrtnute
     * @param  list<string>|null  $zmenene
     * @param  list<string>  $obnovene
     */
    private function zapisUdalosti(array $radky, ?array $odskrtnute, GallerySpace $prostor, User $kdo, ?array $zmenene, array $obnovene): void
    {
        foreach ($radky as $e) {
            if (! is_array($e) || ! isset($e['id'], $e['t'])) {
                continue;
            }

            $id = (string) $e['id'];

            if ($zmenene !== null && ! in_array($id, $zmenene, true) && ! in_array($id, $obnovene, true)) {
                continue;
            }

            $u = $this->udalost($id, $prostor);

            if ($u) {
                $this->uprav($u, $e, $prostor);

                continue;
            }

            // Nová událost — nebo smazaná a vrácená tlačítkem Zpět (ta se
            // založí znovu jen na výslovnou žádost, jinak by starší kopie
            // seznamu vzkřísila, co ten druhý smazal).
            $nova = $this->uuidUdalosti($id) === null && preg_match('/^ev-[a-z]\d/', $id);

            if ($nova || in_array($id, $obnovene, true)) {
                $this->zaloz($e, $prostor, $kdo, $id);
            }
        }

        if (is_array($odskrtnute)) {
            $this->zapisOdskrtnuti($odskrtnute, $prostor);
        }
    }

    /**
     * Smazané události — jen ty, které prohlížeč výslovně odebral.
     *
     * Dřív se mazalo všechno z okna ±90/400 dnů, co v odeslaném seznamu
     * chybělo. Seznam má ale limit a je to kopie z doby načtení: úprava
     * jedné události smazala ty, které se do seznamu nevešly, i ty, které
     * mezitím přidal ten druhý nebo cesta či automatizace.
     *
     * @param  list<string>  $idcka
     */
    private function smazUdalosti(array $idcka, GallerySpace $prostor): void
    {
        foreach ($idcka as $id) {
            $this->udalost($id, $prostor)?->delete();
        }
    }

    /** Událost podle identifikátoru z prohlížeče: `ev-<uuid>`, nebo založená z `ev-n1`. */
    private function udalost(string $id, GallerySpace $prostor): ?CalendarEvent
    {
        $uuid = $this->uuidUdalosti($id);

        if ($uuid !== null) {
            $u = CalendarEvent::where('gallery_space_id', $prostor->id)->where('is_private', false)->where('uuid', $uuid)->first();

            if ($u) {
                return $u;
            }
        }

        return CalendarEvent::where('gallery_space_id', $prostor->id)
            ->where('is_private', false)
            ->where('metadata->klient_id', $id)
            ->where('created_at', '>=', now()->subDays(self::DNU_KLIENT))
            ->latest('id')
            ->first();
    }

    /** @param  array<string, mixed>  $e */
    private function uprav(CalendarEvent $u, array $e, GallerySpace $prostor): void
    {
        $zacatek = $this->zacatek($e);

        if (! $zacatek) {
            return;
        }

        $u->update([
            'title' => Vejde::do($e['t'], 160),
            'description' => $e['note'] ?? null,
            'type' => $this->typ((string) ($e['kind'] ?? 'jine')),
            'activity_kind' => $this->cinnost($e),
            'starts_at' => $zacatek,
            'all_day' => ($e['time'] ?? '') === '',
            'album_id' => $this->albumId($e, $prostor),
        ]);

        $this->ucastnici($u, $e, $prostor);
        $this->pripominka($u, (string) ($e['remind'] ?? ''));
    }

    /** @param  array<string, mixed>  $e */
    private function zaloz(array $e, GallerySpace $prostor, User $kdo, string $klientId): void
    {
        $zacatek = $this->zacatek($e);

        if (! $zacatek) {
            return;
        }

        $u = CalendarEvent::create([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $prostor->id,
            'created_by' => $kdo->id,
            'title' => Vejde::do($e['t'], 160),
            'description' => $e['note'] ?? null,
            'type' => $this->typ((string) ($e['kind'] ?? 'jine')),
            'activity_kind' => $this->cinnost($e),
            'status' => 'planned',
            'starts_at' => $zacatek,
            'all_day' => ($e['time'] ?? '') === '',
            'timezone' => 'Europe/Prague',
            'is_private' => false,
            'album_id' => $this->albumId($e, $prostor),
            // Opakování prototyp rozepisuje na jednotlivé události dopředu,
            // takže se neukládá jako pravidlo — přijdou všechny zvlášť.
            'metadata' => ['source' => 'prototyp', 'klient_id' => $klientId],
        ]);

        $this->ucastnici($u, $e, $prostor);
        $this->pripominka($u, (string) ($e['remind'] ?? ''));
    }

    /**
     * Kdo událost má.
     *
     * `spolu` znamená oba; jméno znamená jednoho. Prototyp podle toho i posílá
     * připomenutí, takže to nesmí zůstat jen v popisku.
     *
     * @param  array<string, mixed>  $e
     */
    private function ucastnici(CalendarEvent $u, array $e, GallerySpace $prostor): void
    {
        if (! Tabulky::je('event_participants')) {
            return;
        }

        $kdo = (string) ($e['who'] ?? 'spolu');
        $lide = $prostor->members()->pluck('users.id', 'users.name');

        $vybrani = $kdo === 'spolu'
            ? $lide->values()->all()
            : array_values(array_filter([$lide[$kdo] ?? null]));

        if (! $vybrani) {
            return;
        }

        DB::table('event_participants')->where('event_id', $u->id)->delete();

        foreach ($vybrani as $id) {
            DB::table('event_participants')->insert([
                'event_id' => $u->id,
                'user_id' => $id,
                'role' => 'owner',
                'response' => 'yes',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Připomenutí.
     *
     * Prototyp nabízí čtyři možnosti; databáze drží okamžik. Nastavené
     * připomenutí se přepíše, zrušené smaže — jinak by chodilo dál.
     */
    private function pripominka(CalendarEvent $u, string $volba): void
    {
        if (! Tabulky::je('event_reminders')) {
            return;
        }

        /*
         * Jen vlastní připomínka dialogu — ne ty, které k události založila
         * cesta, večer vzpomínek nebo automatizace (mají `automation_key`).
         */
        $vlastni = fn () => DB::table('event_reminders')->where('event_id', $u->id)
            ->when(Tabulky::sloupec('event_reminders', 'automation_key'), fn ($q) => $q->whereNull('automation_key'));

        if ($volba === '') {
            $vlastni()->where('status', 'pending')->delete();

            return;
        }

        $zacatek = CarbonImmutable::parse($u->starts_at);

        $kdy = match ($volba) {
            'týden předem' => $zacatek->subWeek(),
            'den předem' => $zacatek->subDay(),
            // „Ráno v den události“ — v sedm, ne v okamžik začátku.
            default => $zacatek->startOfDay()->addHours(7),
        };

        /*
         * Připomínka na tentýž okamžik už je — i doručená.
         *
         * Každé uložení kalendáře mazalo čekající a zakládalo novou. U doručené
         * tak vznikla další čekající s okamžikem v minulosti a plánovač ji
         * poslal znovu: úprava jedné události rozeslala oběma připomínky všech
         * událostí za poslední čtvrtrok.
         */
        if ($vlastni()->where('remind_at', $kdy)->exists()) {
            $vlastni()->where('status', 'pending')->where('remind_at', '!=', $kdy)->delete();

            return;
        }

        $vlastni()->where('status', 'pending')->delete();

        // Na událost, která už začala, se nepřipomíná.
        if ($zacatek->isPast()) {
            return;
        }

        /*
         * Připomínka patří tomu, koho se akce týká.
         *
         * Zakládala se vždy na `created_by`, takže když jeden zapsal druhému
         * zubaře a nastavil „den předem", přišla připomínka **jemu** — a tomu
         * druhému nic. Kdo akci zapsal, se přitom nikde neslibuje; obrazovka
         * se ptá „koho se to týká" a odpověď leží v `event_participants`.
         * U společné akce jsou to oba.
         */
        foreach ($this->komuPripomenout($u) as $komu) {
            DB::table('event_reminders')->insert([
                'event_id' => $u->id,
                'user_id' => $komu,
                'channel' => 'database',
                'remind_at' => $kdy,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Účastníci akce; bez nich ten, kdo ji zapsal.
     *
     * @return list<int>
     */
    private function komuPripomenout(CalendarEvent $u): array
    {
        $ucastnici = Tabulky::je('event_participants')
            ? DB::table('event_participants')->where('event_id', $u->id)->pluck('user_id')->all()
            : [];

        $ucastnici = array_values(array_unique(array_map('intval', array_filter($ucastnici))));

        return $ucastnici !== [] ? $ucastnici : array_values(array_filter([(int) $u->created_by]));
    }

    /**
     * Odškrtnuté události.
     *
     * @param  array<string, mixed>  $mapa
     */
    private function zapisOdskrtnuti(array $mapa, GallerySpace $prostor): void
    {
        /*
         * Jen to, co v mapě je.
         *
         * Mapa v prohlížeči začíná prázdná a plní se odškrtáváním; dřív se
         * každá událost z okna, která v ní chyběla, vrátila na „naplánováno" —
         * první odškrtnutí tak odznačilo všechno hotové za čtvrt roku.
         */
        foreach ($mapa as $id => $ano) {
            $u = $this->udalost((string) $id, $prostor);
            $ma = $ano ? 'completed' : 'planned';

            // Cizí stavy (`cancelled`, `confirmed`) se nepřepisují — prototyp
            // o nich neví a nemá je proč rušit.
            if (! $u || $u->status === $ma || ! in_array($u->status, ['planned', 'completed'], true)) {
                continue;
            }

            $u->update(['status' => $ma]);
        }
    }

    // ——— nástěnka úkolů ———

    /**
     * Nástěnka.
     *
     * Klient posílá `{ klíč nástěnky: [sloupce] }`, kde poslední sloupec je
     * „Hotovo". Sloupce jsou u skutečných dat **odvozené z termínu**, takže
     * přesun mezi nimi je změna termínu — a ta se musí zapsat, jinak by se
     * karta po obnovení vrátila tam, odkud ji někdo přetáhl.
     *
     * @param  array<string, mixed>  $nastenky
     * @param  list<string>|null  $zmenene
     */
    private function zapisNastenku(array $nastenky, GallerySpace $prostor, User $kdo, ?array $zmenene): void
    {
        foreach ($nastenky as $klic => $sloupce) {
            if (! is_array($sloupce)) {
                continue;
            }

            $this->zapisSloupce((string) $klic, $sloupce, $prostor, $kdo, $zmenene);
        }
    }

    /**
     * Úkoly jedné nástěnky.
     *
     * Zapisuje se jen to, co prohlížeč označil za změněné (`xBoardZmenene`);
     * starší klient bez rozdílu posílá celou nástěnku. Nový úkol se pamatuje
     * pod identifikátorem z prohlížeče, takže další odeslání ho nezaloží
     * znovu. Mazání je jen výslovné — viz zrusUkoly(). Dřív se rušilo všechno,
     * co v odeslané nástěnce chybělo: úkoly nad limitem nástěnky, úkoly
     * přidané mezitím druhým a úkol založený o chvíli dřív na nástěnce
     * domácnosti.
     *
     * @param  array<int, mixed>  $sloupce
     * @param  list<string>|null  $zmenene
     */
    private function zapisSloupce(string $klic, array $sloupce, GallerySpace $prostor, User $kdo, ?array $zmenene): void
    {
        $radky = [];

        foreach ($sloupce as $sloupec) {
            if (! is_array($sloupec)) {
                continue;
            }

            foreach ((array) ($sloupec['items'] ?? []) as $u) {
                if (is_array($u) && isset($u['id'], $u['t'])) {
                    $radky[] = [$u, (string) ($sloupec['label'] ?? ''), (bool) ($sloupec['done'] ?? false)];
                }
            }
        }

        /*
         * Ukázková nástěnka se nezapisuje.
         *
         * Pozná se podle ukázkového řádku (`all0-3`), ne podle toho, že v ní
         * chybí úkol z databáze — tak se dřív nezapsal ani první úkol dvojice,
         * která žádný ještě neměla.
         */
        foreach ($radky as [$u]) {
            if (preg_match(self::UKAZKOVY_UKOL, (string) $u['id']) && ! $this->ukol((string) $u['id'], $prostor)) {
                return;
            }
        }

        foreach ($radky as [$u, $nazev, $hotovo]) {
            $id = (string) $u['id'];

            if ($zmenene !== null && ! in_array($id, $zmenene, true)) {
                continue;
            }

            $ukol = $this->ukol($id, $prostor);

            if ($ukol) {
                $this->upravUkol($ukol, $u, $nazev, $hotovo, $prostor, $kdo);

                continue;
            }

            // Identifikátor, který vypadá jako z databáze a v ní není, je
            // smazaný úkol ze starší kopie nástěnky — nezakládá se znovu.
            if (! Str::isUuid($id)) {
                $this->zalozUkol($u, $nazev, $hotovo, $prostor, $kdo, $klic, $id);
            }
        }
    }

    /**
     * Úkoly, které prohlížeč z nástěnky výslovně odebral.
     *
     * Otevřený se zruší (zůstane v historii). Hotový se jen archivuje: „Uklidit
     * hotové" slibuje, že úkoly zůstanou v záložce Hotovo, a zrušený by z ní
     * zmizel.
     *
     * @param  list<string>  $idcka
     */
    private function zrusUkoly(array $idcka, GallerySpace $prostor): void
    {
        foreach ($idcka as $id) {
            $u = $this->ukol($id, $prostor);

            if (! $u || $u->status === 'cancelled') {
                continue;
            }

            if ($u->status === 'completed') {
                $u->update(['metadata' => array_merge((array) $u->metadata, ['archivovano' => true])]);
            } else {
                $u->update(['status' => 'cancelled']);
            }
        }
    }

    /** Úkol podle identifikátoru z prohlížeče: uuid, nebo založený z `all-n1`. */
    private function ukol(string $id, GallerySpace $prostor): ?SharedTodo
    {
        if (Str::isUuid($id)) {
            return SharedTodo::where('gallery_space_id', $prostor->id)->where('uuid', $id)->first();
        }

        return SharedTodo::where('gallery_space_id', $prostor->id)
            ->where('metadata->klient_id', $id)
            ->where('created_at', '>=', now()->subDays(self::DNU_KLIENT))
            ->latest('id')
            ->first();
    }

    /**
     * Identifikátory z rozdílu, který poslal prohlížeč.
     *
     * @return list<string>|null null = rozdíl nepřišel (starší klient)
     */
    private function idcka(mixed $hodnota): ?array
    {
        if (! is_array($hodnota)) {
            return null;
        }

        return array_values(array_filter(array_map(
            fn ($id) => is_scalar($id) ? mb_substr((string) $id, 0, 80) : '',
            array_slice($hodnota, 0, 500),
        ), fn (string $id) => $id !== ''));
    }

    /** @param  array<string, mixed>  $r */
    private function upravUkol(SharedTodo $u, array $r, string $sloupec, bool $hotovo, GallerySpace $prostor, User $kdo): void
    {
        $zmeny = [
            'title' => Vejde::do($r['t']),
            'description' => ($r['note'] ?? '') !== '' ? $r['note'] : null,
            'assigned_to' => $this->kdoMa($r, $prostor),
            'priority' => $this->priorita($r),
            'due_at' => $this->termin($u, $r, $sloupec),
            'recurrence' => $this->opakovani((string) ($r['rep'] ?? '')),
        ];

        $maBytHotovy = $hotovo || (bool) ($r['on'] ?? false);
        $jeHotovy = $u->status === 'completed';

        // Zrušený úkol, který je zase na nástěnce, vrátilo tlačítko Zpět.
        if ($maBytHotovy !== $jeHotovy || $u->status === 'cancelled') {
            $zmeny['status'] = $maBytHotovy ? 'completed' : 'open';
            $zmeny['completed_at'] = $maBytHotovy ? ($u->completed_at ?? now()) : null;
            $zmeny['completed_by'] = $maBytHotovy ? ($u->completed_by ?? $kdo->id) : null;
        }

        if (is_array($u->metadata) && ! empty($u->metadata['archivovano'])) {
            $zmeny['metadata'] = array_diff_key($u->metadata, ['archivovano' => true]);
        }

        $u->update($zmeny);
    }

    /** @param  array<string, mixed>  $r */
    private function zalozUkol(array $r, string $sloupec, bool $hotovo, GallerySpace $prostor, User $kdo, string $klic, string $klientId): void
    {
        SharedTodo::create([
            'gallery_space_id' => $prostor->id,
            'created_by' => $kdo->id,
            'assigned_to' => $this->kdoMa($r, $prostor),
            'list_id' => $klic === 'home' ? $this->domaciSeznam($prostor, $kdo) : $this->seznamKategorie($klic, $prostor),
            'title' => Vejde::do($r['t']),
            'description' => ($r['note'] ?? '') !== '' ? $r['note'] : null,
            'status' => $hotovo ? 'completed' : 'open',
            'priority' => $this->priorita($r),
            // Popisek („dnes", „pátek") má přednost; dřív se zahodil a každý nový
            // úkol v „Tento týden" dostal konec týdne — po obnovení byl v „Později".
            'due_at' => $this->terminZeSloupce($this->zPopisku((string) ($r['d'] ?? ''), null), (string) ($r['d'] ?? ''), $sloupec),
            'recurrence' => $this->opakovani((string) ($r['rep'] ?? '')),
            'completed_at' => $hotovo ? now() : null,
            'completed_by' => $hotovo ? $kdo->id : null,
            'metadata' => ['source' => 'prototyp', 'klient_id' => $klientId],
        ]);
    }

    /**
     * Termín po zásahu na nástěnce.
     *
     * Popisek („čtvrtek", „bez termínu") je to jediné, co prototyp posílá, ale
     * server k němu přiložil i datum. Když se popisek nezměnil, drží se datum;
     * když ano, přeloží se. A když karta skončila v jiném sloupci, rozhoduje
     * sloupec — u odvozených sloupců je přesun tím, čím termín je.
     *
     * @param  array<string, mixed>  $r
     */
    private function termin(SharedTodo $u, array $r, string $sloupec): ?CarbonImmutable
    {
        $puvodni = $u->due_at ? CarbonImmutable::parse($u->due_at) : null;
        $popisek = trim((string) ($r['d'] ?? ''));

        // Popisek, který server sám vyrobil → termín se nemění.
        $zeServeru = isset($r['due']) && $r['due']
            ? CarbonImmutable::parse((string) $r['due'])
            : $puvodni;

        $bezeZmeny = $zeServeru !== null && $popisek === $this->popisek($zeServeru);

        /*
         * Beze změny se drží **uložený čas**, ne datum z popisku.
         *
         * Klientovi se posílá jen den (`2026-09-09`); vrátit ho zpátky by
         * z „do dvanácti" udělalo půlnoc, aniž by o to někdo požádal.
         */
        $zachovany = $bezeZmeny
            ? ($puvodni && $puvodni->isSameDay($zeServeru) ? $puvodni : $zeServeru)
            : $this->zPopisku($popisek, $puvodni);

        return $this->terminZeSloupce($zachovany, $popisek, $sloupec);
    }

    /**
     * Sloupec má poslední slovo.
     *
     * „Někdy" znamená bez termínu, „Tento týden" nejpozději za týden a „Později"
     * až po něm. Kdyby se přesun nezapsal, karta by po obnovení skočila zpátky.
     */
    private function terminZeSloupce(?CarbonImmutable $termin, string $popisek, string $sloupec): ?CarbonImmutable
    {
        $tyden = Cas::dnes()->addWeek()->endOfDay();

        return match ($sloupec) {
            Planovani::NEKDY => null,
            Planovani::TENTO_TYDEN => $termin && $termin->lte($tyden) ? $termin : $tyden,
            Planovani::POZDEJI => $termin && $termin->gt($tyden) ? $termin : $tyden->addWeek(),
            // Sloupec „Hotovo" ani vlastní kategorie termín neurčují.
            default => $termin,
        };
    }

    /** Popisek termínu tak, jak ho tvoří poskytovatel — kvůli porovnání. */
    private function popisek(CarbonImmutable $den): string
    {
        $den = $den->startOfDay();
        $dnes = Cas::dnes();
        $rozdil = (int) $dnes->diffInDays($den, false);

        return match (true) {
            $rozdil < 0 => 'po termínu',
            $rozdil === 0 => 'dnes',
            $rozdil === 1 => 'zítra',
            $rozdil <= 6 => ['neděli', 'pondělí', 'úterý', 'středu', 'čtvrtek', 'pátek', 'sobotu'][$den->dayOfWeek],
            default => 'do '.$den->format('j. n.'),
        };
    }

    /**
     * Popisek termínu zpátky na datum.
     *
     * Pole je volný text, takže se překládá jen to, co prototyp sám nabízí.
     * Co se přeložit nedá, nechá termín být — vymýšlet datum z věty by bylo
     * horší než ho nezměnit.
     */
    private function zPopisku(string $popisek, ?CarbonImmutable $puvodni): ?CarbonImmutable
    {
        $t = mb_strtolower(trim($popisek));
        // „Dnes" a „zítra" podle hodin dvojice, stejně jako popisek z poskytovatele.
        $dnes = Cas::dnes();

        if ($t === '' || str_contains($t, 'bez termínu')) {
            return null;
        }

        if (str_contains($t, 'dnes')) {
            return $dnes->endOfDay();
        }

        if (str_contains($t, 'zítra')) {
            return $dnes->addDay()->endOfDay();
        }

        if (str_contains($t, 'za týden')) {
            return $dnes->addWeek()->endOfDay();
        }

        if (str_contains($t, 'za měsíc')) {
            return $dnes->addMonth()->endOfDay();
        }

        $dny = [
            'pondělí' => CarbonImmutable::MONDAY, 'úterý' => CarbonImmutable::TUESDAY,
            'střed' => CarbonImmutable::WEDNESDAY, 'čtvrtek' => CarbonImmutable::THURSDAY,
            'pátek' => CarbonImmutable::FRIDAY, 'sobot' => CarbonImmutable::SATURDAY,
            'neděl' => CarbonImmutable::SUNDAY,
        ];

        foreach ($dny as $slovo => $cislo) {
            if (str_contains($t, $slovo)) {
                return $dnes->next($cislo)->endOfDay();
            }
        }

        if (preg_match('/(\d{1,2})\.\s*(\d{1,2})\.?/u', $t, $shoda)) {
            $rok = $dnes->year;
            $den = CarbonImmutable::createFromDate($rok, (int) $shoda[2], (int) $shoda[1])->endOfDay();

            // Datum, které už letos bylo, se myslí příští rok.
            return $den->lt($dnes->startOfDay()) ? $den->addYear() : $den;
        }

        // „Po termínu" a všechno ostatní: termín zůstává, jaký byl.
        return $puvodni;
    }

    /** @param  array<string, mixed>  $r */
    private function kdoMa(array $r, GallerySpace $prostor): ?int
    {
        $kdo = (string) ($r['w'] ?? 'spolu');

        if ($kdo === 'spolu' || $kdo === '') {
            return null;
        }

        return $prostor->members()->where('users.name', $kdo)->value('users.id');
    }

    /** @param  array<string, mixed>  $r */
    private function priorita(array $r): string
    {
        return match ((int) ($r['pr'] ?? 0)) {
            2 => 'high',
            1 => 'low',
            default => 'normal',
        };
    }

    /** @return array<string, mixed>|null */
    private function opakovani(string $rep): ?array
    {
        return match ($rep) {
            'denně' => ['frequency' => 'daily', 'interval' => 1],
            'týdně' => ['frequency' => 'weekly', 'interval' => 1],
            'měsíčně' => ['frequency' => 'monthly', 'interval' => 1],
            default => null,
        };
    }

    /** Seznam úkolů za nástěnkou kategorie (`seznam-<uuid>`); jinak žádný. */
    private function seznamKategorie(string $klic, GallerySpace $prostor): ?int
    {
        if (! str_starts_with($klic, 'seznam-') || ! Tabulky::je('shared_todo_lists')) {
            return null;
        }

        $id = DB::table('shared_todo_lists')
            ->where('gallery_space_id', $prostor->id)
            ->where('uuid', substr($klic, strlen('seznam-')))
            ->where('kind', Planovani::DRUH_KATEGORIE)
            ->whereNull('archived_at')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    private function domaciSeznam(GallerySpace $prostor, User $kdo): ?int
    {
        if (! Tabulky::je('shared_todo_lists')) {
            return null;
        }

        $seznam = DB::table('shared_todo_lists')
            ->where('gallery_space_id', $prostor->id)
            ->where(fn ($q) => $q->where('kind', 'household')->orWhereRaw('LOWER(title) = ?', ['domácnost']))
            ->first(['id']);

        if ($seznam) {
            return (int) $seznam->id;
        }

        return (int) DB::table('shared_todo_lists')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $prostor->id,
            'created_by' => $kdo->id,
            'title' => 'Domácnost',
            'kind' => 'household',
            'color' => '#14b8a6',
            'icon' => '🏠',
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ——— až budeme mít čas ———

    /**
     * Seznam „až budeme mít čas".
     *
     * Jsou to tytéž úkoly bez termínu, jen se na ně obrazovka dívá jinak.
     * „Jde se to udělat" je proto termín na tento týden, „propuštěno" je zrušení.
     *
     * @param  array<int, mixed>  $radky
     */
    private function zapisNekdy(array $radky, GallerySpace $prostor, User $kdo): void
    {
        $vDatabazi = SharedTodo::where('gallery_space_id', $prostor->id)
            ->whereNull('due_at')
            ->get()
            ->keyBy('uuid');

        // Ukázkový seznam (`w1`…) se nezapisuje. Dřív se místo toho čekalo na
        // první věc v databázi — a první věc dvojice se tak nezapsala nikdy.
        foreach ($radky as $r) {
            if (is_array($r) && preg_match('/^w\d{1,5}$/', (string) ($r['id'] ?? ''))) {
                return;
            }
        }

        foreach ($radky as $r) {
            if (! is_array($r) || ! isset($r['id'], $r['text'])) {
                continue;
            }

            $stav = (string) ($r['state'] ?? 'open');
            $u = $vDatabazi[$r['id']] ?? null;

            // Nová věc se pamatuje pod identifikátorem z prohlížeče; další
            // odeslání seznamu ji dřív založilo znovu.
            if (! $u && preg_match('/^w\d{6,}$/', (string) $r['id'])) {
                $u = $this->ukol((string) $r['id'], $prostor);
            }

            if ($u) {
                $u->update(match ($stav) {
                    'done' => [
                        'title' => Vejde::do($r['text']),
                        // „Jde se to udělat“ — přesouvá se mezi úkoly na tento týden.
                        // Jednou: seznam chodí celý a termín by se jinak posouval.
                        'due_at' => $u->due_at ?? Cas::dnes()->addWeek()->endOfDay(),
                        'status' => 'open',
                    ],
                    'dropped' => ['title' => Vejde::do($r['text']), 'status' => 'cancelled'],
                    default => ['title' => Vejde::do($r['text'])],
                });

                continue;
            }

            if (preg_match('/^w\d{6,}$/', (string) $r['id'])) {
                SharedTodo::create([
                    'gallery_space_id' => $prostor->id,
                    'created_by' => $kdo->id,
                    'title' => Vejde::do($r['text']),
                    'status' => $stav === 'dropped' ? 'cancelled' : 'open',
                    'priority' => 'normal',
                    'metadata' => ['source' => 'prototyp', 'klient_id' => (string) $r['id']],
                ]);
            }
        }
    }

    // ——— překlady ———

    /** Z `ev-<uuid>` na uuid; z `ev0` a `ev-n1` nic. */
    private function uuidUdalosti(string $id): ?string
    {
        if (! str_starts_with($id, 'ev-')) {
            return null;
        }

        $zbytek = substr($id, 3);

        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $zbytek)
            ? $zbytek
            : null;
    }

    /** @param  array<string, mixed>  $e */
    private function zacatek(array $e): ?CarbonImmutable
    {
        if (! isset($e['y'], $e['m'], $e['d'])) {
            return null;
        }

        // Prototyp počítá měsíce od nuly.
        $den = CarbonImmutable::create((int) $e['y'], (int) $e['m'] + 1, (int) $e['d']);

        if (! $den) {
            return null;
        }

        $cas = (string) ($e['time'] ?? '');

        if (preg_match('/^(\d{1,2}):(\d{2})$/', $cas, $shoda)) {
            return $den->setTime((int) $shoda[1], (int) $shoda[2]);
        }

        return $den->startOfDay();
    }

    private function typ(string $druh): string
    {
        return match ($druh) {
            'cesta' => 'trip',
            'kultura' => 'outing',
            'oslava' => 'birthday',
            'platba' => 'reservation',
            default => 'event',
        };
    }

    /**
     * Co to byla za společnou věc.
     *
     * Nepovinné a jiné než `type`: ten popisuje chování události v kalendáři,
     * tohle druh společně stráveného času. Účet radosti podle něj počítá,
     * co doopravdy vyrobilo dobré dny — bez toho se seskupovat nemá podle čeho.
     *
     * Prázdné je `null`, ne prázdný řetězec: „nezařazeno" a „zařazeno do
     * ničeho" musí jít od sebe rozeznat.
     *
     * @param  array<string, mixed>  $e
     */
    private function cinnost(array $e): ?string
    {
        $druh = trim((string) ($e['act'] ?? ''));

        return $druh === '' ? null : mb_substr($druh, 0, 60);
    }

    /** @param  array<string, mixed>  $e */
    private function albumId(array $e, GallerySpace $prostor): ?int
    {
        $uuid = (string) ($e['album'] ?? '');

        if ($uuid === '' || ! Tabulky::je('albums')) {
            return null;
        }

        return DB::table('albums')
            ->where('gallery_space_id', $prostor->id)
            ->where('uuid', $uuid)
            ->value('id');
    }
}
