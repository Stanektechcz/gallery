<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Api\Galerie\Concerns\UrcujePar;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CoupleState;
use App\Models\GallerySpace;
use App\Models\User;
use App\Services\Provoz\AdminVeStavu;
use App\Services\Provoz\DarkyVeStavu;
use App\Services\Provoz\DomacnostVeStavu;
use App\Services\Provoz\FilmyVeStavu;
use App\Services\Provoz\FinanceVeStavu;
use App\Services\Provoz\InboxVeStavu;
use App\Services\Provoz\KapsleVeStavu;
use App\Services\Provoz\KlidVeStavu;
use App\Services\Provoz\KucharkaVeStavu;
use App\Services\Provoz\MechanismyVeStavu;
use App\Services\Provoz\MediaVeStavu;
use App\Services\Provoz\NakupyVeStavu;
use App\Services\Provoz\NastaveniVeStavu;
use App\Services\Provoz\OdebraneVStavu;
use App\Services\Provoz\PlanovaniVeStavu;
use App\Services\Provoz\PravidlaVeStavu;
use App\Services\Provoz\PribehVeStavu;
use App\Services\Provoz\RozboryVeStavu;
use App\Services\Provoz\SeznamyVeStavu;
use App\Services\Provoz\TrezorVeStavu;
use App\Services\Provoz\UklidVeStavu;
use App\Services\Provoz\VztahVeStavu;
use App\Services\Provoz\ZdraviVeStavu;
use App\Services\Provoz\ZpravyVeStavu;
use App\Support\Trezor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StateController extends Controller
{
    use UrcujePar;

    /**
     * Seznamy `xRows`, které počítá server — do stavu žádný z nich nepatří.
     *
     * Prototyp čte `xRows[klíč]` přednostně před tím, co dorazilo ze serveru.
     * Uložený seznam by tedy ten serverový navždy zastínil a obrazovka by
     * ukazovala snímek z okamžiku, kdy se naposledy něco kliklo.
     *
     * Seznamy, které tabulku **nemají** (a je jich pár), tu schválně nejsou:
     * pro ně je stav jediné místo, kde můžou přežít.
     */
    private const SERVEROVE_SEZNAMY = [
        'accounts', 'balancing', 'datesGen', 'datesSaved', 'doneTasks', 'dupes',
        'films', 'gifts', 'inbox', 'inboxDone', 'jobs', 'orders', 'placesVisited',
        'placesWish', 'print', 'recipes', 'series', 'shopping', 'snoozed', 'story',
        'tagMerge', 'tarify', 'ticket', 'travelInbox', 'tripsPast', 'tripsPlanned',
        'users', 'vault', 'voice', 'watchlist', 'weekMenu', 'api',
    ];

    public function __construct(
        private readonly AdminVeStavu $sprava,
        private readonly DomacnostVeStavu $domacnost,
        private readonly VztahVeStavu $vztah,
        private readonly ZdraviVeStavu $zdravi,
        private readonly TrezorVeStavu $trezor,
        private readonly PlanovaniVeStavu $planovani,
        private readonly UklidVeStavu $uklid,
        private readonly PravidlaVeStavu $pravidla,
        private readonly RozboryVeStavu $rozbory,
        private readonly ZpravyVeStavu $zpravy,
        private readonly DarkyVeStavu $darky,
        private readonly KapsleVeStavu $kapsle,
        private readonly NastaveniVeStavu $nastaveni,
        private readonly KlidVeStavu $klid,
        private readonly PribehVeStavu $pribeh,
        private readonly MechanismyVeStavu $mechanismy,
        private readonly FilmyVeStavu $filmy,
        private readonly InboxVeStavu $inbox,
        private readonly NakupyVeStavu $nakupy,
        private readonly SeznamyVeStavu $seznamy,
        private readonly MediaVeStavu $media,
        private readonly KucharkaVeStavu $kucharka,
        private readonly FinanceVeStavu $finance,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $state = CoupleState::forCouple($this->parId($request));

        return response()->json([
            'data' => $state->toClientObject(),
            'updated_at' => $state->updated_at?->toIso8601String(),
            'rev' => $state->rev,
            /*
             * Pod kým je tahle karta přihlášená.
             *
             * Klient si čekající zápisy označuje účtem, který je napsal
             * (viz `update`). Token si ale drží v localStorage a otisk prstu ho
             * mění mimo přihlášení heslem — jistotu, komu patří, má jen server.
             */
            'ucet' => $request->user()?->getAuthIdentifier(),
        ]);
    }

    /** Horní mez jednoho zápisu stavu. Celý stav dvojice má desítky kilobajtů. */
    private const NEJVIC_BAJTU = 1_048_576;

    public function update(Request $request): JsonResponse
    {
        /*
         * Stav je jeden řádek, který se čte při každém otevření aplikace.
         *
         * Bez meze do něj šlo zapsat cokoli, co propustí PHP (desítky MB) — a od
         * té chvíle by se každé načtení aplikace u obou táhlo s tím balastem.
         */
        abort_if(strlen((string) $request->getContent()) > self::NEJVIC_BAJTU, 413,
            'Zápis je příliš velký — stav aplikace má jen pár desítek kilobajtů.');

        $validated = $request->validate([
            'data' => ['required', 'array'],
            'rev' => ['nullable', 'integer', 'min:0'],
        ]);

        $uzivatel = $request->user();

        /*
         * Zápis patří účtu, který ho napsal.
         *
         * Co vzniklo bez signálu, čekalo ve frontě prohlížeče — a odešlo
         * s tím, kdo byl přihlášený **v okamžiku odeslání**. Na sdíleném
         * zařízení tak její neodeslané změny po jejím odhlášení a jeho
         * přihlášení skončily v jeho galerii a pod jeho jménem (převodníky
         * zapisují autora podle přihlášeného). Token proti CSRF to nezastaví
         * a střet po klíčích většinou taky ne.
         *
         * Klient proto posílá, pod kým zápis vznikl (`ucet`), a jiný účet se
         * nezapíše vůbec. Starší klient `ucet` neposílá a zapisuje jako dřív.
         * Příznak `jiny_ucet` říká klientovi, že tenhle zápis nemá zkoušet
         * po kouscích znovu — neprojde žádný.
         */
        if ($this->napsalJinyUcet($request->input('ucet'), $uzivatel)) {
            return response()->json([
                'message' => 'Změna vznikla pod jiným účtem, než který je teď přihlášený — nezapsala se.',
                'jiny_ucet' => true,
            ], 422);
        }

        $coupleId = $this->parId($request);

        return DB::transaction(function () use ($validated, $coupleId, $uzivatel, $request) {
            $state = CoupleState::where('couple_id', $coupleId)->lockForUpdate()->first()
                ?? CoupleState::forCouple($coupleId);

            $clientRev = $validated['rev'] ?? null;
            $patch = $validated['data'];
            // Zapamatováno hned tady: převodníky si cestou své klíče z patche
            // vezmou, ale revizi pro ně zapsat musíme (viz `applyPatch`).
            $poslaneKlice = array_keys($patch);

            /*
             * Střet se řeší po klíčích, ne po celém dokumentu.
             *
             * Dřív stačilo, aby druhý z dvojice mezitím změnil cokoliv:
             * server vrátil 409 a **celý patch zahodil**. Obrazovka se
             * překreslila podle serveru a to, co člověk mezitím napsal,
             * zmizelo bez hlášky — jedno ze dvou otevřených zařízení psalo
             * do prázdna.
             *
             * Zahazuje se proto jen to, oč se ti dva opravdu přetahují.
             * Zbytek se zapíše, i když je dokument jako celek novější.
             */
            $strety = $state->strety($patch, $clientRev);

            if ($strety !== []) {
                $patch = array_diff_key($patch, array_flip($strety));
            }

            if ($patch === []) {
                return response()->json([
                    'data' => $state->toClientObject(),
                    'updated_at' => $state->updated_at?->toIso8601String(),
                    'rev' => $state->rev,
                    'conflict' => true,
                    'strety' => $strety,
                ], 409);
            }

            /*
             * Administrace přichází touhle cestou, ne přes `/api/admin`.
             *
             * Prototyp ji celou drží v komponentě a tlačítka jen mění stav — ten
             * se pak uloží jako všechno ostatní. Kdyby se uložil tak, jak přišel,
             * obrazovka by od prvního kliknutí ukazovala něco, co v databázi
             * neplatí. Záměr se proto provede a odpověď nese skutečnost.
             */
            // Pozůstatek po době, kdy se administrace do stavu ukládala. Zahodí se
            // při prvním zápisu, ať tam nezastarává a starší klient z ní nekreslí.
            $state->zapomen(AdminVeStavu::SERVEROVE);

            $skutecnost = [];

            if ($this->sprava->tykaSe($patch)) {
                $skutecnost = $this->sprava->zpracuj($patch, GallerySpace::findOrFail($coupleId), $uzivatel);
                $patch = $this->sprava->bezSpravy($patch);
            }

            /*
             * Domácnost totéž, jen z druhé strany.
             *
             * Dělba práce, lhůty a byt mají vlastní tabulky; kdyby se druhá
             * kopie držela i ve stavu, po prvním kliknutí by se rozešly. Zápis
             * se provede a klíče se ze stavu vyhodí — při dalším načtení si je
             * prototyp vezme z `/api/data/domacnost`.
             */
            if ($this->domacnost->tykaSe($patch)) {
                $this->domacnost->zpracuj($patch, GallerySpace::findOrFail($coupleId));
                $patch = $this->domacnost->bezDomacnosti($patch);
                $state->zapomen(DomacnostVeStavu::SERVEROVE);
            }

            /*
             * A mechanismy vztahu z téhož důvodu.
             *
             * Paměť rozhodnutí, rozvaha před nákupem, protokol nesouhlasu
             * a veto banka nemají v aplikaci jiného vlastníka — tabulka bez
             * zápisu by znamenala jen druhou pravdu.
             */
            if ($this->vztah->tykaSe($patch)) {
                $this->vztah->zpracuj($patch, GallerySpace::findOrFail($coupleId), $uzivatel);
                $patch = $this->vztah->bezVztahu($patch);
                $state->zapomen(VztahVeStavu::SERVEROVE);
            }

            /*
             * Cyklus a nálada.
             *
             * Kalendář cyklu má vlastní modul i tabulku; tohle jen zajišťuje,
             * že zápis z prototypu skončí tam, kde se na něj ptá zbytek
             * aplikace. Jinak by dvojice měla dva kalendáře — jeden
             * v prohlížeči a druhý v databázi.
             */
            if ($this->zdravi->tykaSe($patch)) {
                $this->zdravi->zpracuj($patch, GallerySpace::findOrFail($coupleId), $uzivatel);
                $patch = $this->zdravi->bezZdravi($patch);
                $state->zapomen(ZdraviVeStavu::SERVEROVE);
            }

            /*
             * Trezor.
             *
             * „Dát do trezoru" má fotku schovat doopravdy, ne jen v prohlížeči
             * toho, kdo klikl — druhý z dvojice by ji jinak dál viděl v mřížce.
             */
            if ($this->trezor->tykaSe($patch)) {
                // Vrátit z trezoru do knihovny smí jen ten, kdo ho má odemčený —
                // jinak by heslo k trezoru obešel jeden zápis stavu.
                $odemceno = Trezor::odemcen($request);
                $this->trezor->zpracuj($patch, GallerySpace::findOrFail($coupleId), $odemceno);
                $patch = $this->trezor->bezTrezoru($patch);
            }

            /*
             * Oblíbené a úpravy fotek (popisek, místo, datum, štítky).
             *
             * Zůstávají ve stavu, ale propíšou se i do databáze — jinak by
             * opravené datum fotku přesunulo jen v jednom prohlížeči.
             */
            // Nejdřív se zopakuje, co se nepovedlo minule — teprve pak se
            // zapisuje to nové. Jinak by dluh přebil právě poslanou úpravu.
            $dluh = $this->zaplatDluh($state, $coupleId, $uzivatel);

            if ($this->media->tykaSe($patch)) {
                $dluh += $this->zDluhu($patch, $this->media->zpracuj(
                    $patch, $state->toClientArray(), GallerySpace::findOrFail($coupleId), $uzivatel,
                ));
            }

            /*
             * Zařazení transakcí do kategorií.
             *
             * Obrazovka Transakce ukazovala novou kategorii, rozpočet počítaný
             * z knihy transakci dál vedl jako nezařazenou. Klíč zůstává kvůli
             * tlačítku Zpět; do knihy se změna propíše.
             */
            if ($this->finance->tykaSe($patch)) {
                $dluhFinance = [];
                $puvodni = $patch;
                $patch = $this->finance->zpracuj(
                    $patch, $state->toClientArray(), GallerySpace::findOrFail($coupleId), $dluhFinance,
                );
                $dluh += $this->zDluhu($puvodni, $dluhFinance);
            }

            /*
             * Kalendář a úkoly.
             *
             * Tyhle tabulky **vlastní aplikace sama** — ptají se na ně
             * připomínky, automatizace i cesty. Bez téhle vrstvy má dvojice
             * dva kalendáře: jeden v prohlížeči a druhý v databázi, který
             * o jejích změnách neví.
             */
            if ($this->planovani->tykaSe($patch)) {
                $this->planovani->zpracuj($patch, GallerySpace::findOrFail($coupleId), $uzivatel);
                $patch = $this->planovani->bezPlanovani($patch);
                $state->zapomen(PlanovaniVeStavu::SERVEROVE);
            }

            /*
             * Úklid knihovny.
             *
             * „Pustit" a „Sloučit" měnily jen prohlížeč toho, kdo klikl —
             * originál ležel na disku dál a druhý z dvojice viděl karanténu
             * nedotčenou. Klíče ve stavu zůstávají (drží tlačítko Zpět), ale
             * rozhodnutí se provede v knihovně.
             */
            if ($this->uklid->tykaSe($patch)) {
                $patch = $this->uklid->zpracuj($patch, GallerySpace::findOrFail($coupleId));
            }

            /*
             * Automatizace.
             *
             * `rulesAll()` v prototypu vrací `state.rules || RULEDEF`, takže
             * jedno přepnutí vypínače navždy zastínilo skutečná pravidla:
             * obrazovka od té chvíle kreslila kopii z prohlížeče, nová
             * pravidla nikde nedoběhla a ta skutečná z ní zmizela.
             */
            if ($this->pravidla->tykaSe($patch)) {
                $skutecnost += $this->pravidla->zpracuj($patch, GallerySpace::findOrFail($coupleId), $uzivatel);
                $patch = $this->pravidla->bezPravidel($patch);
                $state->zapomen(PravidlaVeStavu::SERVEROVE);
            }

            /*
             * Sezónní fondy.
             *
             * `seasonVals()` čte `state.season || SEASON`, takže po prvním
             * kliknutí přestal platit `budget_goals` a začala platit kopie —
             * a v Rozpočtech pak stál jiný stav fondu než v jeho vlastní
             * obrazovce.
             */
            if ($this->rozbory->tykaSe($patch)) {
                $skutecnost += $this->rozbory->zpracuj($patch, GallerySpace::findOrFail($coupleId));
                $patch = $this->rozbory->bezRozboru($patch);
                $state->zapomen(RozboryVeStavu::SERVEROVE);
            }

            /*
             * Zprávy.
             *
             * „Zpráva odeslána," řekl prototyp — a nikam ji neodeslal. Bublina
             * se objevila v prohlížeči odesílatele, druhý z dvojice o ní
             * nevěděl a po zavření záložky zmizela.
             */
            if ($this->zpravy->tykaSe($patch)) {
                $skutecnost += $this->zpravy->zpracuj($patch, GallerySpace::findOrFail($coupleId), $uzivatel);
                $patch = $this->zpravy->bezZprav($patch);
                $state->zapomen(ZpravyVeStavu::SERVEROVE);
            }

            /*
             * Dárky a přání.
             *
             * Táž věc: `state.wishes || GIFT_WISHES`. První napsané přání
             * zastínilo celou sekci — druhý o něm nevěděl a po zavření
             * záložky zmizelo.
             */
            if ($this->darky->tykaSe($patch)) {
                $skutecnost += $this->darky->zpracuj($patch, GallerySpace::findOrFail($coupleId), $uzivatel);
                $patch = $this->darky->bezDarku($patch);
                $state->zapomen(DarkyVeStavu::SERVEROVE);
            }

            /*
             * Časové kapsle.
             *
             * Dopis, který má přijít za rok, je přesně ta věc, u které na
             * uložení záleží nejvíc: mezitím se vymění telefon, a s ním celá
             * lokální kopie.
             */
            if ($this->kapsle->tykaSe($patch)) {
                $skutecnost += $this->kapsle->zpracuj($patch, GallerySpace::findOrFail($coupleId), $uzivatel);
                $patch = $this->kapsle->bezKapsli($patch);
                $state->zapomen(KapsleVeStavu::SERVEROVE);
            }

            /*
             * Přepínače nastavení.
             *
             * „Sync každé čtyři hodiny" i „upozornit na duplicitní transakci"
             * měnily jen barvu v prohlížeči toho, kdo klikl — napojení se
             * dál synchronizovalo podle toho, co bylo v databázi.
             */
            if ($this->nastaveni->tykaSe($patch)) {
                $skutecnost += $this->nastaveni->zpracuj($patch, GallerySpace::findOrFail($coupleId), $uzivatel);
                $patch = $this->nastaveni->bezNastaveni($patch);
                $state->zapomen(NastaveniVeStavu::SERVEROVE);
            }

            /*
             * Klid a pohoda.
             *
             * Mapa energie je celá o tom najít okno, kdy mají sílu **oba** —
             * a klepnutí do ní končilo v prohlížeči toho, kdo klikl. Druhý se
             * k ní nedostal, takže se to okno nedalo najít nikdy.
             */
            if ($this->klid->tykaSe($patch)) {
                $skutecnost += $this->klid->zpracuj(
                    $patch,
                    GallerySpace::findOrFail($coupleId),
                    $uzivatel,
                    (array) $state->toClientObject(),
                );
                $patch = $this->klid->bezKlidu($patch);
                $state->zapomen(KlidVeStavu::SERVEROVE);
            }

            /*
             * Příběh, nouzový přístup a papírová záloha.
             *
             * „Soukromé zápisy v deníku se nouzově neodemknou" je rozhodnutí,
             * které musí platit i na druhém zařízení — ne jen v prohlížeči
             * toho, kdo přepínač vypnul.
             */
            if ($this->pribeh->tykaSe($patch)) {
                $skutecnost += $this->pribeh->zpracuj($patch, GallerySpace::findOrFail($coupleId), $uzivatel);
                $patch = $this->pribeh->bezPribehu($patch);
                $state->zapomen(PribehVeStavu::SERVEROVE);
            }

            /*
             * Mechanismy pro dva.
             *
             * Laskavosti, odpuštěné věci, anti-rozpočet, rodina, dvě pravdy.
             * Nebylo to ztracené — leželo to v jednom JSON dokumentu, kam se
             * nedá zeptat. Kolik laskavostí je nevyrovnaných, se z blobu
             * nedozví ani upozornění, ani týdenní přehled.
             */
            if ($this->mechanismy->tykaSe($patch)) {
                $skutecnost += $this->mechanismy->zpracuj($patch, GallerySpace::findOrFail($coupleId), $uzivatel);
                $patch = $this->mechanismy->bezMechanismu($patch);
                $state->zapomen(MechanismyVeStavu::SERVEROVE);
            }

            /*
             * Filmy, seriály a žebříček.
             *
             * Hvězdičky, rozkoukaný díl a pásmo S až F končily ve stavu, takže
             * po zavření záložky byl žebříček zase ukázkový. Klíče jsou tu
             * ale společné s deseti dalšími obrazovkami — vyhazují se proto
             * jen identifikátory titulů, ne celé mapy.
             */
            if ($this->filmy->tykaSe($patch)) {
                $skutecnost += $this->filmy->zpracuj($patch, GallerySpace::findOrFail($coupleId), $uzivatel);
                $patch = $this->filmy->bezFilmu($patch);
                $state->zapomenFilmy(FilmyVeStavu::SEZNAMY);
            }

            /*
             * Seznamy, které mají vlastní tabulku.
             *
             * `xRows` je společný sklad třiceti dvou seznamů; ty, které
             * v aplikaci tabulku mají — nápady na dárky, uložená randíčka,
             * jízdenky, cestovní schránka —, se ukládají do ní. Zbytek klíče
             * projde beze změny: nákupní seznam tabulku nemá a vyhodit ho
             * kvůli sousedovi by znamenalo ho ztratit.
             */
            if ($this->seznamy->tykaSe($patch)) {
                $this->seznamy->zpracuj($patch, GallerySpace::findOrFail($coupleId), $uzivatel);
                $patch = $this->seznamy->bezSeznamu($patch);
            }

            /*
             * Akční inbox.
             *
             * „Vyřešit" jen přeškrtlo řádek v prohlížeči a po obnovení stránky
             * byl zpátky. Ukládá se **rozhodnutí**, ne obsah: samotné řádky se
             * dál počítají z toho, co v aplikaci chybí, takže se seznam sám
             * vyprázdní, jakmile se ta věc opraví.
             */
            if ($this->inbox->tykaSe($patch)) {
                $this->inbox->zpracuj($patch, GallerySpace::findOrFail($coupleId), $uzivatel);
                $patch = $this->inbox->bezInboxu($patch);
                $state->zapomenFilmy(InboxVeStavu::SEZNAMY);
            }

            /*
             * Nákupní seznam.
             *
             * „Koupeno" přeškrtlo řádek jen v prohlížeči. Seznam se přitom
             * počítá ze surovin naplánovaných jídel, takže se sám mění —
             * ukládá se proto jen to, co spočítat nejde: že to někdo koupil.
             */
            if ($this->nakupy->tykaSe($patch)) {
                $this->nakupy->zpracuj($patch, GallerySpace::findOrFail($coupleId), $uzivatel);
                $patch = $this->nakupy->bezNakupu($patch);
                $state->zapomenFilmy(NakupyVeStavu::SEZNAMY);
            }

            /*
             * Menu na týden.
             *
             * Výběr receptu na den zůstával ve stavu prohlížeče, takže se do
             * nákupního seznamu (počítá se z plánu jídel) nikdy nedostal a mapa
             * dnů bez týdne by příští pondělí ukazovala totéž.
             */
            if ($this->kucharka->tykaSe($patch)) {
                $this->kucharka->zpracuj($patch, GallerySpace::findOrFail($coupleId), $uzivatel);
                $patch = $this->kucharka->bezKucharky($patch);
                $state->zapomen(KucharkaVeStavu::SERVEROVE);
            }

            /*
             * A nakonec: **spočítaný seznam nepatří do stavu.**
             *
             * `xRows` je společný sklad třiceti dvou seznamů a dvacet z nich
             * teď server počítá z tabulek. Prototyp přitom čte `xRows[klíč]`
             * přednostně před tím, co dorazilo ze serveru — takže jakmile se
             * takový seznam jednou uložil do stavu, obrazovka ho odtamtud
             * kreslila napořád. Vyřešený řádek inboxu se vracel na místo,
             * odškrtnutá položka se odškrtávala znovu a nikdo nepoznal proč.
             *
             * Zapisovače výš si své klíče uklidí samy; tohle je pojistka pro
             * ty, které tabulku mají, ale zápis z prototypu k nim nevede —
             * ten seznam se dá jen číst a psát se do něj má jinudy.
             */
            if (is_array($patch['xRows'] ?? null)) {
                $patch['xRows'] = array_diff_key($patch['xRows'], array_flip(self::SERVEROVE_SEZNAMY));

                if ($patch['xRows'] === []) {
                    unset($patch['xRows']);
                }
            }

            $state->zapomenFilmy(self::SERVEROVE_SEZNAMY);
            // Rozdíl pro převodníky (co prohlížeč odebral) do stavu nepatří.
            unset($patch[OdebraneVStavu::KLIC], $patch[OdebraneVStavu::ZMENENE]);
            // Klíče tak, jak přišly — i ty, které si cestou vzaly převodníky.
            // Bez nich by pro klíče vedené v tabulkách nevznikla revize
            // a jejich střet by se nedal poznat (viz `CoupleState::applyPatch`).
            $state->applyPatch($this->sPuvodnimTvarem($patch, $request), $poslaneKlice);
            // Až po uložení stavu: dluh se vede proti tomu, co v něm leží.
            $state->zapisDluh($dluh);

            return response()->json([
                // Skutečnost se vrací, ale **neukládá**: administrace má jediný
                // zdroj pravdy, a to `/api/admin`. Druhá kopie ve stavu by se
                // dřív nebo později rozešla s tou první.
                'data' => (object) array_merge((array) $state->toClientObject(), $skutecnost),
                /*
                 * A klient si ji nemá ukládat ani k sobě.
                 *
                 * Do lokální kopie patří jen to, co server uložil. Uložená
                 * skutečnost by se při dalším spuštění postavila před data
                 * ze serveru — tedy přesně to, čemu se tahle vrstva vyhýbá.
                 */
                'docasne' => array_keys($skutecnost),
                /*
                 * Co se nezapsalo, protože to mezitím změnil ten druhý.
                 *
                 * Klient o tom musí říct. Tiché zahození je horší než střet:
                 * člověk vidí obrazovku, která se sama vrátila o krok zpět,
                 * a nemá jak poznat proč.
                 */
                'strety' => $strety,
                'updated_at' => $state->updated_at?->toIso8601String(),
                'rev' => $state->rev,
            ]);
        });
    }

    /**
     * Jestli zápis ohlásil jiného autora, než kdo je přihlášený.
     *
     * Porovnává se jako text: z prohlížeče přijde `"7"` i `7` podle toho,
     * odkud si klient identifikátor vzal. Bez údaje se nic neodmítá — to je
     * starší klient.
     */
    private function napsalJinyUcet(mixed $ucet, ?User $uzivatel): bool
    {
        if ($ucet === null || $ucet === '') {
            return false;
        }

        if (! is_scalar($ucet) || $uzivatel === null) {
            return true;
        }

        return (string) $ucet !== (string) $uzivatel->getAuthIdentifier();
    }

    /**
     * Zopakuje zápisy, které se minule nepovedly.
     *
     * Převodníky počítají, co zapsat, jako rozdíl patche proti stavu **před**
     * uložením. Stav se ale uloží i tehdy, když zápis do tabulek spadne — při
     * dalším požadavku tedy žádný rozdíl není a úprava je pryč natrvalo, jen
     * obrazovka dál ukazuje popisek, který v knihovně nikdy nebyl.
     *
     * Dluh se proto zapisuje do stavu a tady se platí: hodnota se vezme
     * z uloženého stavu a předchozí se předá **prázdná**, takže se všechno
     * z toho klíče zapíše znovu. Oba zápisy jsou idempotentní (`insertOrIgnore`
     * a `update` na tutéž hodnotu), takže zopakování nic nerozbije.
     *
     * @return list<string> co se nepovedlo ani teď
     */
    private function zaplatDluh(CoupleState $state, int $coupleId, ?User $uzivatel): array
    {
        $dluh = $state->dluh();

        if ($dluh === [] || $uzivatel === null) {
            return $dluh;
        }

        $prostor = GallerySpace::findOrFail($coupleId);
        $zbyva = [];

        $media = array_intersect_key($dluh, array_flip(['favs', 'edits']));

        if ($media !== []) {
            $zbyva += $this->zDluhu($media, $this->media->zpracuj($media, [], $prostor, $uzivatel));
        }

        if ($this->finance->tykaSe($dluh)) {
            $dluhFinance = [];
            $this->finance->zpracuj($dluh, [], $prostor, $dluhFinance);
            $zbyva += $this->zDluhu($dluh, $dluhFinance);
        }

        return $zbyva;
    }

    /**
     * Ze jmen klíčů udělá dluh i s hodnotami.
     *
     * Dluh musí nést, **co** se má zapsat, ne jen který klíč selhal: `favs` se
     * do společného dokumentu schválně neukládá, takže by při dalším
     * požadavku nebylo odkud tu hodnotu vzít.
     *
     * @param  array<string, mixed>  $patch
     * @param  list<string>  $klice
     * @return array<string, mixed>
     */
    private function zDluhu(array $patch, array $klice): array
    {
        return array_intersect_key($patch, array_flip($klice));
    }

    /**
     * Vrátí hodnotám tvar, ve kterém přišly — objekt zůstane objektem.
     *
     * PHP mezi `{}` a `[]` po `json_decode(..., true)` nerozliší: prázdný
     * objekt i prázdné pole jsou prázdné pole, a `{"0":"x"}` je totéž co
     * `["x"]`. Při odeslání zpátky z toho `json_encode` udělá pole — a klient,
     * který porovnává obsah přes `JSON.stringify`, uvidí rozdíl, který sám
     * nezpůsobil. Zapíše tedy znovu, server zase odpoví polem, a takhle
     * dokola.
     *
     * Ta smyčka byla tichá a drahá: aplikace při nečinnosti posílala kolem
     * čtyřiceti `PATCH /api/state` za minutu s pořád stejným obsahem, revize
     * stavu vyšplhala do desetitisíců — a na serveru to WAF po sto dvaceti
     * požadavcích za minutu vyhodnotil jako útok a zablokoval adresu, ze které
     * se dvojice dívala. Aplikace pak nešla načíst vůbec.
     *
     * Tvar se bere z těla požadavku, kde ještě je. Hodnotu, kterou po cestě
     * přepsala některá z vrstev zápisu, necháváme být — ta už není to, co
     * přišlo od klienta.
     *
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    private function sPuvodnimTvarem(array $patch, Request $request): array
    {
        $puvodni = json_decode((string) $request->getContent())->data ?? null;

        if (! $puvodni instanceof \stdClass) {
            return $patch;
        }

        foreach ($patch as $klic => $hodnota) {
            if (! property_exists($puvodni, (string) $klic)) {
                continue;
            }

            $sTvarem = $puvodni->{$klic};

            // Jen když jde pořád o tutéž hodnotu, jen jinak zapsanou.
            if (json_decode(json_encode($sTvarem), true) == $hodnota) {
                $patch[$klic] = $sTvarem;
            }
        }

        return $patch;
    }

    /**
     * Smazat celý stav — oběma najednou.
     *
     * Rozhraní tuhle cestu nevolá; zůstala pro `GalerieApi.reset()`. Smí ji jen
     * vlastník prostoru a zapisuje se do protokolu: jedním požadavkem zmizí
     * nastavení i rozepsané věci druhého z dvojice.
     */
    public function destroy(Request $request): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));
        abort_unless((int) $prostor->owner_id === (int) $request->user()->id, 403,
            'Společný stav může smazat jen vlastník galerie.');
        AuditLog::record('galerie.state.reset', $prostor);

        /*
         * `forceFill`, ne `update`: `private` a `rev_keys` nejsou v `$fillable`,
         * takže je Eloquent tiše zahodil. Reset pak nechal v šifrovaném sloupci
         * to nejcitlivější (blízkost, postoj k dětem, odchod) a `rev_keys`
         * s vysokými revizemi proti `rev = 0` — další zápis skončil konfliktem.
         */
        $state = CoupleState::forCouple($prostor->id);
        $state->forceFill(['data' => [], 'private' => [], 'rev' => 0, 'rev_keys' => []])->save();

        return response()->json(['data' => (object) [], 'rev' => 0]);
    }
}
