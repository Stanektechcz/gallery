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

    /** Sloučení částečného patche po klíčích. Hodnoty se nahrazují celé. */
    public function applyPatch(array $patch): void
    {
        $open = $this->data ?? [];
        $priv = $this->private ?? [];

        foreach ($patch as $key => $value) {
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

    /** Co posíláme klientovi — otevřený i šifrovaný stav v jednom objektu. */
    public function toClientArray(): array
    {
        return array_merge($this->data ?? [], $this->private ?? []);
    }
}
