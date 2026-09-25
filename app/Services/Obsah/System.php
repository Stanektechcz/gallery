<?php

namespace App\Services\Obsah;

use App\Models\Budget;
use App\Models\FinanceAccess;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\StorageConnection;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Auth\PristupDoGalerie;
use App\Services\Finance\ExchangeRateService;
use App\Services\Finance\LedgerService;
use App\Services\Media\MazaniFotek;
use App\Services\Notifications\NotificationPreferenceService;
use App\Services\Provoz\AdministraceGalerie;
use App\Services\Provoz\PlanovaneUlohy;
use App\Services\Provoz\UlozisteGalerie;
use App\Services\Storage\DriveConnectionResolver;
use App\Support\Cas;
use App\Support\Meny;
use App\Support\SpaceContext;
use App\Support\Tabulky;
use App\Support\Trezor;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Co aplikace ví o sobě: jak tvrdá jsou její čísla, kdo které sekce živí
 * a jak je na tom úložiště.
 *
 * Tady je ukázka nejhorší ze všech obrazovek. „Zdraví dat" je jediné místo,
 * kde aplikace přiznává, čemu se dá věřit — a psalo se v něm o zůstatku
 * 38 412 Kč a portfoliu za 961 700 Kč, které dvojice nemá. Obrazovka, která
 * má měřit důvěryhodnost čísel, byla nejméně důvěryhodná z nich.
 *
 * Posílá se jen to, co má oporu: řádek o cyklu, když existují záznamy, řádek
 * o rozpočtu, když existuje rozpočet. Zbytek se **neposílá**, ne dopočítává.
 */
class System implements MaPrazdneKolekce, PoskytovatelObsahu
{
    /** Do kolika dnů bez zápisu se sekce považuje za živou. */
    private const ZIVA_DNI = 90;

    /** Kolik návrhů ke smazání obrazovka koše vypíše — stejně jako koš sám. */
    private const KE_SCHVALENI_NEJVIC = 60;

    /** `MAZANI` ve tvaru, který obrazovka čte, i bez dvojice. */
    private const MAZANI_PRAZDNE = [
        'rezim' => MazaniFotek::SPOLECNE,
        'muzuSam' => true,
        'partner' => null,
        'navrhRezimu' => null,
        'cekaNaMe' => 0,
        'cekaNaPartnera' => 0,
    ];

    /**
     * Sekce a tabulka, do které se v nich zapisuje.
     *
     * `[id, název, tabulka, sloupec autora, sloupec času]`
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    private const SEKCE = [
        ['l1', 'Deník', 'journal_entries', 'created_by', 'created_at'],
        ['l2', 'Finance a transakce', 'transactions', 'created_by', 'created_at'],
        ['l3', 'Kuchařka', 'recipes', 'created_by', 'created_at'],
        ['l4', 'Knihovna', 'media_items', 'uploaded_by', 'uploaded_at'],
        ['l5', 'Zprávy a hlasovky', 'chat_messages', 'created_by', 'created_at'],
        ['l6', 'Plánování a úkoly', 'shared_todos', 'created_by', 'created_at'],
        ['l7', 'Cesty a výlety', 'trips', 'created_by', 'created_at'],
        ['l8', 'Milníky a výročí', 'relationship_milestones', 'created_by', 'created_at'],
    ];

    public function __construct(
        private readonly UlozisteGalerie $uloziste,
        private readonly LedgerService $kniha,
        private readonly Formulare $formulare,
        private readonly DriveConnectionResolver $disky,
        // Účty, klíče a tarify už administrace umí spočítat; druhý výpočet
        // by znamenal dvě čísla o téže věci, která se časem rozejdou.
        private readonly AdministraceGalerie $sprava,
        private readonly PlanovaneUlohy $ulohy,
        private readonly NastaveniAplikace $nastaveni,
        private readonly MazaniFotek $mazaniFotek,
        // Součty přes měny (zůstatek, rozpočet, cesta) jdou do hlavní měny kurzem ECB.
        private readonly ExchangeRateService $kurzy,
    ) {}

    public function skupina(): string
    {
        return 'system';
    }

    /**
     * Obojí přichází celé.
     *
     * U zdraví dat je to podstata věci: nechat mezi skutečnými čísly jedno
     * ukázkové znamená, že obrazovka o důvěryhodnosti čísel sama lže. A sekce
     * se nabízejí ke skrytí — nabídnout skrytí sekce, kterou aplikace nemá,
     * je nesmysl.
     */
    public function uplne(): array
    {
        // `CONFLICTS` taky: vyřešený rozpor musí z obrazovky zmizet hned.
        // Bez toho by tam po kliknutí zůstal viset řádek, který v databázi
        // už otevřený není — a při dalším načtení by se „vrátil".
        //
        // A `LOCKWHO`/`LOCKMAIL`: v prostoru s jediným člověkem by jinak vedle
        // něj zůstala druhá ukázková volba i s cizí adresou.
        // `SETROWS` taky: sekce, kterou server nepošle (import, ticho), nemá u dvojice zůstat z ukázky.
        // `OZNAMENI`: přečtené oznámení musí ze zvonku zmizet i po obnovení.
        // `MAZANI` a `KE_SCHVALENI`: stažený či schválený návrh ke smazání
        // nesmí na obrazovce viset — klient v objektu jinak přepíše jen to, co přišlo.
        return ['DATA_HEALTH', 'SECLIFE', 'TRASH', 'CONFLICTS', 'LOCKWHO', 'LOCKMAIL', 'SETROWS', 'OZNAMENI', 'MAZANI', 'KE_SCHVALENI'];
    }

    /**
     * Prázdné kolekce pro modul, který dvojice zatím nepoužila.
     *
     * Bez nich zůstala na obrazovce ukázka z prototypu (viz MaPrazdneKolekce).
     *
     * @return array<string, mixed>
     */
    public function prazdne(): array
    {
        return [
            'CONFLICTS' => [],
            'DATA_HEALTH' => [],
            'SECLIFE' => [],
            'OZNAMENI' => [],
            'VAULT_ITEMS' => [],
            /*
             * `kolekce()` je posílá, `prazdne()` o nich mlčelo.
             *
             * Klient si na ně dosud dával pozor sám, ale mlčení tady znamená,
             * že se ukázka smaže jen tam, kam se prototyp nezapomněl podívat.
             * `AFORMS` jsou přepínače nastavení, `LOCKWHO` a `LOCKMAIL` kdo
             * se přihlašuje — u prázdné galerie prostě nikdo.
             */
            'AFORMS' => new \stdClass,
            'LOCKWHO' => '',
            'LOCKMAIL' => '',
            'ABARS' => ['health' => [], 'risk' => []],
            'AL' => ['inbox' => [], 'snoozed' => [], 'inboxDone' => [], 'vault' => [], 'users' => [], 'jobs' => [], 'api' => [], 'tarify' => []],
            // Bez dvojice není s kým se dohodnout — jediný člověk maže sám.
            'MAZANI' => self::MAZANI_PRAZDNE,
            'KE_SCHVALENI' => [],
        ];
    }

    public function kolekce(GallerySpace $prostor): array
    {
        /*
         * Trezor chodí **vždycky, i prázdný a i zamčený**.
         *
         * Ukázka měla čtyři složky („Doklady · 12 souborů · šifrováno",
         * „Skeny pasů · sdílet nelze") a obrazovka je ukazovala každému, kdo se
         * dostal přes heslo natištěné o dva řádky výš. Dvojí lež v jednom:
         * obsah nebyl její a šifrování aplikace nedělá — jen schovává před
         * mřížkou, hledáním a sdílením.
         *
         * Zamčený trezor posílá prázdno. Zámek, který přesto vypíše, co je za
         * ním, žádný zámek není.
         */
        $trezor = $this->trezorOtevreny()
            ? $this->schovaneMedia($prostor)
            : new Collection;

        $disk = $this->diskAStav($prostor);
        $zamek = $this->stavZamku();
        $mazani = $this->mazani($prostor);

        return array_filter([
            'DATA_HEALTH' => $this->zdraviDat($prostor),
            'SECLIFE' => $this->zivotSekci($prostor),
            'ABARS' => $this->sloupce($prostor),
            'AFORMS' => $this->prepinace($prostor),
            'CONFLICTS' => $this->rozpory($prostor),
            'DISK' => $disk,
            'TRASH' => $this->kos($prostor),
            'KE_SCHVALENI' => $this->keSchvaleni($prostor),
            'DVOJICE' => $this->jmenaDvojice($prostor),
            'UCTY' => $this->uctyDvojice($prostor),
            'OZNAMENI' => $this->oznameni(),
        ], fn ($v) => $v !== null && $v !== [])
            + [
                /*
                 * Kdo se přihlašuje — jménem a adresou dvojice.
                 *
                 * Přihlašovací obrazovka nabízela „Adrian" a „Makinka" a do
                 * kolonky předvyplnila `adrian@example.com`. U jiné
                 * dvojice to byla cizí adresa, kterou člověk poslušně odeslal
                 * a dostal „E-mail nebo heslo nesouhlasí" — bez nápovědy, co
                 * je vlastně špatně.
                 *
                 * Posílá se jako objekt se dvěma klíči, protože `LOCKWHO`
                 * a `LOCKMAIL` jsou v prototypu objekty: navlékají se na
                 * místě a všech patnáct míst, která je čtou, uvidí to pravé
                 * hned. Pořadí je totéž jako u `DVOJICE`.
                 */
                'LOCKWHO' => $this->kdoSePrihlasuje($prostor, 0),
                'LOCKMAIL' => $this->kdoSePrihlasuje($prostor, 1),
                /*
                 * Zámek aplikace — o kódu jen to, jestli vůbec je.
                 *
                 * `LOCKPIN` byly dva šestimístné kódy napsané v `galerie-data.js`
                 * a `lockTry()` je porovnával v prohlížeči. Kód teď zná jen
                 * databáze, a to jako haš; sem chodí pouze `nastaveno` a datum
                 * poslední změny, aby obrazovka věděla, jestli má kód chtít,
                 * nebo nabídnout jeho nastavení.
                 */
                'ZAMEK' => $zamek,
                /*
                 * Pravidlo mazání a co čeká na souhlas — vždycky a celé.
                 *
                 * Je to objekt a klient v objektu přepisuje jen klíče, které
                 * přišly: kdyby `navrhRezimu` po stažení návrhu chybělo, místo
                 * `null` by na obrazovce zůstal starý návrh.
                 */
                'MAZANI' => $mazani,
                'TREZOR' => $this->stavTrezoru(),
                'VAULT_ITEMS' => $this->obsahTrezoru($trezor),
                'AL' => array_filter(
                    [
                        // Co čeká na zařazení — spočítané, ne napsané.
                        'inbox' => $this->akcniInbox($prostor),
                        'vault' => $this->trezorDoSeznamu($trezor),
                    ]
                    + $this->inboxRozhodnute($prostor)
                    + $this->spravaDoSeznamu($prostor),
                    /*
                     * Tyhle chodí **i prázdné**, každý z jiného důvodu.
                     *
                     * `vault`, `inbox`, `snoozed`, `inboxDone` a `users` mají
                     * v prototypu napsaný prázdný stav („Nic není odložené"),
                     * takže prázdno není k nerozeznání od rozbité obrazovky.
                     *
                     * `api`, `tarify` a `jobs` prázdný stav nemají, a přesto
                     * chodí: jejich ukázka tvrdí něco o penězích a o přístupu.
                     * „Rodinný 200 GB · aktivní · 249 Kč měsíčně" u dvojice bez
                     * předplatného, „Mobilní aplikace · klíč …8f2a · aktivní"
                     * u dvojice, která žádný klíč nevydala, a „Noční záloha ·
                     * hotovo" jako ujištění, že zálohy běží. Prázdný seznam je
                     * proti tomu poctivý.
                     */
                    fn (array $v, string $k) => in_array(
                        $k,
                        ['vault', 'inbox', 'snoozed', 'inboxDone', 'users', 'api', 'tarify', 'jobs'],
                        true,
                    ) || $v !== [],
                    ARRAY_FILTER_USE_BOTH,
                ),
            ]
            // Nastavení ze skutečného stavu — viz NastaveniAplikace.
            + $this->nastaveni->pro($prostor, auth()->user(), $disk, $zamek, $mazani);
    }

