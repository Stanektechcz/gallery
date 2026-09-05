<?php

namespace App\Http\Controllers\Api\Galerie\Concerns;

use Illuminate\Http\Request;

/**
 * Který pár tenhle požadavek patří.
 *
 * Scaffold prototypu počítal se sloupcem `users.couple_id`. Aplikace ale identitu
 * dvojice už má — je to prostor galerie. Zavést druhou by znamenalo dvě pravdy
 * o tomtéž: jeden člověk by mohl být ve `gallery_space` číslo 1 a zároveň
 * v `couple` číslo 3, a nikdo by nevěděl, které z nich platí pro fotky a které
 * pro rozpočet.
 *
 * Odvozuje se proto z prostoru. Kdo prostor nemá, nemá ani stav — a `null` se
 * nevrací, protože volající by ho stejně musel ošetřit ve všech čtyřech místech.
 */
trait UrcujePar
{
    protected function parId(Request $request): int
    {
        $prostor = $request->user()?->gallerySpaces()->first();

        abort_if($prostor === null, 404,
            'Účet zatím nepatří do žádného společného prostoru. Založte ho nebo přijměte pozvánku.');

        return (int) $prostor->id;
    }
}
