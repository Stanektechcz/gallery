<?php

namespace App\Services\Provoz;

use App\Models\CalendarEvent;
use App\Models\GallerySpace;
use App\Models\SharedTodo;
use App\Models\User;
use App\Services\Obsah\Planovani;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
    /** Klíče, které patří databázi. Do stavu se neukládají. */
    public const SERVEROVE = ['evList', 'evDoneMap', 'xBoard', 'hsLater'];

    /** Jak dlouhé okno událostí posílá poskytovatel; mimo něj se nemaže. */
    private const DNU_ZPET = 90;

    private const DNU_VPRED = 400;

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
            $this->zapisUdalosti($patch['evList'], $patch['evDoneMap'] ?? null, $prostor, $kdo);
        } elseif (is_array($patch['evDoneMap'] ?? null)) {
            // Odškrtnutí přijde samo, bez seznamu událostí.
            $this->zapisOdskrtnuti($patch['evDoneMap'], $prostor);
        }

        if (is_array($patch['xBoard'] ?? null)) {
            $this->zapisNastenku($patch['xBoard'], $prostor, $kdo);
        }

        if (is_array($patch['hsLater'] ?? null)) {
            $this->zapisNekdy($patch['hsLater'], $prostor, $kdo);
        }
    }

    // ——— kalendář ———

    /**
     * Události z kalendáře.
     *
     * Prototyp posílá celý seznam. Zapisuje se z něj to, co má identifikátor
     * skutečné události (úprava), a to, co si prototyp právě vyrobil (nová
     * událost). Napsané ukázkové řádky — `ev0`, `ev1` — se ignorují: nejsou
     * to události dvojice a v jejím kalendáři nemají co dělat.
     *
     * @param  array<int, mixed>  $radky
     * @param  array<string, mixed>|null  $odskrtnute
     */
    private function zapisUdalosti(array $radky, ?array $odskrtnute, GallerySpace $prostor, User $kdo): void
    {
        $vOkne = $this->udalostiVOkne($prostor);
        $zustaly = [];

        foreach ($radky as $e) {
            if (! is_array($e) || ! isset($e['id'], $e['t'])) {
                continue;
            }

            $uuid = $this->uuidUdalosti((string) $e['id']);

            if ($uuid !== null && $vOkne->has($uuid)) {
                $zustaly[] = $uuid;
                $this->uprav($vOkne[$uuid], $e, $prostor);

                continue;
            }

            // Nová událost pozná prototyp podle vlastní předpony (`ev-n`, `ev-d`,
            // `ev-s`, `ev-m`); ukázkový řádek žádnou nemá.
            if ($uuid === null && preg_match('/^ev-[a-z]\d/', (string) $e['id'])) {
                $this->zaloz($e, $prostor, $kdo);
            }
        }

        /*
         * Smazané: byly v okně, které server poslal, a v seznamu už nejsou.
         *
         * Mimo okno se nemaže nic. Klient může mít v paměti starší seznam
         * z doby, kdy okno leželo jinde, a jeho odesláním by jinak zmizely
         * události, na které se nikdo ani nepodíval.
         */
        $vOkne
            ->reject(fn (CalendarEvent $u) => in_array($u->uuid, $zustaly, true))
            ->each(fn (CalendarEvent $u) => $u->delete());

        if (is_array($odskrtnute)) {
            $this->zapisOdskrtnuti($odskrtnute, $prostor);
        }
    }

    /** @return Collection<string, CalendarEvent> */
    private function udalostiVOkne(GallerySpace $prostor): Collection
    {
        return CalendarEvent::where('gallery_space_id', $prostor->id)
            ->where('is_private', false)
            ->whereBetween('starts_at', [
                CarbonImmutable::now()->subDays(self::DNU_ZPET),
                CarbonImmutable::now()->addDays(self::DNU_VPRED),
            ])
            ->get()
            ->keyBy('uuid');
    }

    /** @param  array<string, mixed>  $e */
    private function uprav(CalendarEvent $u, array $e, GallerySpace $prostor): void
    {
        $zacatek = $this->zacatek($e);

        if (! $zacatek) {
            return;
        }

        $u->update([
            'title' => (string) $e['t'],
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
    private function zaloz(array $e, GallerySpace $prostor, User $kdo): void
    {
        $zacatek = $this->zacatek($e);

        if (! $zacatek) {
            return;
        }

        $u = CalendarEvent::create([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $prostor->id,
            'created_by' => $kdo->id,
            'title' => (string) $e['t'],
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
            'metadata' => ['source' => 'prototyp'],
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
        if (! Schema::hasTable('event_participants')) {
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
        if (! Schema::hasTable('event_reminders')) {
            return;
        }

        DB::table('event_reminders')->where('event_id', $u->id)->where('status', 'pending')->delete();

        if ($volba === '') {
            return;
        }

        $zacatek = CarbonImmutable::parse($u->starts_at);

        $kdy = match ($volba) {
            'týden předem' => $zacatek->subWeek(),
            'den předem' => $zacatek->subDay(),
            // „Ráno v den události“ — v sedm, ne v okamžik začátku.
            default => $zacatek->startOfDay()->addHours(7),
        };

        DB::table('event_reminders')->insert([
            'event_id' => $u->id,
            'user_id' => $u->created_by,
            'channel' => 'database',
            'remind_at' => $kdy,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Odškrtnuté události.
     *
     * @param  array<string, mixed>  $mapa
     */
    private function zapisOdskrtnuti(array $mapa, GallerySpace $prostor): void
    {
        $hotove = [];

        foreach ($mapa as $id => $ano) {
            $uuid = $this->uuidUdalosti((string) $id);

            if ($ano && $uuid !== null) {
                $hotove[] = $uuid;
            }
        }

        $vOkne = $this->udalostiVOkne($prostor);

        $vOkne->each(function (CalendarEvent $u) use ($hotove) {
            $ma = in_array($u->uuid, $hotove, true) ? 'completed' : 'planned';

            // Cizí stavy (`cancelled`, `confirmed`) se nepřepisují — prototyp
            // o nich neví a nemá je proč rušit.
            if ($u->status === $ma || ! in_array($u->status, ['planned', 'completed'], true)) {
                return;
            }

            $u->update(['status' => $ma]);
        });
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
     */
    private function zapisNastenku(array $nastenky, GallerySpace $prostor, User $kdo): void
    {
        foreach ($nastenky as $klic => $sloupce) {
            if (! is_array($sloupce)) {
                continue;
            }

            $this->zapisSloupce((string) $klic, $sloupce, $prostor, $kdo);
        }
    }

    /**
     * @param  array<int, mixed>  $sloupce
     */
    private function zapisSloupce(string $klic, array $sloupce, GallerySpace $prostor, User $kdo): void
    {
        $vDatabazi = SharedTodo::where('gallery_space_id', $prostor->id)
            ->where('status', '!=', 'cancelled')
            ->get()
            ->keyBy('uuid');

        // Nástěnka, ve které není jediný skutečný úkol, je pořád ta ukázková.
        // Zakládat z ní by znamenalo naplnit modul vymyšlenými řádky.
        $znama = false;

        foreach ($sloupce as $sloupec) {
            foreach ((array) ($sloupec['items'] ?? []) as $u) {
                if (is_array($u) && isset($u['id']) && $vDatabazi->has($u['id'])) {
                    $znama = true;
                    break 2;
                }
            }
        }

        $zustaly = [];

        foreach ($sloupce as $sloupec) {
            if (! is_array($sloupec)) {
                continue;
            }

            $hotovo = (bool) ($sloupec['done'] ?? false);
            $nazev = (string) ($sloupec['label'] ?? '');

            foreach ((array) ($sloupec['items'] ?? []) as $u) {
                if (! is_array($u) || ! isset($u['id'], $u['t'])) {
                    continue;
                }

                if ($vDatabazi->has($u['id'])) {
                    $zustaly[] = (string) $u['id'];
                    $this->upravUkol($vDatabazi[$u['id']], $u, $nazev, $hotovo, $prostor, $kdo);

                    continue;
                }

                // Nový úkol pozná prototyp podle vlastní předpony (`-n`, `-r`).
                if ($znama && preg_match('/-[nr]\d+$/', (string) $u['id'])) {
                    $this->zalozUkol($u, $nazev, $hotovo, $prostor, $kdo, $klic);
                }
            }
        }

        /*
         * Smazané: byly na nástěnce a v seznamu už nejsou.
         *
         * Jen když je nástěnka skutečná — a jen úkoly, které do ní patří.
         * Zrušené se **nemažou**, dostanou stav; „uklidit hotové" nemá znamenat
         * ztrátu historie.
         */
        if (! $znama) {
            return;
        }

        $vDatabazi
            ->filter(fn (SharedTodo $u) => $this->patriNaNastenku($u, $klic))
            ->reject(fn (SharedTodo $u) => in_array($u->uuid, $zustaly, true))
            ->each(fn (SharedTodo $u) => $u->update(['status' => 'cancelled']));
    }

    /**
     * Patří úkol na tuhle nástěnku?
     *
     * `all` je všechno, `home` jen to, co je v domácím seznamu — přesně jak to
     * skládá poskytovatel. Bez toho by zásah na nástěnce domácnosti zrušil
     * všechno ostatní.
     */
    private function patriNaNastenku(SharedTodo $u, string $klic): bool
    {
        if ($klic !== 'home') {
            return true;
        }

        if (! $u->list_id || ! Schema::hasTable('shared_todo_lists')) {
            return false;
        }

        $seznam = DB::table('shared_todo_lists')->where('id', $u->list_id)->first(['title', 'kind']);

        return $seznam && ($seznam->kind === 'household'
            || mb_strtolower((string) $seznam->title) === 'domácnost');
    }

    /** @param  array<string, mixed>  $r */
    private function upravUkol(SharedTodo $u, array $r, string $sloupec, bool $hotovo, GallerySpace $prostor, User $kdo): void
    {
        $zmeny = [
            'title' => (string) $r['t'],
            'description' => ($r['note'] ?? '') !== '' ? $r['note'] : null,
            'assigned_to' => $this->kdoMa($r, $prostor),
            'priority' => $this->priorita($r),
            'due_at' => $this->termin($u, $r, $sloupec),
            'recurrence' => $this->opakovani((string) ($r['rep'] ?? '')),
        ];

        $maBytHotovy = $hotovo || (bool) ($r['on'] ?? false);
        $jeHotovy = $u->status === 'completed';

        if ($maBytHotovy !== $jeHotovy) {
            $zmeny['status'] = $maBytHotovy ? 'completed' : 'open';
            $zmeny['completed_at'] = $maBytHotovy ? now() : null;
            $zmeny['completed_by'] = $maBytHotovy ? $kdo->id : null;
        }

        $u->update($zmeny);
    }

    /** @param  array<string, mixed>  $r */
    private function zalozUkol(array $r, string $sloupec, bool $hotovo, GallerySpace $prostor, User $kdo, string $klic): void
    {
        SharedTodo::create([
            'gallery_space_id' => $prostor->id,
            'created_by' => $kdo->id,
            'assigned_to' => $this->kdoMa($r, $prostor),
            'list_id' => $klic === 'home' ? $this->domaciSeznam($prostor, $kdo) : null,
            'title' => (string) $r['t'],
            'description' => ($r['note'] ?? '') !== '' ? $r['note'] : null,
            'status' => $hotovo ? 'completed' : 'open',
            'priority' => $this->priorita($r),
            'due_at' => $this->terminZeSloupce(null, (string) ($r['d'] ?? ''), $sloupec),
            'recurrence' => $this->opakovani((string) ($r['rep'] ?? '')),
            'completed_at' => $hotovo ? now() : null,
            'completed_by' => $hotovo ? $kdo->id : null,
            'metadata' => ['source' => 'prototyp'],
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
        $tyden = CarbonImmutable::now()->addWeek()->endOfDay();

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
        $dnes = CarbonImmutable::now()->startOfDay();
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
        $dnes = CarbonImmutable::now();

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

    private function domaciSeznam(GallerySpace $prostor, User $kdo): ?int
    {
        if (! Schema::hasTable('shared_todo_lists')) {
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

        foreach ($radky as $r) {
            if (! is_array($r) || ! isset($r['id'], $r['text'])) {
                continue;
            }

            $stav = (string) ($r['state'] ?? 'open');

            if ($vDatabazi->has($r['id'])) {
                $u = $vDatabazi[$r['id']];

                $u->update(match ($stav) {
                    'done' => [
                        'title' => (string) $r['text'],
                        // „Jde se to udělat“ — přesouvá se mezi úkoly na tento týden.
                        'due_at' => CarbonImmutable::now()->addWeek()->endOfDay(),
                        'status' => 'open',
                    ],
                    'dropped' => ['title' => (string) $r['text'], 'status' => 'cancelled'],
                    default => ['title' => (string) $r['text']],
                });

                continue;
            }

            // Nová věc na seznam. Ukázkové řádky (`w1`…) se ignorují.
            if ($vDatabazi->isNotEmpty() && preg_match('/^w\d{6,}$/', (string) $r['id'])) {
                SharedTodo::create([
                    'gallery_space_id' => $prostor->id,
                    'created_by' => $kdo->id,
                    'title' => (string) $r['text'],
                    'status' => $stav === 'dropped' ? 'cancelled' : 'open',
                    'priority' => 'normal',
                    'metadata' => ['source' => 'prototyp'],
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

        if ($uuid === '' || ! Schema::hasTable('albums')) {
            return null;
        }

        return DB::table('albums')
            ->where('gallery_space_id', $prostor->id)
            ->where('uuid', $uuid)
            ->value('id');
    }
}