    /**
     * Ostatní z dvojice prostoru: `[id => ['jmeno' => …, 'aktivni' => bool]]`.
     *
     * Dvojice je vlastník a role `owner`/`admin`/`editor` **v tomhle
     * prostoru** — host ne, i když ho `members()` vrací taky. Deaktivovaný
     * partner se počítá (jako v `MazaniFotek::pocetDvojice`): obrazovka má
     * říct, na koho se čeká, i když se zrovna nepřihlásí. Upozornění ale
     * dostane jen aktivní (`aktivni`).
     *
     * Jména jako všude jinde (`jmenaClenu`), aby dva Adriani nesplynuli.
     *
     * @return array<int, array{jmeno: string, aktivni: bool}>
     */
    public static function ostatniZDvojice(GallerySpace $prostor, ?int $ja): array
    {
        $ids = collect(self::idDvojice($prostor))->reject(fn (int $id) => $id === $ja)->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $jmena = self::jmenaClenu($prostor);

        return User::query()->whereIn('id', $ids)->orderBy('id')->get(['id', 'name', 'is_active'])
            ->mapWithKeys(fn (User $u) => [(int) $u->id => [
                'jmeno' => (string) ($jmena[$u->id] ?? $u->name),
                // `null` je čerstvý účet, kterému výchozí hodnotu doplnila databáze.
                'aktivni' => $u->is_active !== false,
            ]])
            ->all();
    }

    /**
     * Id dvojice prostoru: vlastník a role `owner`/`admin`/`editor` v TOMHLE
     * prostoru. Host ne — `members()` ho vrací taky. Deaktivovaní ano: jejich
     * zápisy, jména a návrhy v galerii zůstávají (jako `MazaniFotek::pocetDvojice`).
     *
     * @return list<int>
     */
    private static function idDvojice(GallerySpace $prostor): array
    {
        return DB::table('gallery_space_user')
            ->where('gallery_space_id', $prostor->id)
            ->whereIn('role', PristupDoGalerie::ROLE_DVOJICE)
            ->pluck('user_id')
            ->push($prostor->owner_id)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Jak se v galerii maže a co čeká na souhlas.
     *
     * `muzuSam` říká obrazovce, jestli „Do koše" maže, nebo jen navrhuje.
     * `partner` je jméno toho, na koho se čeká (`null`, když v galerii nikdo
     * další není). Počty se řídí trezorem jako seznam koše: se zamčeným
     * trezorem by rozdíl mezi číslem a seznamem prozradil, že v něm něco je.
     *
     * @return array<string, mixed>
     */
    private function mazani(GallerySpace $prostor): array
    {
        $ja = auth()->id() === null ? null : (int) auth()->id();
        $stav = $this->mazaniFotek->stavRezimu($prostor);
        $ostatni = self::ostatniZDvojice($prostor, $ja);
        $navrh = $stav['navrh'];
        $navrhl = $navrh === null || $navrh['navrhl'] === null ? null : (int) $navrh['navrhl'];

        $cekajici = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->cekaNaSmazani()
            // Návrh bez navrhujícího (smazaný účet) schválit nejde — nečeká na nikoho.
            ->whereNotNull('trash_requested_by')
            ->when(! $this->trezorOtevreny(), fn ($q) => $q->where('is_hidden', false));

        return [
            'rezim' => $stav['rezim'],
            'muzuSam' => $stav['rezim'] === MazaniFotek::KAZDY || $stav['pocetDvojice'] <= 1,
            'partner' => $ostatni === [] ? null : reset($ostatni)['jmeno'],
            'navrhRezimu' => $navrh === null ? null : [
                'rezim' => $navrh['rezim'],
                'kdo' => $navrhl === null ? null : ($this->jmenaNavrhujicich($prostor, [$navrhl])[$navrhl] ?? 'Někdo'),
                'ja' => $navrhl !== null && $navrhl === $ja,
                'kdy' => $navrh['kdy'] === null ? null : $this->kdy(CarbonImmutable::parse($navrh['kdy'])->toDateTimeString()),
            ],
            'cekaNaMe' => $ja === null ? 0 : (clone $cekajici)->where('trash_requested_by', '!=', $ja)->count(),
            'cekaNaPartnera' => $ja === null ? 0 : (clone $cekajici)->where('trash_requested_by', $ja)->count(),
        ];
    }

    /**
     * Návrhy ke smazání: `[{ id, name, from, bg, by, when, ja, n }]`, nejnovější první.
     *
     * Týž tvar jako `TRASH` (obrazovka koše je kreslí nad ním), navíc `ja` —
     * vlastní návrh jde jen stáhnout, cizí schválit nebo ponechat — a `bg`,
     * náhled jako hodnota pro `background` (jako `PHOTOS.bg`). Fotka z trezoru
     * jen s odemčeným trezorem a nikdy s náhledem: náhledy z trezoru se
     * nevydávají vůbec.
     *
     * @return list<array<string, mixed>>
     */
    private function keSchvaleni(GallerySpace $prostor): array
    {
        $polozky = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->cekaNaSmazani()
            ->whereNotNull('trash_requested_by')
            ->when(! $this->trezorOtevreny(), fn ($q) => $q->where('is_hidden', false))
            ->orderByDesc('trash_requested_at')
            ->orderByDesc('id')
            ->limit(self::KE_SCHVALENI_NEJVIC)
            ->get(['id', 'uuid', 'original_filename', 'media_type', 'is_hidden', 'trash_requested_by', 'trash_requested_at']);

        if ($polozky->isEmpty()) {
            return [];
        }

        $ja = auth()->id() === null ? null : (int) auth()->id();
        $jmena = $this->jmenaNavrhujicich($prostor, $polozky->pluck('trash_requested_by'));
        // Konec zítřka pro všechny, jako u dlaždic knihovny — prohlížeč si náhled podrží.
        $platnost = CarbonImmutable::tomorrow()->endOfDay();

        return $polozky->map(fn (MediaItem $m) => array_filter([
            'id' => $m->uuid,
            'name' => $m->original_filename,
            'from' => $m->media_type === 'video' ? 'Video' : 'Fotka',
            'bg' => $m->is_hidden ? null : "url('".URL::temporarySignedRoute('galerie.media.thumb', $platnost, ['uuid' => $m->uuid])."') center/cover no-repeat #2b2842",
            'by' => $jmena[(int) $m->trash_requested_by] ?? 'Někdo',
            'when' => $this->kdy(CarbonImmutable::parse($m->trash_requested_at)->toDateTimeString()),
            'ja' => (int) $m->trash_requested_by === $ja,
            'n' => 0,
        ], fn ($v) => $v !== null))->values()->all();
    }

    /**
     * Jména navrhujících naráz: člen galerie jako všude jinde, bývalý člen
     * jménem z účtu — návrh po něm může zůstat, než ho někdo vyřídí.
     *
     * @param  iterable<int>  $ids
     * @return array<int, string>
     */
    private function jmenaNavrhujicich(GallerySpace $prostor, iterable $ids): array
    {
        $jmena = self::jmenaClenu($prostor);
        $chybi = collect($ids)->map(fn ($id) => (int) $id)->unique()->reject(fn (int $id) => array_key_exists($id, $jmena));

        if ($chybi->isNotEmpty()) {
            $jmena += User::query()->whereIn('id', $chybi)->pluck('name', 'id')->map(fn ($j) => (string) $j)->all();
        }

        return $jmena;
    }

    /**
     * `{ A: …, M: … }` z členů prostoru — jména (`$sloupec` 0) nebo adresy (1).
     *
     * Klíče `A` a `M` jsou z prototypu; nejsou to iniciály konkrétních lidí,
     * jen „první" a „druhý". Když je v prostoru jen jeden člověk, druhý klíč
     * se nevyplní a obrazovka nabídne jedinou volbu — což je pravda.
     *
     * @return array<string, string>
     */
    private function kdoSePrihlasuje(GallerySpace $prostor, int $sloupec): array
    {
        $lide = $this->uctyDvojice($prostor);
        $klice = ['A', 'M'];
        $mapa = [];

        foreach (array_slice($lide, 0, 2) as $i => $clovek) {
            // Adresa se porovnává s tím, co člověk napsal do kolonky, a ten
            // ji píše, jak mu přijde; přihlašování rozlišovat velikost nemá.
            $mapa[$klice[$i]] = $sloupec === 1
                ? mb_strtolower((string) $clovek[1])
                : (string) $clovek[0];
        }

        return $mapa;
    }

    /**
     * Co obrazovka o zámku smí vědět.
     *
     * Jen jestli má přihlášený člověk kód nastavený a odkdy. Samotný kód sem
     * nepatří ani v podobě haše — obrazovka ho nepotřebuje, ověřuje ho server.
     *
     * @return array<string, mixed>
     */
    private function stavZamku(): array
    {
        $clovek = auth()->user();

        return [
            /*
             * Jestli se dívá někdo přihlášený.
             *
             * Obrazovka podle toho pozná rozdíl mezi „kód nemám nastavený"
             * a „nejsem přihlášený" — v obou případech totiž `nastaveno`
             * vyjde `false`, ale první z nich má být uvnitř a druhý venku.
             */
            'prihlasen' => $clovek !== null,
            'nastaveno' => (bool) ($clovek?->app_lock_pin),
            'delka' => 6,
            'zmeneno' => $clovek?->app_lock_set_at?->toDateString(),
        ] + $this->zarizeniASezeni($clovek);
    }

    /**
     * Zařízení a sezení — spočítaná, ne napsaná.
     *
     * V nastavení stálo „iPhone Adrian, iPhone Makinka, iPad v ložnici ·
     * 3 zařízení" a „Tento telefon a iPhone Makinka (dnes 7:12)". U dvojice,
     * která se přihlásila z jednoho notebooku, to bylo tvrzení o cizích
     * telefonech — a zrovna na obrazovce, kde má člověk poznat, že se někdo
     * přihlásil odjinud.
     *
     * Zařízení jsou vydané přihlašovací klíče (`personal_access_tokens`,
     * pojmenované při přihlášení), sezení řádky v `sessions`. Obojí jen moje:
     * kolik zařízení má partner, není moje věc.
     *
     * @return array<string, mixed>
     */
    private function zarizeniASezeni(?User $clovek): array
    {
        if ($clovek === null) {
            return ['zarizeni' => 0, 'zarizeniPopis' => '', 'sezeni' => 0, 'sezeniPopis' => ''];
        }

        $klice = DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $clovek->id)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByDesc('last_used_at')
            ->get(['name', 'last_used_at']);

        $sezeni = Tabulky::je('sessions')
            ? DB::table('sessions')
                ->where('user_id', $clovek->id)
                // Sezení bez aktivity za poslední dva týdny je mrtvé; počítat
                // ho mezi „aktivní" by z toho čísla udělalo nesmysl.
                ->where('last_activity', '>=', now()->subDays(14)->timestamp)
                ->orderByDesc('last_activity')
                ->get(['ip_address', 'last_activity'])
            : collect();

        return [
            'zarizeni' => $klice->count(),
            'zarizeniPopis' => $klice->isEmpty()
                ? 'Zatím žádné — tohle okno běží na přihlášení přes prohlížeč'
                : $klice->take(3)->map(fn (object $k) => (string) ($k->name ?: 'bez názvu'))->implode(', '),
            'sezeni' => $sezeni->count(),
            'sezeniPopis' => $sezeni->isEmpty()
                ? 'Žádné otevřené sezení kromě tohohle'
                : $this->pocet($sezeni->count(), 'otevřené sezení', 'otevřená sezení', 'otevřených sezení')
                    .' · naposledy '.Cas::mistni((int) $sezeni->first()->last_activity)->format('j. n. G:i'),
        ];
    }

    /**
     * Je trezor právě odemčený?
     *
     * Ptá se `Trezor` — tatáž podmínka, jakou hlídá výdej souborů
     * (`ProtectVaultMedia`) a jakou nastavuje `TrezorController`, a jen pro
     * toho, kdo odemykal. Jediné místo pravdy: kdyby si obrazovka vedla
     * vlastní odpočet, dala by se otevřít přepsáním čísla v konzoli a soubory
     * by stejně nedostala.
     */
    private function trezorOtevreny(): bool
    {
        return Trezor::odemcen();
    }

    /** @return array{odemceno: bool, zbyva: int} */
    private function stavTrezoru(): array
    {
        $zbyva = Trezor::zbyva();

        return ['odemceno' => $zbyva > 0, 'zbyva' => $zbyva];
    }

