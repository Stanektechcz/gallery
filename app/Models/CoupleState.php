<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoupleState extends Model
{
    protected $fillable = ['couple_id', 'data', 'rev', 'rev_keys'];

    protected $casts = [
        'data' => 'array',
        // Citlivé klíče (blízkost, děti, opt-iny) doporučujeme držet zvlášť
        // a šifrovaně: 'private' => 'encrypted:array',
        'private' => 'encrypted:array',
        'rev' => 'integer',
        // `klíč => revize, ve které se naposledy změnil`
        'rev_keys' => 'array',
    ];

    /**
     * Klíče, které se neukládají vůbec.
     *
     * Klient si sám hlídá, co na server neposílá (`persistSkip`), a hesla ani
     * kódy mezi tím nejsou — až na `vaultPwd`, které v tom seznamu chybí. Heslo
     * k trezoru se tedy při psaní odešle a zůstalo by v databázi otevřeně ležet.
     *
     * Server se na kázeň klienta spoléhat nemá: mobilní aplikace, starší verze
     * i překlep v jednom seznamu jsou tři různé cesty, jak sem heslo poslat.
     * Zahazuje se proto tady, kde je to jedno místo pro všechny.
     */
    public const NEUKLADAT = [
        'vaultPwd', 'lockPwd', 'lockPin', 'lockRec', 'lockRecCode',
        'gatePin', 'gvPwd', 'admNewMail', 'admNewKeyName',
        // Příznak „právě kontroluji" patří k jednomu kliknutí, ne do sdíleného
        // stavu: uložený by po obnovení stránky nechal viset „Kontroluji…".
        'admChecking',
    ];

    /**
     * Kolik posledních událostí z proudu `events` stav drží.
     *
     * Klient proud jen přidával. Hláška, která se opakovala každé čtyři
     * vteřiny, ho nafoukla na 4 400 položek a 800 KB — a ten celý stav šel
     * s každým zápisem i načtením. Víc než pár set posledních změn nikdo
     * nečte.
     */
    public const UDALOSTI_STROP = 200;

    /** Klíče, které patří do šifrovaného sloupce, ne do otevřeného JSONu. */
    public const PRIVATE_KEYS = [
        'blizWA', 'blizWM', 'blizAdd', 'blizBlock',
        'kidsStance', 'kidsYear', 'kidsFixed', 'kidsTalks',
        'optIn', 'exitMade', 'exitOff', 'exitWho',
        'svedAdj', 'mineInc', 'mineEven',
    ];

    public static function forCouple(int $coupleId): self
    {
        return static::firstOrCreate(
            ['couple_id' => $coupleId],
            ['data' => [], 'private' => [], 'rev' => 0]
        );
    }

    /**
     * Zahodí klíče, které do stavu nepatří.
     *
     * Používá se na pozůstatky: administrace se do stavu chvíli ukládala, než
     * dostala vlastní adresu, a uložená kopie by tam jinak ležela navždy —
     * zastarávala by a starší klient by z ní kreslil.
     *
     * @param  list<string>  $klice
     */
    public function zapomen(array $klice): bool
    {
        $otevrene = $this->data ?? [];
        $zbyle = array_diff_key($otevrene, array_flip($klice));

        if (count($zbyle) === count($otevrene)) {
            return false;
        }

        $this->zapisData($zbyle);
        $this->save();

        return true;
    }

    /**
     * Uloží otevřená data a **nezměněné klíče nechá v tvaru, v jakém ležely**.
     *
     * Přetypování `data` čte JSON asociativně, takže každé uložení přes
     * `$this->data = …` převedlo `{}` na `[]` u všech klíčů — i u těch, na které
     * zápis vůbec nesahal. Oprava v kontroleru hlídala jen klíč, který zrovna
     * přišel. Stačilo, aby partner změnil cokoli jiného, a `shared.txCat` se
     * vrátil jako pole; klient to viděl jako změnu, zapsal znovu, a každých
     * dvacet vteřin (tak často se stav načítá) šly ven dva zbytečné PATCHe.
     *
     * Klíče z `$zapsane` přišly od klienta a berou se tak, jak přišly — i když
     * klient `{}` vědomě vyměnil za `[]`, jinak by se smyčka jen otočila.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string|int>  $zapsane
     */
    private function zapisData(array $data, array $zapsane = []): void
    {
        $surove = $this->surovy('data');

        foreach ($data as $klic => $hodnota) {
            if ($hodnota instanceof \stdClass || ! array_key_exists($klic, $surove) || in_array($klic, $zapsane, true)) {
                continue;
            }

            // Tatáž hodnota, jen dekódovaná asociativně: vezme se původní zápis.
            if (json_encode(json_decode(json_encode($surove[$klic]), true)) === json_encode($hodnota)) {
                $data[$klic] = $surove[$klic];
            }
        }

        $this->attributes['data'] = json_encode($data === [] ? new \stdClass : $data);

        /*
         * Eloquent pozná změnu JSON sloupce podle dekódovaného obsahu — a pro
         * něj jsou `{}` a `[]` totéž. Změna jen tvaru by se tak neuložila
         * vůbec. Bez původní hodnoty se sloupec bere jako změněný.
         */
        if ($this->attributes['data'] !== $this->getRawOriginal('data')) {
            unset($this->original['data']);
        }
    }

    /**
     * Zapomene jen řádky filmů — klíče, ve kterých leží i cizí obrazovky.
     *
     * `xRows` drží nákupní seznam i nápady na dárky, `rowDone` odškrtnuté
     * řádky napříč aplikací. Vyhodit je celé kvůli jednomu žebříčku by
     * znamenalo smazat věci, které tabulku nemají a jinde než tady nejsou.
     *
     * @param  list<string>  $seznamy  klíče `xRows` a předpony identifikátorů
     */
    public function zapomenFilmy(array $seznamy): bool
    {
        $data = $this->data ?? [];
        $puvodni = json_encode($data);

        if (is_array($data['xRows'] ?? null)) {
            $data['xRows'] = array_diff_key($data['xRows'], array_flip($seznamy));

            if ($data['xRows'] === []) {
                unset($data['xRows']);
            }
        }

        $patri = function (string $id) use ($seznamy): bool {
            foreach ($seznamy as $seznam) {
                if (str_starts_with($id, $seznam.'-')) {
                    return true;
                }
            }

            return false;
        };

        foreach (['tierMap', 'fmRate', 'fmEp', 'rowDone'] as $klic) {
            if (! is_array($data[$klic] ?? null)) {
                continue;
            }

            $data[$klic] = array_filter($data[$klic], fn (string $id) => ! $patri($id), ARRAY_FILTER_USE_KEY);

            if ($data[$klic] === []) {
                unset($data[$klic]);
            }
        }

        unset($data['tierOrder']);

        if (json_encode($data) === $puvodni) {
            return false;
        }

        $this->zapisData($data);
        $this->save();

        return true;
    }

    /** Sloučení částečného patche po klíčích. Hodnoty se nahrazují celé. */
    public function applyPatch(array $patch): void
    {
        // Surově: klíče, které patch nemění, se uloží přesně tak, jak ležely.
        $open = $this->surovy('data');
        $priv = $this->private ?? [];
        $revize = $this->rev_keys ?? [];
        $nova = ($this->rev ?? 0) + 1;

        foreach ($patch as $key => $value) {
            // Hesla a kódy se zahazují, ať přijdou odkudkoli — viz NEUKLADAT.
            if (in_array($key, self::NEUKLADAT, true)) {
                continue;
            }

            if ($key === 'events' && is_array($value) && array_is_list($value) && count($value) > self::UDALOSTI_STROP) {
                $value = array_slice($value, 0, self::UDALOSTI_STROP);
            }

            if (in_array($key, self::PRIVATE_KEYS, true)) {
                $priv[$key] = $value;
            } else {
                $open[$key] = $value;
            }

            // U kterého klíče se to stalo. Bez toho se nedá poznat střet
            // o tutéž věc od změny něčeho jiného.
            $revize[$key] = $nova;
        }

        $this->zapisData($open, array_keys($patch));
        $this->private = $priv;
        $this->rev_keys = $revize;
        $this->rev = $nova;
        $this->save();
    }

    /**
     * Klíče, o které se dva klienti přetahují.
     *
     * Klient staví na revizi `$odRevize`. Klíč, který se od té doby změnil,
     * měnil někdo jiný — a přepsat ho znamená zahodit jeho práci. Klíč, který
     * se od té doby nezměnil, se zapsat může, i když je dokument jako celek
     * novější: ten rozdíl je celý smysl téhle metody.
     *
     * @param  array<string, mixed>  $patch
     * @return list<string>
     */
    public function strety(array $patch, ?int $odRevize): array
    {
        if ($odRevize === null) {
            return [];
        }

        $revize = $this->rev_keys ?? [];
        $strety = [];

        foreach (array_keys($patch) as $klic) {
            if (($revize[$klic] ?? 0) > $odRevize) {
                $strety[] = (string) $klic;
            }
        }

        return $strety;
    }

    /**
     * Co posíláme klientovi — otevřený i šifrovaný stav v jednom objektu.
     *
     * Vrací se **objekt**, ne pole. Prázdné pole se do JSONu zapíše jako `[]`
     * a klient si ho vezme jako svou lokální kopii; jenže `JSON.stringify` u pole
     * ukládá jen číselné indexy, takže první uložení do prohlížeče zahodí všechno,
     * co do něj mezitím přibylo. Projevilo by se to jen u nového páru, jehož stav
     * je ještě prázdný — tedy přesně tam, kde si toho nikdo nevšimne.
     */
    public function toClientObject(): object
    {
        /*
         * Čte se **surový JSON**, ne přetypovaná kopie.
         *
         * Přetypování `'data' => 'array'` dekóduje asociativně, a tím se ztratí
         * rozdíl mezi `{}` a `[]`: prázdný objekt i prázdné pole jsou v PHP
         * prázdné pole a při odeslání zpátky z obojího vyjde `[]`. Klient
         * porovnává obsah přes `JSON.stringify`, uvidí rozdíl, který sám
         * nezpůsobil, zapíše znovu — a server zase odpoví polem.
         *
         * Ta smyčka byla tichá a drahá: aplikace při nečinnosti posílala kolem
         * čtyřiceti `PATCH /api/state` za minutu s pořád stejným obsahem,
         * revize stavu vyšplhala do desetitisíců, a na serveru to WAF po sto
         * dvaceti požadavcích za minutu vyhodnotil jako útok a zablokoval
         * adresu, ze které se dvojice dívala. Aplikace pak nešla načíst vůbec.
         */
        $ven = new \stdClass;

        foreach ([$this->surovy('data'), $this->private ?? []] as $cast) {
            foreach ((array) $cast as $klic => $hodnota) {
                $ven->{$klic} = $hodnota;
            }
        }

        // Už uložený nafouknutý proud se posílá zkrácený hned, ne až po dalším zápisu.
        if (is_array($ven->events ?? null) && count($ven->events) > self::UDALOSTI_STROP) {
            $ven->events = array_slice($ven->events, 0, self::UDALOSTI_STROP);
        }

        return $ven;
    }

    /**
     * Uložený sloupec tak, jak leží v databázi — objekty zůstanou objekty.
     *
     * @return array<string, mixed>
     */
    private function surovy(string $sloupec): array
    {
        $json = $this->getAttributes()[$sloupec] ?? null;

        if (! is_string($json) || $json === '') {
            return (array) ($this->{$sloupec} ?? []);
        }

        $rozlozene = json_decode($json);

        // Poškozený JSON není důvod poslat prázdno: přetypovaná kopie je pořád
        // lepší než nic, jen bez rozlišení `{}` a `[]`.
        return $rozlozene instanceof \stdClass
            ? get_object_vars($rozlozene)
            : (array) ($this->{$sloupec} ?? []);
    }

    /** Totéž pro čtení v PHP, kde na rozdílu mezi polem a objektem nezáleží. */
    public function toClientArray(): array
    {
        return array_merge($this->data ?? [], $this->private ?? []);
    }
}
