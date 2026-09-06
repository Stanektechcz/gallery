<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoupleState extends Model
{
    protected $fillable = ['couple_id', 'data', 'rev'];

    protected $casts = [
        'data' => 'array',
        // Citlivé klíče (blízkost, děti, opt-iny) doporučujeme držet zvlášť
        // a šifrovaně: 'private' => 'encrypted:array',
        'private' => 'encrypted:array',
        'rev' => 'integer',
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

    /** Sloučení částečného patche po klíčích. Hodnoty se nahrazují celé. */
    public function applyPatch(array $patch): void
    {
        $open = $this->data ?? [];
        $priv = $this->private ?? [];

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
        }

        $this->data = $open;
        $this->private = $priv;
        $this->rev = ($this->rev ?? 0) + 1;
        $this->save();
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
