<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

/**
 * Klíč k API. Sanctum ho drží jen jako otisk — `suffix` je poslední čtyři znaky,
 * aby se dal klíč v administraci poznat, aniž by šel použít.
 */
class PersonalAccessToken extends SanctumToken
{
    // Custom scopes: read, upload, albums, export, admin

    protected $fillable = ['name', 'token', 'abilities', 'expires_at', 'suffix'];

    /**
     * Zrušený klíč se **nemaže**.
     *
     * Administrace ho má dál ukazovat jako zrušený — jinak by po kliknutí na
     * „Zrušit" prostě zmizel řádek a nikdo by později nezjistil, který klíč to byl
     * a kdy přestal platit. Prošlá `expires_at` Sanctumu stačí, aby ho nepustil dál.
     */
    public function zrusen(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Nepoužitý tři měsíce. Není to chyba, ale stojí za zrušení. */
    public function necinny(): bool
    {
        $naposledy = $this->last_used_at ?? $this->created_at;

        return $naposledy !== null && $naposledy->lt(now()->subDays(90));
    }
}