    /**
     * Co v trezoru doopravdy leží.
     *
     * Volá se jen s odemčeným trezorem — viz `kolekce()`.
     *
     * @return Collection<int, MediaItem>
     */
    private function schovaneMedia(GallerySpace $prostor): Collection
    {
        return MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('trashed_at')
            ->where('is_hidden', true)
            ->with('primaryAlbum:id,title')
            ->orderByDesc('taken_at')
            ->limit(200)
            ->get(['id', 'uuid', 'primary_album_id', 'original_filename', 'media_type', 'taken_at']);
    }

    /**
     * Obsah trezoru ve tvaru, ve kterém ho kreslí jeho obrazovka.
     *
     * Náhled se **záměrně neposílá**. U ostatních mřížek chodí jako podepsaná
     * adresa s platností do konce zítřka; u trezoru by to znamenalo, že se
     * z nejcitlivějších souborů stanou odkazy, které fungují bez přihlášení
     * a přežijí i zamčení. Dlaždici si prototyp umí nakreslit i sám.
     *
     * @param  Collection<int, MediaItem>  $schovane
     * @return list<array<string, mixed>>
     */
    private function obsahTrezoru(Collection $schovane): array
    {
        return $schovane
            ->map(fn (MediaItem $m) => [
                // `id` je uuid: pod ním se položka vrací z trezoru zpátky.
                'id' => (string) $m->uuid,
                'name' => (string) ($m->original_filename ?: 'Bez názvu'),
                'meta' => implode(' · ', array_filter([
                    $m->primaryAlbum?->title,
                    $m->taken_at ? CarbonImmutable::parse($m->taken_at)->format('j. n. Y') : null,
                    'mimo mřížku, hledání i sdílení',
                ])),
                'n' => (int) $m->id,
            ])
            ->values()
            ->all();
    }

    /**
     * Týž trezor ve tvaru seznamu, po albech.
     *
     * Ukázka tu psala „Doklady · 12 souborů · šifrováno". Aplikace obsah
     * trezoru **nešifruje** — schová ho před mřížkou, hledáním a sdílením.
     * Tvrdit u něj šifrování je slib, který nikdo nedrží, a přesně ten, kvůli
     * kterému by tam dvojice dala doklady.
     *
     * @param  Collection<int, MediaItem>  $schovane
     * @return list<array<int, string>>
     */
    private function trezorDoSeznamu(Collection $schovane): array
    {
        return $schovane
            ->groupBy(fn (MediaItem $m) => $m->primaryAlbum?->title ?: 'Bez alba')
            ->map(fn (Collection $v, string $album) => [
                $album,
                $this->pocet($v->count(), 'položka', 'položky', 'položek').' · schované před mřížkou i sdílením',
                'trezor',
            ])
            ->values()
            ->all();
    }

    /**
     * Jméno a adresa obou, ve stejném pořadí jako `DVOJICE`.
     *
     * První spuštění nabízelo výběr ze dvou napsaných účtů včetně adres
     * (`adrian@example.com`). U jiné dvojice to byla cizí adresa
     * a člověk si podle ní vybíral, kdo je.
     *
     * Nic nového se tím neodhaluje: jsou to členové téhož prostoru a vidí
     * se navzájem i v administraci.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function uctyDvojice(GallerySpace $prostor): array
    {
        /*
         * Jen dvojice, a jen ta, která se přihlásit může.
         *
         * Brali se všichni členové prostoru: přihlašovací obrazovka pak
         * nabízela jméno a adresu hosta jako druhého z dvojice. Účet
         * s odebraným přístupem se nepřihlásí, takže ho nabízet nemá smysl.
         */
        $lide = app(PristupDoGalerie::class)->dvojiceOdDivaka($prostor, auth()->user());

