<?php

namespace App\Services\Obsah;

use App\Services\Automation\AutomationEngine;

/**
 * Slovník mezi pravidly, jak je nabízí prototyp, a tím, co umí motor.
 *
 * Prototyp nabízí pět spouštěčů a pět akcí, motor umí tři a dvě — a i z těch
 * se některé potkávají jen jménem. Slovník je proto na jednom místě a v obou
 * směrech: obrazovka i zápis by se jinak dřív nebo později rozešly a pravidlo
 * uložené jako „výdaj nad limit" by se načetlo jako „konec týdne".
 *
 * Co motor spustit neumí, se **stejně uloží**. Je to záměr dvojice a ten se
 * nezahazuje; jen se u něj neříká, že běží.
 */
class SlovnikPravidel
{
    /** Spouštěč v databázi → druh, který prototyp kreslí. */
    private const SPOUSTECE = [
        'media.uploaded' => 'tag',
        'todo.completed' => 'task',
        'event.created' => 'anniv',
        'finance.limit_exceeded' => 'spend',
        'week.ended' => 'week',
    ];

    /** Akce v databázi → druh, který prototyp kreslí. */
    private const AKCE = [
        'todo.create' => 'task',
        'journal.entry' => 'diary',
        'album.add' => 'album',
        'notify.send' => 'notify',
        'digest.build' => 'digest',
    ];

    /**
     * Pole podmínky, do kterého patří to, co dvojice napsala do „cíle".
     *
     * Motor porovnává proti tomu, co mu přijde v podnětu; pole, které v podnětu
     * není, znamená, že pravidlo nikdy nesedne.
     */
    private const POLE = [
        'media.uploaded' => 'filename',
        'todo.completed' => 'title',
        'event.created' => 'days_ahead',
        'finance.limit_exceeded' => 'amount',
        'week.ended' => null,
    ];

    public function spoustecVen(string $spoustec): string
    {
        return self::SPOUSTECE[$spoustec] ?? 'week';
    }

    public function spoustecDovnitr(string $druh): string
    {
        return array_search($druh, self::SPOUSTECE, true) ?: 'week.ended';
    }

    public function akceVen(string $akce): string
    {
        return self::AKCE[$akce] ?? 'notify';
    }

    public function akceDovnitr(string $druh): string
    {
        return array_search($druh, self::AKCE, true) ?: 'notify.send';
    }

    public function pole(string $spoustec): ?string
    {
        return self::POLE[$spoustec] ?? null;
    }

    /**
     * Umí to aplikace doopravdy spustit?
     *
     * Nestačí, že motor spouštěč zná — musí k němu i něco dostat. „Foto
     * s tagem" se nedá porovnat, protože podnět z nahrání fotky žádné tagy
     * nenese; tag se na ni věší až potom.
     */
    public function umiSpustit(string $spoustec, string $akce): bool
    {
        return isset(AutomationEngine::TRIGGERS[$spoustec])
            && isset(AutomationEngine::ACTIONS[$akce])
            && in_array($spoustec, ['todo.completed', 'event.created'], true);
    }

    /** Věta, kterou obrazovka napíše místo času posledního běhu. */
    public function proc(string $spoustec, string $akce): string
    {
        if (! isset(AutomationEngine::TRIGGERS[$spoustec])) {
            return 'tenhle spouštěč aplikace zatím nesleduje';
        }

        if (! isset(AutomationEngine::ACTIONS[$akce])) {
            return 'tuhle akci aplikace zatím neumí provést';
        }

        return 'nahrání fotky zatím nenese tagy, podle kterých se pozná';
    }
}
