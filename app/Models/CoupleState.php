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

        $this->data = $zbyle;
        $this->save();

        return true;
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

        $this->data = $data;
        $this->save();

        return true;
    }

    /** Sloučení částečného patche po klíčích. Hodnoty se nahrazují celé. */
    public function applyPatch(array $patch): void
    {
        $open = $this->data ?? [];
        $priv = $this->private ?? [];
        $revize = $this->rev_keys ?? [];
        $nova = ($this->rev ?? 0) + 1;

        foreach ($patch as $key => $value) {
            // Hesla a kódy se zahazují, ať přijdou odkudkoli — viz NEUKLADAT.
            if (in_array($key, self::NEUKLADAT, true)) {
                continue;
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

        $this->data = $open;
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
        return (object) array_merge($this->data ?? [], $this->private ?? []);
    }

    /** Totéž pro čtení v PHP, kde na rozdílu mezi polem a objektem nezáleží. */
    public function toClientArray(): array
    {
        return array_merge($this->data ?? [], $this->private ?? []);
    }
}