        return $lide
            ->map(fn ($u) => [(string) $u->name, (string) $u->email])
            ->take(2)
            ->values()
            ->all();
    }

    /**
     * Nepřečtená oznámení toho, kdo se dívá: `{ id, text, ikona, kdy, kam, dulezite }`.
     *
     * Server je zapisuje (nahrané fotky, přidělený úkol, narozeniny, dárek,
     * kapsle, import financí…), ale galerie je nikde neukazovala — zvonek
     * skládal jen připomenutí odvozená z obrazovek. Přečíst a odložit se dají
     * přes `/api/v1/notifications`. Odložená, archivovaná a vypnutá v předvolbách
     * se neposílají.
     *
     * @return list<array<string, mixed>>
     */
    private function oznameni(): array
    {
        $ja = auth()->user();

        if (! $ja instanceof User || ! Tabulky::je('notifications')) {
            return [];
        }

        $dotaz = $ja->unreadNotifications();

        if (Tabulky::sloupec('notifications', 'archived_at')) {
            $dotaz->whereNull('archived_at')
                ->where(fn ($q) => $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now()));
        }

        $predvolby = app(NotificationPreferenceService::class);
        $dnes = Cas::ted();

        return $dotaz->latest()->limit(60)->get()
            ->filter(fn (DatabaseNotification $n) => $predvolby->allows($ja, (string) ($n->data['type'] ?? ''), (array) $n->data))
            ->take(15)
            ->map(function (DatabaseNotification $n) use ($predvolby, $dnes) {
                $druh = (string) ($n->data['type'] ?? '');
                $meta = $predvolby->metadata($druh, (array) $n->data);
                $kdy = Cas::mistni($n->created_at);

                return [
                    'id' => (string) $n->id,
                    'text' => mb_substr(trim((string) ($n->data['message'] ?? 'Oznámení')), 0, 200),
                    'ikona' => $this->ikonaOznameni($druh),
                    'kdy' => $kdy === null ? ''
                        : ($kdy->isSameDay($dnes) ? 'dnes v '.$kdy->format('G:i')
                            : ($kdy->isSameDay($dnes->subDay()) ? 'včera v '.$kdy->format('G:i') : $kdy->format('j. n.'))),
                    'kam' => $this->kamOznameni($druh),
                    'dulezite' => in_array($meta['priority'], ['high', 'critical'], true),
                ];
            })
            ->values()
            ->all();
    }

    private function ikonaOznameni(string $druh): string
    {
        return match (true) {
            str_starts_with($druh, 'upload') || str_starts_with($druh, 'media') => 'ph-images',
            str_starts_with($druh, 'album') => 'ph-folder',
            str_contains($druh, 'todo') || str_contains($druh, 'task') => 'ph-check-square',
            str_starts_with($druh, 'calendar') || str_starts_with($druh, 'planning') => 'ph-calendar-check',
            str_starts_with($druh, 'finance') || str_starts_with($druh, 'bank') => 'ph-credit-card',
            str_starts_with($druh, 'gift') => 'ph-gift',
            str_starts_with($druh, 'relationship') => 'ph-heart',
            str_starts_with($druh, 'memory') => 'ph-sparkle',
            str_starts_with($druh, 'cycle') || str_starts_with($druh, 'health') => 'ph-drop',
            str_starts_with($druh, 'drive') || str_starts_with($druh, 'export') => 'ph-hard-drives',
            default => 'ph-bell',
        };
    }

    /** Kam oznámení vede v galerii (klíč trasy); `null` = nikam, jen přečíst. */
    private function kamOznameni(string $druh): ?string
    {
        return match (true) {
            // Návrhy ke smazání se schvalují v koši, ne v knihovně.
            $druh === 'media.trash_proposed' => 'trash',
            str_starts_with($druh, 'upload') || str_starts_with($druh, 'media') => 'all',
            str_starts_with($druh, 'album') => 'albums',
            str_contains($druh, 'todo') || str_contains($druh, 'task') => 'x-plan',
            str_starts_with($druh, 'calendar') || str_starts_with($druh, 'planning') => 'calendar',
            str_starts_with($druh, 'finance') || str_starts_with($druh, 'bank') => 'x-transakce',
            str_starts_with($druh, 'gift') => 'x-darky',
            str_starts_with($druh, 'relationship') => 'x-milniky',
            str_starts_with($druh, 'memory') => 'x-vzpominky',
            str_starts_with($druh, 'cycle') || str_starts_with($druh, 'health') => 'x-cyklus',
            str_starts_with($druh, 'drive') || str_starts_with($druh, 'export') => 'storage',
            default => null,
        };
    }

    /**
     * Kdo ti dva jsou — jménem, ten kdo se dívá první.
     *
     * Aplikace to na desítkách míst porovnávala s „Adrian" a „Makinka"
     * napsanými v kódu. U dvojice, která se jmenuje jinak, z toho vycházely
     * prázdné sloupce a štítky bez barvy.
     *
     * Prototyp si jména dokázal odvodit z nálady dvou, jenže ta bývá prázdná —
     * a dokud si ji nikdo nezapíše, spadl zpátky na ukázková jména. Tady se
     * berou ze členů prostoru, které má každá dvojice od prvního dne.
     *
     * Stejné jméno dvakrát dostane pořadové číslo: bez něj by se dva Adriani
     * slili do jednoho a půlka obrazovek by počítala jeho práci dvakrát.
     *
     * @return list<string>
     */
    private function jmenaDvojice(GallerySpace $prostor): array
    {
        return array_slice(array_values(self::jmenaClenu($prostor)), 0, 2);
    }

    /**
     * Totéž jako `DVOJICE`, jen s id uživatele: `[id => jméno]`.
     *
     * Jiné skupiny (třeba příjmy v Financích) posílají mapy podle jména
     * a obrazovka je hledá jmény z `DVOJICE`. Kdyby si každá skupina jména
     * skládala po svém, druhý Adrian by v jedné byl „Adrian (2)" a v druhé
     * „Adrian" — a jeho příjem by se na obrazovce nenašel.
     *
     * Jen dvojice (`idDvojice`), ne hosté: host se jmenoval ve `DVOJICE`,
     * v příjmech Financí i v aktivitě na úvodní obrazovce — a když vstoupil
     * dřív než partner, seděl na jeho místě. Pořadí: ten, kdo se dívá, pak
     * podle vstupu do prostoru a id (jako `PristupDoGalerie::dvojiceOdDivaka`),
     * ať je stejné při každém načtení.
     *
     * @return array<int, string>
     */
    public static function jmenaClenu(GallerySpace $prostor): array
    {
        $ids = self::idDvojice($prostor);
        $ja = auth()->id() === null ? null : (int) auth()->id();
        $vstup = DB::table('gallery_space_user')
            ->where('gallery_space_id', $prostor->id)
            ->whereIn('user_id', $ids)
            ->pluck('joined_at', 'user_id')
            ->all();
        $lide = User::query()->whereIn('id', $ids)->pluck('name', 'id')->map(fn ($j) => (string) $j)->all();

        uksort($lide, fn (int $a, int $b) => [$b === $ja, ($vstup[$a] ?? null) === null, (string) ($vstup[$a] ?? ''), $a]
            <=> [$a === $ja, ($vstup[$b] ?? null) === null, (string) ($vstup[$b] ?? ''), $b]);

        $videno = [];
        $jmena = [];

        foreach ($lide as $id => $jmeno) {
            $videno[$jmeno] = ($videno[$jmeno] ?? 0) + 1;
            $jmena[$id] = $videno[$jmeno] > 1 ? $jmeno.' ('.$videno[$jmeno].')' : $jmeno;
        }

        return $jmena;
    }

    /**
     * Koš: `[{ id, name, from, by, when, left, n }]`.
     *
     * Obrazovka koše měla čtyři vymyšlené řádky a tlačítko „Vyprázdnit koš"
     * hlásilo „Trvale se odstraní 4 položky včetně jednoho albumu" — bez
     * ohledu na to, co v koši je. A pak nesmazalo nic.
     *
     * `left` je, kolik dní zbývá do trvalého odstranění. Počítá se z `purge_after`,
     * a když ho položka nemá, z třiceti dnů od vyhození — to je lhůta, kterou
     * slibuje text nad seznamem.
     *
     * @return list<array<string, mixed>>
     */
    private function kos(GallerySpace $prostor): array
    {
        $polozky = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->whereNotNull('trashed_at')
            /*
             * Fotka z trezoru jen s odemčeným trezorem — stejná podmínka jako
             * `VAULT_ITEMS`. Smazání se na `is_hidden` neptá, takže vyhozená
             * fotka z trezoru se jinak vypsala i s názvem souboru, zatímco byl
             * trezor zamčený. Úplně ji schovat nejde: vrátit z koše ji jde jen
             * odsud, a bez toho by ji po třiceti dnech úklid tiše smazal.
             */
            ->when(! $this->trezorOtevreny(), fn ($q) => $q->where('is_hidden', false))
            ->orderByDesc('trashed_at')
            ->limit(60)
            ->get(['uuid', 'original_filename', 'trashed_at', 'purge_after', 'uploaded_by', 'trashed_by', 'media_type']);

        if ($polozky->isEmpty()) {
            return [];
        }

        $jmena = $prostor->members()->pluck('users.name', 'users.id')->all();

        return $polozky->map(function (MediaItem $m) use ($jmena) {
            $vyhozeno = CarbonImmutable::parse($m->trashed_at);
            $konec = $m->purge_after ? CarbonImmutable::parse($m->purge_after) : $vyhozeno->addDays(30);
            $dnu = max(0, (int) round(now()->diffInDays($konec, false)));

            return [
                'id' => $m->uuid,
                'name' => $m->original_filename,
                // Odkud to bylo se nedopočítává: album po vyhození nemusí
                // existovat a vymyslet cestu by znamenalo tvrdit, kde to leželo.
                'from' => $m->media_type === 'video' ? 'Video' : 'Fotka',
                // Sloupec „Odstranil": kdo fotku do koše poslal. Starší položky
                // to nemají zapsané — u nich zbývá jen ten, kdo ji nahrál.
                'by' => $jmena[$m->trashed_by ?: $m->uploaded_by] ?? '—',
                'when' => $this->kdy($vyhozeno->toDateTimeString()),
                'left' => $this->pocet($dnu, 'den', 'dny', 'dní'),
                'n' => 0,
            ];
        })->values()->all();
    }

    /**
     * Úložiště a synchronizace — celá obrazovka ze skutečnosti.
     *
     * Byla to nejnebezpečnější obrazovka v aplikaci. Stálo v ní „Připojeno —
     * adrian@example.com", „poslední úspěšná synchronizace dnes v 8:12"
     * a „24 316 originálů bezpečně uloženo" — všechno napsané v designovém
     * souboru. Dvojici, která Disk připojený nemá, tvrdila, že jsou její fotky
     * ve dvou kopiích. To není zastaralé číslo, to je nepravda o záloze.
     *
     * @return array<string, mixed>
     */
    private function diskAStav(GallerySpace $prostor): array
    {
        /*
         * Použitelný Disk se hledá stejnou cestou jako všude jinde v aplikaci
         * (`DriveConnectionResolver`): podle **členů prostoru**, ne podle
         * `gallery_space_id`. Účet obvykle patří jednomu z nich a druhý na něj
         * nahrává taky; vazba na prostor v té tabulce je z větší části prázdná.
         *
         * Vedle toho se hledá i účet, který **nefunguje** — rozbité připojení
         * musí obrazovka přiznat, ne ho ukázat jako nepřipojený.
         */
        $disk = $this->disky->forSpace($prostor->id);
        $rozbity = $disk === null ? $this->rozbityDisk($prostor) : null;
        $disk ??= $rozbity;

        $polozky = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('trashed_at');

        $pocet = fn (callable $kde) => (int) $kde((clone $polozky))->count();

        /*
         * Čtyři dlaždice musí **rozdělit celou knihovnu**, ne jen popsat čtyři
         * stavy. Když se počítalo podle `storage_status`, položky se stavem,
         * který do žádné škatulky nepatřil (a v datech takové jsou), se ztratily
         * mezi dlaždicemi: součet neseděl s počtem fotek a nikdo by to nepoznal.
         *
         * Dělí se proto podle toho, co rozhoduje: **má to na Disku své id?**
         * Stav sám o sobě je jen tvrzení. Záznam, který o sobě říká „synced",
         * ale nemá k čemu se vrátit, je přesně ten případ na čtvrté dlaždici.
         */
        $naDisku = ['synced', 'mirrored'];

        $ulozeno = $pocet(fn ($q) => $q->whereNotNull('drive_file_id'));
        $ceka = $pocet(fn ($q) => $q->whereNull('drive_file_id')->where('storage_status', 'uploading'));
        $ztracene = $pocet(fn ($q) => $q->whereNull('drive_file_id')->whereIn('storage_status', $naDisku));
        // Prázdný stav se musí vypsat zvlášť: `NOT IN` v SQL řádek s `NULL`
        // nevrátí, takže by z rozdělení vypadl.
        $jenTady = $pocet(fn ($q) => $q->whereNull('drive_file_id')->where(
            fn ($w) => $w->whereNull('storage_status')
                ->orWhereNotIn('storage_status', array_merge($naDisku, ['uploading'])),
        ));
        $chybne = $pocet(fn ($q) => $q->whereNotNull('processing_error'));

        $bajtu = fn (string $druh) => (int) (clone $polozky)->where('media_type', $druh)->sum('size_bytes');

        $fotky = $bajtu('photo');
        $videa = $bajtu('video');

        $nahledy = Tabulky::je('media_variants')
            ? (int) DB::table('media_variants')
                ->join('media_items', 'media_items.id', '=', 'media_variants.media_item_id')
                ->where('media_items.gallery_space_id', $prostor->id)
                ->sum('media_variants.size_bytes')
            : 0;

        $celkem = $fotky + $videa + $nahledy;
        $dil = fn (int $b) => $celkem > 0 ? round($b / $celkem * 100, 1).'%' : '0%';

        $pripojeno = $disk !== null && $rozbity === null;
        $posledni = $disk?->last_successful_request_at?->toDateTimeString();

        return [
            'connected' => $pripojeno,
            'account' => $disk->account_email ?? null,

            'intro' => $pripojeno
                ? 'Originály fotek a videí leží na Google účtu '.$disk->account_email.'. Galerie si u sebe drží jen náhledy.'
                : 'Google Disk není připojený. Originály leží jen tady — druhou kopii nemá kdo udělat.',

            'headline' => $pripojeno
                ? 'Připojeno — '.$disk->account_email
                : ($disk === null ? 'Účet není připojený' : 'Připojení nefunguje — '.$disk->account_email),

            // Co se opravdu ví: kdy naposledy Disk odpověděl a kolik originálů
            // má u sebe. Ne „dnes v 8:12".
            'note' => $pripojeno
                ? ($posledni === null
                    ? 'Účet je připojený, ale ještě se nic nepřeneslo.'
                    : 'Poslední úspěšná odpověď Disku '.$this->kdy($posledni).' · '
                        .$this->pocet($ulozeno, 'originál bezpečně uložen', 'originály bezpečně uloženy', 'originálů bezpečně uloženo'))
                : ($disk?->last_error_message ?: 'Bez připojeného účtu se originály nemají kam kopírovat.'),

            'tone' => $pripojeno ? 'ok' : ($disk === null ? 'off' : 'err'),
            'cta' => $disk === null ? 'Připojit Google Disk' : 'Znovu připojit účet',

            'capacity' => [
                ['label' => 'Fotografie '.$this->objem($fotky), 'w' => $dil($fotky), 'color' => 'var(--g-acc)'],
                ['label' => 'Videa '.$this->objem($videa), 'w' => $dil($videa), 'color' => 'var(--g-acc-deep)'],
                ['label' => 'Náhledy a cache '.$this->objem($nahledy), 'w' => $dil($nahledy), 'color' => 'var(--g-warn)'],
            ],

            'states' => [
                ['icon' => 'ph-cloud-check', 'color' => 'var(--g-ok)', 'value' => $this->cislo($ulozeno),
                    'label' => 'Originál bezpečně uložen', 'hint' => 'na Google Disku'],
                ['icon' => 'ph-cloud-arrow-up', 'color' => 'var(--g-acc)', 'value' => $this->cislo($ceka),
                    'label' => 'Čeká na přenos', 'hint' => 'nahraje se, až bude galerie otevřená'],
                ['icon' => 'ph-image', 'color' => 'var(--g-ink2)', 'value' => $this->cislo($jenTady),
                    'label' => 'Jen lokální náhled', 'hint' => $pripojeno ? 'originál se přenese později' : 'druhá kopie chybí'],
                ['icon' => 'ph-warning-circle', 'color' => 'var(--g-mag)', 'value' => $this->cislo($ztracene),
                    'label' => 'Originál nelze najít', 'hint' => 'nejspíš přesunut mimo galerii'],
            ],

            // Varovný pruh se ukáže jen tehdy, když je opravdu co hlásit.
            'failed' => $chybne,
            'failedTitle' => $chybne === 0 ? '' :
                $this->pocet($chybne, 'originál se nepodařilo zpracovat', 'originály se nepodařilo zpracovat', 'originálů se nepodařilo zpracovat'),
        ];
    }

    /**
     * Účet, který je připojený, ale nefunguje.
     *
     * Vypršelý token, odebraný souhlas, smazaná kořenová složka. Pro dvojici
     * je to horší stav než „nepřipojeno": myslí si, že zálohu má.
     */
    private function rozbityDisk(GallerySpace $prostor): ?StorageConnection
    {
        if (! Tabulky::je('storage_connections')) {
            return null;
        }

        return StorageConnection::query()
            ->where('provider', 'google_drive')
            ->whereNull('revoked_at')
            // Jen dvojice: rozbité připojení hosta panel ukazoval i s jeho adresou.
            ->whereIn('owner_user_id', self::idDvojice($prostor))
            ->orderByDesc('connected_at')
            ->first();
    }

    /** „dnes v 8:12" nebo datum — bez přesnosti, kterou aplikace nemá. */
    private function kdy(string $cas): string
    {
        $kdy = Cas::mistni($cas);

        return match (true) {
            $kdy->isToday() => 'dnes v '.$kdy->format('G:i'),
            $kdy->isYesterday() => 'včera v '.$kdy->format('G:i'),
            default => $kdy->format('j. n. Y').' v '.$kdy->format('G:i'),
        };
    }

    /**
     * Objem lidsky. Desítkové jednotky — tak je počítá i Google Disk.
     *
     * Pod megabajt se ukazují megabajty s desetinou, ne „0,0 GB": nová galerie
     * má pár set kilobajtů náhledů a tři nuly vedle sebe vypadají jako chyba.
     */
    private function objem(int $bajtu): string
    {
        if ($bajtu >= 1_000_000_000) {
            return str_replace('.', ',', (string) round($bajtu / 1_000_000_000, 1)).' GB';
        }

        if ($bajtu >= 1_000_000) {
            return str_replace('.', ',', (string) round($bajtu / 1_000_000, 1)).' MB';
        }

        return max(0, (int) round($bajtu / 1000)).' kB';
    }

    /**
     * Rozpory mezi zařízeními: `[id, co, kde, ikona, moje, kdy, jejich, kdy, sloučeno]`.
     *
     * Dvě verze téhož záznamu, které vznikly, když byl jeden z telefonů
     * offline. Nabídnutá sloučená verze se **nevymýšlí** — je to prázdné
     * pole a rozhodnutí zůstává na dvojici. Domyslet za ně, co si vlastně
     * chtěli poznamenat, by bylo horší než nechat je vybrat.
     *
     * @return list<array<int, mixed>>
     */
    private function rozpory(GallerySpace $prostor): array
    {
        if (! Tabulky::je('drive_conflicts') || ! Tabulky::je('storage_connections')) {
            return [];
        }

        return DB::table('drive_conflicts as r')
            ->join('storage_connections as s', 's.id', '=', 'r.storage_connection_id')
            ->where('s.gallery_space_id', $prostor->id)
            ->whereNull('r.resolved_at')
            ->orderByDesc('r.detected_at')
            ->limit(20)
            ->get(['r.id', 'r.entity_type', 'r.entity_id', 'r.conflict_type', 'r.app_state', 'r.drive_state', 'r.detected_at'])
            ->map(function (object $r) {
                $kdy = CarbonImmutable::parse($r->detected_at);

                return [
                    // `r`, ne `c`: ukázkové rozpory v `galerie-data.js` mají
                    // `c1` až `c3` a obrazovka by je posílala serveru k
                    // vyřešení, kde by na ně narazila na 404.
                    'r'.$r->id,
                    $this->popisRozporu((string) $r->entity_type, (int) $r->entity_id),
                    $this->kdeRozpor((string) $r->entity_type),
                    $this->ikonaRozporu((string) $r->entity_type),
                    $this->stranaRozporu($r->app_state),
                    $this->pred($kdy),
                    $this->stranaRozporu($r->drive_state),
                    $this->pred($kdy),
                    // Sloučenou verzi si dvojice vybere sama.
                    '',
                    /*
                     * Jak se ty dvě strany jmenují.
                     *
                     * Obrazovka je jinak popisuje „moje" a „jeho" — u rozporu
                     * mezi knihovnou a Diskem by to znamenalo označit za
                     * původce smazaného souboru někoho z dvojice.
                     */
                    ['V knihovně', 'Na Disku'],
                ];
            })
            ->values()
            ->all();
    }

    private function popisRozporu(string $druh, int $id): string
    {
        $nazev = match ($druh) {
            'media_item' => Tabulky::je('media_items')
                ? DB::table('media_items')->where('id', $id)->value('original_filename')
                : null,
            'album' => Tabulky::je('albums')
                ? DB::table('albums')->where('id', $id)->value('title')
                : null,
            default => null,
        };

        return $nazev ? $this->kdeRozpor($druh).' — '.$nazev : $this->kdeRozpor($druh);
    }

    private function kdeRozpor(string $druh): string
    {
        return match ($druh) {
            'media_item' => 'Knihovna',
            'album' => 'Alba',
            'todo' => 'Plánování → Nástěnka',
            default => 'Úložiště',
        };
    }

    private function ikonaRozporu(string $druh): string
    {
        return match ($druh) {
            'media_item' => 'ph-image',
            'album' => 'ph-folders',
            'todo' => 'ph-list-checks',
            default => 'ph-cloud-warning',
        };
    }

    /** Jedna strana rozporu jako věta, ne jako JSON. */
    private function stranaRozporu(mixed $stav): string
    {
        $data = json_decode((string) $stav, true);

        if (! is_array($data) || $data === []) {
            return 'beze změny';
        }

        foreach (['caption', 'title', 'name', 'note'] as $klic) {
            if (! empty($data[$klic])) {
                return (string) $data[$klic];
            }
        }

        return implode(', ', array_map(
            fn ($k, $v) => $k.': '.(is_scalar($v) ? (string) $v : '…'),
            array_keys($data),
            $data,
        ));
    }

    /**
     * Přepínače nastavení: `{ klíč: [[sekce, [[popisek, poznámka, zapnuto]]]] }`.
     *
     * Obrazovka, pro kterou nemá aplikace ani jeden skutečný přepínač, se
     * **neposílá** — prototyp na chybějící klíč sahá přes `AFORMS[key] ||
     * AFORMS.revolut`, takže prázdný by na ni nakreslil cizí formulář.
     *
     * @return array<string, list<array{0: string, 1: list<array{0: string, 1: string, 2: int}>}>>
     */
    private function prepinace(GallerySpace $prostor): array
    {
        $uzivatel = auth()->user();
        $formulare = [];

        foreach ($this->formulare->klice() as $klic) {
            $sekce = $this->formulare->sekce($klic, $prostor, $uzivatel);

            if ($sekce === []) {
                continue;
            }

            $formulare[$klic] = array_map(fn (array $s) => [
                $s['label'],
                array_map(fn (array $r) => [$r['label'], (string) $r['note'], $r['on'] ? 1 : 0], $s['rows']),
            ], $sekce);
        }

        return $formulare;
    }

    // ——— zdraví dat ———

    /**
     * Každý řádek je jedno číslo, které aplikace někde ukazuje, spolu s tím,
     * odkud je a jak moc se na něj dá spolehnout.
     *
     * `conf` se nevymýšlí: tvrdá čísla přicházejí ze zdroje, ruční zápis je
     * tak přesný, jak přesně se zapisoval, a odhad je tím jistější, čím delší
     * řadu záznamů má pod sebou.
     *
     * @return list<array<string, mixed>>
     */
    private function zdraviDat(GallerySpace $prostor): array
    {
        return array_values(array_filter([
            $this->radekZustatek($prostor),
            $this->radekKnihovna($prostor),
            $this->radekOdhadnutaData($prostor),
            $this->radekRozpocet($prostor),
            $this->radekCyklus($prostor),
            $this->radekCesta($prostor),
        ]));
    }

    /** @return array<string, mixed>|null */
    private function radekZustatek(GallerySpace $prostor): ?array
    {
        $penezenky = DB::table('wallets')
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->get(['uuid', 'name', 'kind', 'currency', 'opening_balance']);

        if ($penezenky->isEmpty()) {
            return null;
        }

        $zustatky = $this->kniha->walletBalances($prostor)->keyBy('uuid');

        /*
         * Zůstatek v hlavní měně (CZK), účty v jiných měnách přepočtené kurzem ECB.
         *
         * Dřív vyhrála měna s nejvíc účty — dva eurové účty proti jednomu
         * korunovému tak z celého zůstatku udělaly „1 000 €" a koruny zmizely.
         * Teď se každá měna sečte zvlášť a převede jednou; když kurz chybí,
         * ukáže se jen hlavní měna a zbytek se napíše částkou „stranou" —
         * smíšené číslo na obrazovce o důvěryhodnosti čísel nesmí vzniknout.
         */
        $hlavniMena = Meny::hlavni($prostor);
        $podleMeny = $penezenky->groupBy(fn (object $p) => $this->klicMeny($p->currency, $hlavniMena));
        $poMenach = $podleMeny
            ->sortByDesc(fn (Collection $ucty) => $ucty->count())
            ->map(fn (Collection $ucty) => $ucty->sum(fn (object $p) => (float) ($zustatky[$p->uuid]['balance'] ?? $p->opening_balance ?? 0)))
            ->all();

        $soucet = $this->vHlavniMene($poMenach, $prostor);
        $hlavni = $soucet['mena'];
        $celkem = $soucet['hodnota'];
        // Kolik účtů je v čísle: po přepočtu všechny, bez kurzu jen ty v ukázané měně.
        $vHlavni = $soucet['stranou'] === [] ? $penezenky : ($podleMeny[$hlavni] ?? collect());

        $napojeni = Tabulky::je('bank_connections')
            ? DB::table('bank_connections')
                ->where('gallery_space_id', $prostor->id)
                ->whereNull('revoked_at')
                ->orderByDesc('last_synced_at')
                ->first(['institution_name', 'last_synced_at'])
            : null;

        $sync = $napojeni?->last_synced_at ? CarbonImmutable::parse($napojeni->last_synced_at) : null;
        $stare = $sync !== null && $sync->lt(now()->subDay());

        return [
            'label' => 'Zůstatek na účtech',
            'value' => Meny::castka($celkem, $hlavni),
            'kind' => $sync ? 'hard' : 'manual',
            'where' => trim(($sync
                ? 'Bankovní napojení · '.($napojeni->institution_name ?: 'banka')
                : $this->pocet($vHlavni->count(), 'ručně vedený účet', 'ručně vedené účty', 'ručně vedených účtů'))
                .$this->dovetekMen($soucet, 'stranou')),
            // Datum kurzu zvlášť, ať ho obrazovka umí ukázat i jinde než ve „where".
            'rate' => $soucet['prepocet'],
            // Zůstatky po měnách — původní čísla, přepočet je jen druhá informace.
            // Prázdné jako `{}`, ať má klíč pořád tvar mapy.
            'split' => $soucet['poMenach'] ?: new \stdClass,
            'age' => $sync ? $this->pred($sync) : 'podle zapsaných pohybů',
            // Napojený a čerstvý zůstatek je nejtvrdší číslo v aplikaci; ručně
            // vedený je tak přesný, jak přesně se do něj zapisovalo.
            'conf' => $sync ? ($stare ? 88 : 99) : 72,
            'stale' => $stare,
            'note' => $sync
                ? 'Rozdíl proti bance může vzniknout jen u plateb, které ještě nejsou zaúčtované.'
                : 'Účty nejsou napojené na banku — zůstatek platí, pokud se zapsal každý pohyb.',
            'fixLabel' => 'Otevřít účty',
            'route' => 'x-ucty',
            'fixToast' => 'Účty a napojení',
        ];
    }

    /** @return array<string, mixed>|null */
    private function radekKnihovna(GallerySpace $prostor): ?array
    {
        $pocet = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('trashed_at')
            ->where('is_archived', false)
            // Trezor ne: rozdíl proti počtu v mřížce by prozradil, kolik v něm je.
            ->where('is_hidden', false)
            ->count();

        if ($pocet === 0) {
            return null;
        }

        return [
            'label' => 'Počet položek v knihovně',
            'value' => $this->cislo($pocet),
            'kind' => 'hard',
            'where' => 'Index knihovny',
            'age' => 'po každém nahrání',
            'conf' => 97,
            'stale' => false,
            'note' => 'Nezahrnuje koš ani karanténu. Fotky bez data se počítají, ale nemají kam patřit.',
            'fixLabel' => 'Úklid knihovny',
            'route' => 'x-uklid',
            'fixToast' => 'Úklid knihovny',
        ];
    }

    /**
     * Fotky, jejichž rok nikdo nezměřil.
     *
     * Obrazovka Datování slibuje, že přijatý odhad „ve Zdraví dat není vidět
     * jako tvrdý údaj" — tohle je to místo, kde se to plní.
     *
     * @return array<string, mixed>|null
     */
    private function radekOdhadnutaData(GallerySpace $prostor): ?array
    {
        if (! Tabulky::sloupec('media_items', 'taken_at_estimated')) {
            return null;
        }

        $pocet = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('trashed_at')
            ->where('is_hidden', false)
            ->where('taken_at_estimated', true)
            ->count();

        if ($pocet === 0) {
            return null;
        }

        return [
            'label' => 'Odhadnutá data fotek',
            'value' => $this->pocet($pocet, 'fotka', 'fotky', 'fotek'),
            'kind' => 'guess',
            'where' => 'Odvozeno z okolních fotek při datování',
            'age' => 'mění se, jen když se datuje',
            // Rok odvozený od sousedů sedí obvykle, den v něm ale nesedí nikdy.
            'conf' => 55,
            'stale' => false,
            'note' => 'Rok bývá správně, den v něm ne — je to první leden. V časové ose proto sedí pořadí, ne přesné datum.',
            'fixLabel' => 'Otevřít datování',
            'route' => 'x-uklid',
            'fixToast' => 'Úklid knihovny → Datování',
        ];
    }

    /** @return array<string, mixed>|null */
    private function radekRozpocet(GallerySpace $prostor): ?array
    {
        $ja = auth()->id();

        if (! $ja) {
            return null;
        }

        /*
         * Cizí osobní rozpočet do přehledu nepatří.
         *
         * `budgets` nese `owner_user_id` a `FinanceAccess::viditelne()` je to
         * pravidlo napsané jednou pro rozpočty i cesty. Tady se vybíralo jen
         * podle prostoru, takže se do „čemu se dá věřit" mohl dostat rozpočet,
         * který druhý z dvojice nesdílel — i s limity a částkou. Smazané řádky
         * se taky počítaly.
         */
        $rozpocet = FinanceAccess::viditelne(
            Budget::withoutGlobalScope(SpaceContext::SCOPE)->where('gallery_space_id', $prostor->id),
            'budget', $ja,
        )
            ->orderByDesc('starts_on')
            ->first(['id', 'currency']);

        if ($rozpocet === null) {
            return null;
        }

        $plan = (float) DB::table('budget_category_limits')->where('budget_id', $rozpocet->id)->sum('amount');

        if ($plan <= 0) {
            return null;
        }

        // Měsíc dvojice: první noc v měsíci je v UTC ještě ten minulý.
        $dnes = Cas::dnes();

        /*
         * Čerpání jako v rozpočtech (`Transaction::countsTowardsBudget`).
         *
         * `type != 'income'` počítalo za útratu i převod mezi vlastními účty,
         * směnu a výběr z bankomatu, k tomu rozepsaný koncept, výdaj ručně
         * vyřazený z rozpočtu, vyrovnání mezi partnery a eura jako koruny.
         * Převod pěti tisíc na spořák tak „snědl" půlku měsíce.
         */
        $hlavniMena = Meny::hlavni($prostor);
        $menaRozpoctu = $this->klicMeny($rozpocet->currency, $hlavniMena);
        $poMenach = [];

        Transaction::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->utraty()
            ->where('excluded_from_budget', false)
            ->where('is_settlement', false)
            // `occurred_at` je datum: jako datum se i porovnává. S časem „00:00:00"
            // by řetězcové srovnání (SQLite) vynechalo útraty z prvního dne v měsíci.
            ->whereBetween('occurred_at', [$dnes->startOfMonth()->toDateString(), $dnes->endOfMonth()->toDateString()])
            ->groupBy('currency_from')
            ->selectRaw('currency_from AS mena, SUM(amount_from) AS soucet')
            ->toBase()
            ->get()
            ->each(function (object $r) use (&$poMenach, $hlavniMena) {
                $mena = $this->klicMeny($r->mena, $hlavniMena);
                $poMenach[$mena] = ($poMenach[$mena] ?? 0.0) + (float) $r->soucet;
            });

        /*
         * Útrata v jiné měně rozpočet čerpá taky — přepočtená a označená.
         *
         * Počítala se jen útrata v měně rozpočtu, takže večeře za 20 € na
         * výletě z korunového rozpočtu neubrala nic a „zbývá" lhalo. Bez kurzu
         * se nepřepočítá nic a řádek řekne, kolik v čísle chybí.
         */
        $utraceno = (float) ($poMenach[$menaRozpoctu] ?? 0.0);
        $jine = array_filter(array_diff_key($poMenach, [$menaRozpoctu => true]), fn (float $c) => abs($c) >= 0.005);
        $prevod = $jine === [] ? null : $this->doMeny($jine, $menaRozpoctu, $prostor);
        $utraceno += $prevod['celkem'] ?? 0.0;
        $jineText = $jine === [] ? '' : ' · '.$this->castky($jine).' '.($prevod['popisek'] ?? 'nezapočteno');

        // Odhad stojí na tom, kolik měsíců má aplikace za sebou. Jeden měsíc
        // dat neumí říct nic o tom, jak měsíc obvykle dopadá.
        $mesicu = $this->mesicuHistorie($prostor);

        return [
            'label' => 'Zbývá v rozpočtu tento měsíc',
            'value' => Meny::castka($plan - $utraceno, $menaRozpoctu),
            'kind' => 'guess',
            'where' => 'Dopočítáno z limitů a zapsaných útrat'.$jineText,
            'rate' => $prevod['popisek'] ?? null,
            'split' => $poMenach ?: new \stdClass,
            'age' => 'přepočet při každém načtení',
            'conf' => min(84, 45 + $mesicu * 4),
            'stale' => false,
            'note' => $mesicu >= 3
                ? 'Odhad počítá s tím, že zbytek měsíce bude jako předchozí. Velký nákup ho rozhodí.'
                : 'Zatím krátká řada zápisů — čím víc měsíců, tím míň se odhad mýlí.',
            'fixLabel' => 'Otevřít rozpočty',
            'route' => 'x-rozpocty',
            'fixToast' => 'Rozpočty',
        ];
    }

    /** @return array<string, mixed>|null */
    private function radekCyklus(GallerySpace $prostor): ?array
    {
        $ja = auth()->id();

        if (! $ja || ! Tabulky::je('cycle_days')) {
            return null;
        }

        /*
         * Jen vlastní záznamy.
         *
         * `Zdravi::dny()` řeší `cycle_settings.share_level` do detailu — bez
         * výslovného souhlasu se partnerovy dny neposílají vůbec a u „jen
         * termíny" se odřezávají příznaky, nálada i poznámka. Tenhle řádek
         * se ptal jen na prostor, takže „Délka cyklu · poslední začátek 3. 9."
         * obešel celé to nastavení. A když zapisují oba, prokládaly se dva
         * cykly do jednoho průměru, ze kterého nevyšlo nic.
         */
        $zacatky = DB::table('cycle_days')
            ->where('gallery_space_id', $prostor->id)
            ->where('user_id', $ja)
            ->where('is_cycle_start', true)
            ->where('is_predicted', false)
            ->orderBy('day')
            ->pluck('day');

        // Ze dvou začátků je jedna délka; z jedné délky se průměr nedělá.
        if ($zacatky->count() < 3) {
            return null;
        }

        $delky = [];
        for ($i = 1; $i < $zacatky->count(); $i++) {
            $delky[] = CarbonImmutable::parse($zacatky[$i - 1])->diffInDays(CarbonImmutable::parse($zacatky[$i]));
        }

        $prumer = array_sum($delky) / count($delky);
        $posledni = CarbonImmutable::parse($zacatky->last());

        return [
            'label' => 'Délka cyklu',
            // „28,4 dne", ale u celého čísla „28 dní" — jinak to nikdo nepřečte.
            'value' => round($prumer, 1) == (int) $prumer
                ? $this->pocet((int) $prumer, 'den', 'dny', 'dní')
                : str_replace('.', ',', (string) round($prumer, 1)).' dne',
            'kind' => 'guess',
            'where' => $this->pocet(count($delky), 'zaznamenaný cyklus', 'zaznamenané cykly', 'zaznamenaných cyklů'),
            'age' => 'poslední začátek '.$posledni->format('j. n.'),
            'conf' => min(88, 50 + count($delky) * 5),
            'stale' => $posledni->lt(now()->subDays(60)),
            'note' => 'Předpověď je tím přesnější, čím delší řada záznamů za ní stojí.',
            'fixLabel' => 'Otevřít cyklus',
            'route' => 'x-cyklus',
            'fixToast' => 'Cyklus',
        ];
    }

    /** @return array<string, mixed>|null */
    private function radekCesta(GallerySpace $prostor): ?array
    {
        if (! Tabulky::je('trip_expenses')) {
            return null;
        }

        /*
         * `trips` měkké mazání nemá — cesta se maže doopravdy.
         *
         * Stála tu podmínka na `deleted_at`. Na MySQL je neznámý sloupec chyba
         * a padla s ní celá skupina `system`: koš, trezor, zámek i oznámení
         * přišly prázdné. SQLite v testech ho tiše vzal jako řetězec a řádek
         * jen chyběl, takže to nikdo neviděl.
         */
        $cesta = DB::table('trips')
            ->where('gallery_space_id', $prostor->id)
            ->orderByDesc('start_date')
            ->first(['id', 'name']);

        if ($cesta === null) {
            return null;
        }

        /*
         * Jen to, co se opravdu utratilo, v hlavní měně.
         *
         * Počítaly se i plánované útraty (rozpočet cesty dopředu) a eura se
         * přičítala ke korunám pod nápisem „Kč". Pak vyhrávala nejčastější
         * měna a ostatní se jen přiznaly. Teď se všechno přepočte kurzem ECB
         * do hlavní měny; bez kurzu se ukáže hlavní měna (nebo nejčastější,
         * když v hlavní nic není) a zbytek se napíše částkou stranou.
         */
        $hlavniMena = Meny::hlavni($prostor);
        $radky = DB::table('trip_expenses')
            ->where('trip_id', $cesta->id)
            ->where('state', 'actual')
            ->groupBy('currency')
            ->selectRaw('currency, COUNT(*) AS pocet, SUM(amount) AS soucet, MAX(occurred_at) AS posledni')
            ->get()
            ->sortByDesc('pocet')
            ->values();

        if ((int) $radky->sum('pocet') === 0) {
            return null;
        }

        $poMenach = [];
        $pocty = [];

        foreach ($radky as $r) {
            $mena = $this->klicMeny($r->currency, $hlavniMena);
            $poMenach[$mena] = ($poMenach[$mena] ?? 0.0) + (float) $r->soucet;
            $pocty[$mena] = ($pocty[$mena] ?? 0) + (int) $r->pocet;
        }

        $soucet = $this->vHlavniMene($poMenach, $prostor);
        // Položek v čísle: po přepočtu všechny, bez kurzu jen ty v ukázané měně.
        $polozek = $soucet['stranou'] === [] ? array_sum($pocty) : ($pocty[$soucet['mena']] ?? 0);
        $posledni = $radky->pluck('posledni')->filter()->max();

        return [
            'label' => 'Útrata na cestě '.$cesta->name,
            'value' => Meny::castka($soucet['hodnota'], $soucet['mena']),
            'kind' => 'manual',
            'where' => 'Zapsáno ručně — '.$this->pocet($polozek, 'položka', 'položky', 'položek')
                .$this->dovetekMen($soucet, 'stranou'),
            'rate' => $soucet['prepocet'],
            'split' => $soucet['poMenach'] ?: new \stdClass,
            'age' => $posledni ? 'poslední zápis '.CarbonImmutable::parse($posledni)->format('j. n.') : 'bez data',
            'conf' => 72,
            'stale' => false,
            'note' => 'Hotovost se na cestě zapisuje z hlavy — pár set stranou je běžné.',
            'fixLabel' => 'Projít útraty',
            'route' => 'x-cesty',
            'fixToast' => 'Cesty a výlety',
        ];
    }

    // ——— kdo sekce živí ———

    /**
     * Kdo do které sekce zapisuje — a která se tři měsíce nežije.
     *
     * `a` je vlastník prostoru, `m` všichni ostatní dohromady. Prototyp kreslí
     * dva pruhy, takže třetí člověk by se do nich nevešel; sečíst je pod jedno
     * jméno je pořád pravda, jen hrubší.
     *
     * @return list<array<string, mixed>>
     */
    private function zivotSekci(GallerySpace $prostor): array
    {
        [$vlastnik, $druhy] = $this->dvojice($prostor);

        if ($vlastnik === null) {
            return [];
        }

        $sekce = [];

        foreach (self::SEKCE as [$id, $nazev, $tabulka, $autor, $cas]) {
            if (! Tabulky::je($tabulka)) {
                continue;
            }

            $radek = DB::table($tabulka)
                ->where('gallery_space_id', $prostor->id)
                /*
                 * Smazané se nepočítá.
                 *
                 * `DB::table` obchází měkké mazání, takže tahle obrazovka
                 * hlásila u knihovny devět položek, zatímco „Zdraví dat"
                 * o kousek vedle šest. Dvě čísla o téže věci na jedné stránce.
                 */
                ->when(Tabulky::sloupec($tabulka, 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
                ->when(Tabulky::sloupec($tabulka, 'trashed_at'), fn ($q) => $q->whereNull('trashed_at'))
                // Trezor se nepočítá — rozdíl proti knihovně by ho prozradil.
                ->when($tabulka === 'media_items', fn ($q) => $q->where('is_hidden', false))
                ->selectRaw('SUM(CASE WHEN '.$autor.' = ? THEN 1 ELSE 0 END) AS a', [$vlastnik->id])
                /*
                 * „Ten druhý" je partner, ne kdokoli jiný.
                 *
                 * Počítalo se všechno, co nezapsal vlastník — i nahrávky hosta
                 * — a u pruhu stálo partnerovo jméno. Bez partnera (sám
                 * v galerii) zůstává součet ostatních pod popiskem „ostatní".
                 */
                ->when(
                    $druhy !== null,
                    fn ($q) => $q->selectRaw('SUM(CASE WHEN '.$autor.' = ? THEN 1 ELSE 0 END) AS m', [$druhy->id]),
                    fn ($q) => $q->selectRaw('SUM(CASE WHEN '.$autor.' IS NOT NULL AND '.$autor.' <> ? THEN 1 ELSE 0 END) AS m', [$vlastnik->id]),
                )
                ->selectRaw('MAX('.$cas.') AS posledni')
                ->first();

            $posledni = $radek->posledni ? CarbonImmutable::parse($radek->posledni) : null;

            $sekce[] = [
                'id' => $id,
                'name' => $nazev,
                'a' => (int) $radek->a,
                'm' => (int) $radek->m,
                'aName' => $vlastnik->name,
                'mName' => $druhy?->name ?? 'ostatní',
                'last' => $posledni ? $this->pred($posledni) : null,
                'cold' => $posledni === null || $posledni->lt(now()->subDays(self::ZIVA_DNI)),
            ];
        }

        return $sekce;
    }

    /**
     * Vlastník a partner — pozice `a`/`m` v `SECLIFE`, vlastník vždy první.
     *
     * Brali se všichni členové prostoru, takže host (vstoupil dřív, nebo měl
     * menší id) seděl na místě partnera a jeho jméno stálo u pruhu dvojice.
     * Deaktivovaný partner zůstává: jeho zápisy jsou pořád jeho.
     *
     * @return array{0: ?object, 1: ?object}
     */
    private function dvojice(GallerySpace $prostor): array
    {
        $lide = DB::table('users as u')
            ->whereIn('u.id', self::idDvojice($prostor))
            ->orderByRaw('CASE WHEN u.id = ? THEN 0 ELSE 1 END', [$prostor->owner_id])
            ->orderBy('u.id')
            ->get(['u.id', 'u.name']);

        return [$lide->first(), $lide->skip(1)->first()];
    }

    // ——— sloupce administrace ———

    /**
     * Rok v číslech: kolik čeho dvojice letos přibylo — a jak proti loňsku.
     *
     * Počítá se z týchž tabulek jako „kdo sekci živí", jen po letech. Podíl
     * v pruhu je poměr k loňsku, ne k vymyšlenému cíli: „o třetinu víc zápisů
     * než loni" je věta, která něco znamená.
     *
     * @return list<array{0: string, 1: string, 2: int, 3: int}>
     */
    private function rokVCislech(GallerySpace $prostor): array
    {
        $letos = Cas::dnes()->startOfYear();
        $loni = $letos->subYear();

        $radky = [];

        foreach (self::SEKCE as [, $nazev, $tabulka, , $cas]) {
            if (! Tabulky::je($tabulka)) {
                continue;
            }

            $pocet = DB::table($tabulka)
                ->where('gallery_space_id', $prostor->id)
                // Jako `zivotSekci`: smazané, koš a trezor se nepočítají — trezor
                // by rozdílem proti knihovně prozradil, kolik v něm přibylo.
                ->when(Tabulky::sloupec($tabulka, 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
                ->when(Tabulky::sloupec($tabulka, 'trashed_at'), fn ($q) => $q->whereNull('trashed_at'))
                ->when($tabulka === 'media_items', fn ($q) => $q->where('is_hidden', false))
                ->selectRaw('SUM(CASE WHEN '.$cas.' >= ? THEN 1 ELSE 0 END) AS letos', [$letos])
                ->selectRaw('SUM(CASE WHEN '.$cas.' >= ? AND '.$cas.' < ? THEN 1 ELSE 0 END) AS loni', [$loni, $letos])
                ->first();

            // Sekce, do které letos nikdo nic nedal, do ročního přehledu
            // nepatří — „nula" je odpověď pro Zdraví sekcí, ne pro tuhle.
            if ((int) $pocet->letos === 0) {
                continue;
            }

            $radky[] = [$nazev, (int) $pocet->letos, (int) $pocet->loni];
        }

        if (! $radky) {
            return [];
        }

        $nejvic = max(array_map(fn (array $r) => $r[1], $radky)) ?: 1;

        return array_map(function (array $r) use ($nejvic) {
            [$nazev, $letos, $loni] = $r;

            return [
                $nazev,
                $this->cislo($letos).($loni ? ' · loni '.$this->cislo($loni) : ' · loni nic'),
                (int) round($letos / $nejvic * 100),
                match (true) {
                    $loni === 0 => 0,
                    $letos > $loni => 0,
                    $letos < $loni => 1,
                    default => 2,
                },
            ];
        }, $radky);
    }

    /**
     * `health` a `risk` — dvě záložky administrace.
     *
     * Ostatní klíče `ABARS` patří jiným obrazovkám a nechávají se být; kolekce
     * se sesypává z víc poskytovatelů a nikdo z nich ji nedodává celou.
     *
     * @return array<string, list<array{0: string, 1: string, 2: int, 3: int}>>
     */
    private function sloupce(GallerySpace $prostor): array
    {
        $panel = $this->uloziste->panel($prostor);
        $zabrano = (int) ($panel['usedBytes'] ?? 0);
        $limit = $panel['limitBytes'] ?? null;
        $procent = $limit ? (int) round($zabrano / $limit * 100) : 0;

        $jednaKopie = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('trashed_at')
            // Jedna kopie znamená „nemá to na Disku své id". Ptát se na
            // `storage_status = 'local_only'` míjelo řádky, které mají
            // `local` nebo výchozí `pending` — tři zápisy téhož.
            ->whereNull('drive_file_id')
            ->selectRaw('COUNT(*) AS pocet, SUM(size_bytes) AS bajtu')
            ->first();

        // Fronta je serverová, ne párová — a záložka „Zdraví systému" se na
        // server taky ptá. Stejná čísla ukazuje i široké rozvržení.
        $chybne = Tabulky::je('failed_jobs')
            ? DB::table('failed_jobs')->where('failed_at', '>=', now()->subWeek())->count()
            : 0;

        $ceka = Tabulky::je('jobs') ? DB::table('jobs')->count() : 0;

        return [
            'health' => [
                ['Zaplněnost úložiště', $panel['label'] ?? $this->gb($zabrano), $procent, $procent >= 90 ? 1 : 0],
                ['Chybové úlohy', $this->pocet($chybne, 'chyba', 'chyby', 'chyb').' za 7 dní', min(100, $chybne * 10), $chybne > 0 ? 1 : 2],
                ['Čeká ve frontě', $this->pocet($ceka, 'úloha', 'úlohy', 'úloh'), min(100, $ceka * 5), $ceka > 0 ? 0 : 2],
                ['Druhá kopie', (string) ($panel['sync'] ?? '—'), ($panel['syncTon'] ?? '') === 'ok' ? 100 : 40,
                    ($panel['syncTon'] ?? '') === 'ok' ? 0 : 1],
            ],
            'risk' => array_values(array_filter([
                ['Zaplněnost úložiště', $panel['label'] ?? $this->gb($zabrano), $procent, $procent >= 90 ? 1 : 0],
                (int) $jednaKopie->pocet > 0
                    ? ['Originály jen v jedné kopii', $this->gb((int) $jednaKopie->bajtu).' · riziko',
                        $zabrano > 0 ? (int) round((int) $jednaKopie->bajtu / $zabrano * 100) : 0, 1]
                    : ['Originály jen v jedné kopii', 'žádné — vše je ve dvou', 0, 2],
                $this->radekPosledniKopie($prostor),
                $this->radekPredpoved($prostor, $zabrano, $limit),
            ], fn ($v) => $v !== null)),
        ] + array_filter(['zprCisla' => $this->rokVCislech($prostor)], fn ($v) => $v !== []);
    }

    /** @return array{0: string, 1: string, 2: int, 3: int}|null */
    private function radekPosledniKopie(GallerySpace $prostor): ?array
    {
        if (! Tabulky::je('storage_operations') || ! Tabulky::je('storage_connections')) {
            return null;
        }

        $kdy = DB::table('storage_operations as o')
            ->join('storage_connections as s', 's.id', '=', 'o.storage_connection_id')
            ->where('s.gallery_space_id', $prostor->id)
            ->where('o.status', 'completed')
            ->max('o.completed_at');

        if (! $kdy) {
            return ['Poslední kopie do cloudu', 'zatím žádná', 0, 1];
        }

        $kdy = CarbonImmutable::parse($kdy);

        return ['Poslední kopie do cloudu', $this->pred($kdy), 100, $kdy->lt(now()->subWeek()) ? 1 : 0];
    }

    /**
     * Za jak dlouho bude plno — z toho, kolik toho přibylo za poslední čtvrtletí.
     *
     * @return array{0: string, 1: string, 2: int, 3: int}|null
     */
    private function radekPredpoved(GallerySpace $prostor, int $zabrano, ?int $limit): ?array
    {
        if ($limit === null || $limit <= $zabrano) {
            return null;
        }

        $pribylo = (int) MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('trashed_at')
            ->where('uploaded_at', '>=', now()->subDays(90))
            ->sum('size_bytes');

        // Bez přírůstku se nedá předpovědět nic — a nula měsíců by lhala.
        if ($pribylo <= 0) {
            return null;
        }

        $mesicne = $pribylo / 3;
        $mesicu = (int) floor(($limit - $zabrano) / $mesicne);

        return [
            'Předpověď zaplnění',
            $mesicu >= 120 ? 'za víc než deset let' : 'za '.$this->pocet($mesicu, 'měsíc', 'měsíce', 'měsíců'),
            (int) round(min(100, 100 - min(100, $mesicu))),
            $mesicu <= 6 ? 1 : 2,
        ];
    }

    // ——— formát ———

    private function pred(CarbonImmutable $kdy): string
    {
        $kdy = Cas::mistni($kdy);
        $minut = $kdy->diffInMinutes(now());

        return match (true) {
            $minut < 60 => 'před '.$this->pocet(max(1, (int) $minut), 'minutou', 'minutami', 'minutami'),
            $kdy->isToday() => 'dnes '.$kdy->format('G:i'),
            $kdy->isYesterday() => 'včera',
            $kdy->gt(now()->subDays(30)) => 'před '.$this->pocet((int) ceil($kdy->diffInDays(now())), 'dnem', 'dny', 'dny'),
            default => $kdy->format('j. n. Y'),
        };
    }

    private function gb(int $bajtu): string
    {
        $gb = $bajtu / 1_000_000_000;

        return $gb >= 1
            ? str_replace('.', ',', (string) round($gb, 1)).' GB'
            : str_replace('.', ',', (string) round($bajtu / 1_000_000, 1)).' MB';
    }

    /**
     * Klíč měny pro součty: velkými písmeny, prázdná je hlavní měna.
     *
     * Starší zápisy měnu nemají — psalo se jen v korunách. Nesmysl, který kódem
     * není, se nechává, jak je: `doHlavni()` ho nepřepočítá a řekne to.
     */
    private function klicMeny(mixed $mena, string $hlavni): string
    {
        $kod = strtoupper(trim((string) $mena));

        return $kod === '' ? $hlavni : $kod;
    }

    /**
     * Součet po měnách jako jedno číslo v hlavní měně — nebo, bez kurzu, jedna měna a zbytek stranou.
     *
     * Bez kurzu se smíšené číslo nesmí ukázat, ani z části přepočtené. Ukáže se
     * hlavní měna, a když v ní nic není, první měna z `$poMenach` (volající je
     * řadí podle toho, co má přednost).
     *
     * @param  array<string, float>  $poMenach
     * @return array{hodnota: float, mena: string, prepocet: ?string, stranou: array<string, float>, poMenach: array<string, float>}
     */
    private function vHlavniMene(array $poMenach, GallerySpace $prostor): array
    {
        $vysledek = $this->kurzy->doHlavni($poMenach, $prostor);
        $soucty = $vysledek['poMenach'];
        // Rozpis s hlavní měnou vpředu; `array_merge` nechá klíč na prvním místě.
        $soucty = isset($soucty[$vysledek['mena']]) ? array_merge([$vysledek['mena'] => $soucty[$vysledek['mena']]], $soucty) : $soucty;

        if ($vysledek['uplne']) {
            return [
                'hodnota' => (float) $vysledek['celkem'],
                'mena' => $vysledek['mena'],
                'prepocet' => $this->kurzy->popisek($vysledek),
                'stranou' => [],
                'poMenach' => $soucty,
            ];
        }

        $mena = array_key_exists($vysledek['mena'], $soucty) ? $vysledek['mena'] : (string) array_key_first($soucty);

        return [
            'hodnota' => (float) ($soucty[$mena] ?? 0.0),
            'mena' => $mena,
            'prepocet' => null,
            'stranou' => array_diff_key($soucty, [$mena => true]),
            'poMenach' => $soucty,
        ];
    }

    /**
     * Částky převedené do měny `$cil`, nebo null, když chybí jediný kurz.
     *
     * Do hlavní měny přes `doHlavni()`. Rozpočet ale smí být i v eurech a
     * `doHlavni()` převádí jen do hlavní měny — tam se kurz bere po měnách
     * z `rate()`, se stejným pravidlem: datum je to nejstarší z použitých.
     *
     * @param  array<string, float>  $castky  měna => částka, bez měny `$cil`
     * @return array{celkem: float, popisek: ?string}|null
     */
    private function doMeny(array $castky, string $cil, GallerySpace $prostor): ?array
    {
        if ($cil === Meny::hlavni($prostor)) {
            $vysledek = $this->kurzy->doHlavni($castky, $prostor);

            return $vysledek['uplne'] ? ['celkem' => (float) $vysledek['celkem'], 'popisek' => $this->kurzy->popisek($vysledek)] : null;
        }

        $celkem = 0.0;
        $datum = null;

        foreach ($castky as $mena => $castka) {
            $kurz = Meny::kod((string) $mena) === null ? null : $this->kurzy->rate((string) $mena, $cil);

            if ($kurz === null) {
                return null;
            }

            $celkem += $castka * $kurz['rate'];
            $datum = $datum === null || $kurz['date'] < $datum ? $kurz['date'] : $datum;
        }

        return ['celkem' => round($celkem, 2), 'popisek' => $this->kurzy->popisek(['prepocteno' => true, 'kurzKeDni' => $datum])];
    }

    /**
     * Dovětek řádku: „ · přepočteno kurzem ECB k …", nebo „ · 1 000 € stranou".
     *
     * @param  array{prepocet: ?string, stranou: array<string, float>}  $soucet  výstup `vHlavniMene()`
     */
    private function dovetekMen(array $soucet, string $slovo): string
    {
        return match (true) {
            $soucet['prepocet'] !== null => ' · '.$soucet['prepocet'],
            $soucet['stranou'] !== [] => ' · '.$this->castky($soucet['stranou']).' '.$slovo,
            default => '',
        };
    }

    /** @param  array<string, float>  $castky  měna => částka; „1 000 € + 20 $" */
    private function castky(array $castky): string
    {
        return implode(' + ', array_map(fn (string $mena, float $castka) => Meny::castka($castka, $mena), array_keys($castky), $castky));
    }

    private function cislo(int $kolik): string
    {
        return number_format($kolik, 0, ',', ' ');
    }

    private function pocet(int $kolik, string $jeden, string $dva, string $pet): string
    {
        return $this->cislo($kolik).' '.match (true) {
            $kolik === 1 => $jeden,
            $kolik >= 2 && $kolik <= 4 => $dva,
            default => $pet,
        };
    }

    private function mesicuHistorie(GallerySpace $prostor): int
    {
        $prvni = DB::table('transactions')
            ->where('gallery_space_id', $prostor->id)
            ->min('occurred_at');

        // Do dneška dvojice: `occurred_at` je datum podle jejích hodin, a s „teď"
        // v UTC by první noc v měsíci měla historie o měsíc méně.
        return $prvni ? (int) floor(CarbonImmutable::parse($prvni)->diffInMonths(Cas::dnes())) : 0;
    }

    /**
     * Akční inbox: co v aplikaci opravdu čeká na zařazení.
     *
     * Obrazovka měla čtyři napsané řádky — „3 originály čekají na přenos",
     * „12 fotek bez data", „Nezařazená transakce 1 240 Kč". U dvojice,
     * která má knihovnu uklizenou, to byla práce, kterou nikdo nemá.
     *
     * Odložené a vyřízené položky (`snoozed`, `inboxDone`) tu nejsou:
     * je to triáž toho, kdo se dívá, a aplikace pro ni tabulku nemá.
     * Zůstávají tam, kde byly — ve stavu, který se ukládá u obou.
     *
     * @return list<array<int, string>>
     */
    private function akcniInbox(GallerySpace $prostor): array
    {
        $radky = [];

        $fotky = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('trashed_at')
            ->where('is_hidden', false);

        $bezData = (clone $fotky)->whereNull('taken_at')->count();

        if ($bezData > 0) {
            $radky[] = [
                $this->pocet($bezData, 'fotka bez data', 'fotky bez data', 'fotek bez data'),
                'knihovna · datum se dá doplnit z okolních dnů',
                'akce',
                null, null, null, null,
                // Osmý prvek je **klíč kategorie**. Prototyp čte první tři,
                // filmy do sedmého; tenhle je za nimi, takže se ukázka nemění.
                // Slouží k tomu, aby odložení nebo vyřešení přežilo změnu
                // počtu: „12 fotek bez data" a „13 fotek bez data" je pořád
                // tentýž řádek a rozhodnutí o něm má platit dál.
                'inbox:fotky-bez-data',
            ];
        }

        $bezMista = (clone $fotky)->whereNull('location_name')->whereNull('latitude')->count();

        if ($bezMista > 0) {
            $radky[] = [
                $this->pocet($bezMista, 'fotka bez místa', 'fotky bez místa', 'fotek bez místa'),
                'knihovna · místo se dá doplnit z téhož dne',
                'akce',
                null, null, null, null, 'inbox:fotky-bez-mista',
            ];
        }

        /*
         * Bez kopie je to, co nemá na Disku své id.
         *
         * Stálo tu `storage_status != 'mirrored'` — jenže `mirrored` nikdo
         * v aplikaci nezapisuje, takže podmínka platila úplně pro všechno
         * a schránka hlásila „čeká na přenos" i u snímků, které jsou na
         * Disku dávno. (Navíc `!=` v SQL nevrátí řádky s `NULL`, takže
         * zrovna ty bez stavu tiše vypadávaly.)
         */
        $nezalohovane = (clone $fotky)->whereNull('drive_file_id')->count();

        if ($nezalohovane > 0 && $this->disky->forSpace($prostor->id)) {
            $radky[] = [
                $this->pocet($nezalohovane, 'originál čeká na přenos', 'originály čekají na přenos', 'originálů čeká na přenos'),
                'úložiště · druhá kopie na Disku',
                'akce',
                null, null, null, null, 'inbox:originaly-bez-kopie',
            ];
        }

        if (Tabulky::je('transactions')) {
            /*
             * Zařadit jde jen výdaj a příjem, a jen živý.
             *
             * Počítaly se i smazané záznamy a převody mezi vlastními účty —
             * ty kategorii nemají a mít nebudou, takže řádek „nezařazené
             * transakce" nešel vyřídit nikdy.
             */
            $bezKategorie = DB::table('transactions')
                ->where('gallery_space_id', $prostor->id)
                ->whereNull('deleted_at')
                ->whereIn('type', Transaction::VYSLEDKOVE)
                ->whereNull('category_id')
                ->count();

            if ($bezKategorie > 0) {
                $radky[] = [
                    $this->pocet($bezKategorie, 'nezařazená transakce', 'nezařazené transakce', 'nezařazených transakcí'),
                    'finance · bez zařazení nesedí rozpočet',
                    'zařadit',
                    null, null, null, null, 'inbox:transakce-bez-kategorie',
                ];
            }
        }

        if (Tabulky::je('travel_inbox_items')) {
            // Čeká jen to, co ještě není v cestě ani v archivu.
            $cesty = DB::table('travel_inbox_items')
                ->where('gallery_space_id', $prostor->id)
                ->whereNotIn('state', ['assigned', 'filed', 'archived'])
                ->count();

            if ($cesty > 0) {
                $radky[] = [
                    $this->pocet($cesty, 'věc v cestovní schránce', 'věci v cestovní schránce', 'věcí v cestovní schránce'),
                    'cesty · čeká na zařazení k cestě',
                    'zařadit',
                    null, null, null, null, 'inbox:cestovni-schranka',
                ];
            }
        }

        // Co je odbyté nebo odložené, v inboxu být nemá.
        $rozhodnute = $this->rozhodnutiInboxu($prostor);

        return array_values(array_filter(
            $radky,
            fn (array $r) => ! isset($rozhodnute[$r[7]]),
        ));
    }

    /**
     * Rozhodnutí o inboxu, která ještě platí.
     *
     * Odložení má datum: po něm se řádek sám vrátí mezi ostatní. Bez toho by
     * z „odložit" bylo tiché smazání a dvojice by se o té věci už nikdy
     * nedozvěděla.
     *
     * @return array<string, object>
     */
    private function rozhodnutiInboxu(GallerySpace $prostor): array
    {
        if (! Tabulky::je('inbox_states')) {
            return [];
        }

        return DB::table('inbox_states')
            ->where('gallery_space_id', $prostor->id)
            ->where(fn ($q) => $q
                ->where('state', 'done')
                /*
                 * Okamžik, ne datum.
                 *
                 * `whereDate` porovnávalo jen den, takže odložení „na dnes
                 * večer" bylo prošlé hned — řádek se vrátil do schránky a
                 * zároveň zůstal v „Odložených", protože ta se o pár řádků
                 * níž ptá `isAfter(now())`. Táž položka na dvou místech.
                 */
                ->orWhere(fn ($v) => $v->where('state', 'snoozed')->where('snoozed_until', '>', now())))
            ->get()
            ->keyBy('item_key')
            ->all();
    }

    /**
     * Odložené a vyřízené řádky inboxu.
     *
     * Obě záložky kreslily ukázku — „Ceny půjčoven aut · odloženo do 1. 9."
     * a „PDF letenky · zařazeno do Jízdenky" u dvojice, která na žádné
     * z toho nesáhla. Ukládá se jen rozhodnutí, takže si text řádku pamatuje
     * ten záznam sám; počty by po čase stejně nesouhlasily.
     *
     * @return array{snoozed: list<array<int, ?string>>, inboxDone: list<array<int, ?string>>}
     */
    private function inboxRozhodnute(GallerySpace $prostor): array
    {
        if (! Tabulky::je('inbox_states')) {
            return ['snoozed' => [], 'inboxDone' => []];
        }

        $jmena = $prostor->members()->pluck('users.name', 'users.id')->all();

        $zaznamy = DB::table('inbox_states')
            ->where('gallery_space_id', $prostor->id)
            ->orderByDesc('updated_at')
            ->limit(60)
            ->get();

        $odlozene = [];
        $hotove = [];

        foreach ($zaznamy as $z) {
            $klic = [null, null, null, null, $z->item_key];

            if ($z->state === 'snoozed' && $z->snoozed_until && CarbonImmutable::parse($z->snoozed_until)->isAfter(now())) {
                $odlozene[] = array_merge([
                    (string) $z->title,
                    'odloženo do '.Cas::mistni($z->snoozed_until)->format('j. n.'),
                    'odloženo',
                ], $klic);

                continue;
            }

            if ($z->state === 'done') {
                $hotove[] = array_merge([
                    (string) $z->title,
                    trim(implode(' · ', array_filter([
                        'vyřešeno',
                        $z->resolved_at ? Cas::mistni($z->resolved_at)->format('j. n.') : null,
                        $jmena[$z->by_user_id] ?? null,
                    ]))),
                    'hotovo',
                ], $klic);
            }
        }

        return ['snoozed' => $odlozene, 'inboxDone' => $hotove];
    }

    /**
     * Správa: účty, plánované úlohy, přístupové klíče a tarify.
     *
     * Všechno to obrazovky kreslily z ukázky — „Klíč …8f2a", „Noční záloha ·
     * poslední běh 3:00" a „Rodinný 200 GB · aktivní" u dvojice, která tarif
     * nemá a klíč nikdy nevydala. Data přitom existují a administrace je
     * ukazuje; jen tyhle čtyři seznamy na ně nebyly napojené.
     *
     * Úlohy a klíče vidí jen správce: klíč je přihlašovací údaj a plánované
     * úlohy jsou vnitřek serveru, ne obsah dvojice.
     *
     * @return array<string, list<array<int, ?string>>>
     */
    private function spravaDoSeznamu(GallerySpace $prostor): array
    {
        $seznamy = [];

        $seznamy['users'] = array_map(fn (array $u) => [
            (string) $u['name'],
            trim(implode(' · ', array_filter([(string) $u['role'], (string) $u['last']]))),
            (string) $u['state'],
        ], $this->sprava->ucty($prostor));

        $tarify = $this->sprava->tarifySeznam();
        $muj = (string) ($this->sprava->soucasnyTarif($prostor) ?? '');

        $seznamy['tarify'] = array_map(fn (array $t) => [
            (string) $t['name'],
            trim(implode(' · ', array_filter([
                (string) $t['id'] === $muj ? 'aktivní' : null,
                $t['gb'] > 0 ? $t['gb'].' GB' : null,
                $t['price'] > 0 ? Meny::castka((float) $t['price'], 'CZK').' měsíčně' : 'zdarma',
            ]))),
            (string) $t['id'] === $muj ? 'aktivní' : 'zařadit',
        ], $tarify);

        /*
         * Kdo správcem není, dostane oba seznamy **prázdné**, ne žádné.
         *
         * Neposlat je by znamenalo nechat na obrazovce ukázku — tedy klíč
         * „Mobilní aplikace · aktivní" a „Noční záloha · hotovo" jako ujištění,
         * že někdo někam přistupuje a zálohy běží.
         */
        $seznamy['jobs'] = [];
        $seznamy['api'] = [];

        // Úlohy běží pro celou instalaci — vidí je provozovatel, ne každý
        // vlastník galerie (viz User::isOperator()).
        if (! auth()->user()?->isOperator()) {
            return $seznamy;
        }

        $seznamy['jobs'] = array_map(fn (array $u) => [
            (string) $u['name'],
            trim(implode(' · ', array_filter([
                (string) $u['cron'],
                $u['last'] !== 'nikdy' ? 'naposledy '.$u['last'] : 'zatím neběžela',
                $u['dur'] !== '—' ? (string) $u['dur'] : null,
            ]))),
            (string) $u['state'],
        ], $this->ulohy->seznam()->all());

        $seznamy['api'] = array_map(fn (array $k) => [
            (string) $k['name'],
            trim(implode(' · ', array_filter([
                /*
                 * Celý klíč se nikdy nikam neposílá — v databázi je jen jeho
                 * otisk. Poslední čtyři znaky stačí, aby se dva rozeznaly.
                 *
                 * U klíčů vydaných dřív, než se poznávací značka začala
                 * ukládat, se nedá doplnit: otevřený text má jen ten, kdo si
                 * ho tenkrát opsal. Píše se to natvrdo místo `…????`, aby si
                 * nikdo nemyslel, že je to část klíče.
                 */
                $k['suffix'] === '????' ? 'bez poznávací značky' : 'klíč …'.$k['suffix'],
                'vytvořen '.$k['made'],
                (string) $k['used'],
            ]))),
            (string) $k['state'],
        ], $this->sprava->klice($prostor));

        return $seznamy;
    }
}
