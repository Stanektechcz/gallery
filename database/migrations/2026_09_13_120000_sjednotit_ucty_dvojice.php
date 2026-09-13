<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jméno a přihlašovací adresy dvojice podle skutečnosti.
 *
 * Účty vznikaly ze seedu a z ruční správy, takže v databázi zůstalo, co se
 * kdy napsalo: Makinka s jiným příjmením, adresy, které nikdo nepoužívá.
 * Aplikace přitom jméno z `users.name` ukazuje všude, kde mluví o dvojici,
 * a adresu na zamykací obrazovce a v přihlášení.
 *
 * Správně je: vlastník prostoru se přihlašuje adresou `info@stanektech.cz`,
 * druhý člen je **Makinka Kubíčková** s adresou `marketa@stanektech.cz`.
 *
 * Migrace, ne příkaz: `deploy.sh` migrace spouští sám, takže oprava dojede
 * s nasazením a nikdo ji nemusí pouštět ručně. Je opatrná — adresu, kterou
 * už má **jiný** účet, nepřepíše (unikátní sloupec by jinak shodil nasazení)
 * a druhého člena mění jen tehdy, když je v prostoru jediný. Heslo ani role
 * se nemění. Na prázdné databázi (testy, nová instalace) nedělá nic.
 */
return new class extends Migration
{
    private const VLASTNIK_EMAIL = 'info@stanektech.cz';

    private const PARTNER_JMENO = 'Makinka Kubíčková';

    private const PARTNER_EMAIL = 'marketa@stanektech.cz';

    public function up(): void
    {
        if (! Schema::hasTable('gallery_spaces') || ! Schema::hasTable('users')) {
            return;
        }

        $prostor = DB::table('gallery_spaces')->orderByDesc('is_default')->orderBy('id')->first(['id', 'owner_id']);

        if (! $prostor || ! $prostor->owner_id) {
            return;
        }

        $this->nastavEmail((int) $prostor->owner_id, self::VLASTNIK_EMAIL);

        $ostatni = DB::table('gallery_space_user')
            ->where('gallery_space_id', $prostor->id)
            ->where('user_id', '!=', $prostor->owner_id)
            ->pluck('user_id');

        // Kdo je „ten druhý", se pozná jen v prostoru o dvou. Při třetím členovi
        // (host, rodina) by jméno mohlo dostat nesprávný účet — radši nic.
        if ($ostatni->count() !== 1) {
            return;
        }

        $partner = (int) $ostatni->first();

        DB::table('users')->where('id', $partner)->update(['name' => self::PARTNER_JMENO, 'updated_at' => now()]);
        $this->nastavEmail($partner, self::PARTNER_EMAIL);
    }

    public function down(): void
    {
        // Předchozí jména a adresy nebyly správné; vracet je není kam.
    }

    private function nastavEmail(int $uzivatel, string $email): void
    {
        $obsazeno = DB::table('users')
            ->whereRaw('LOWER(email) = ?', [strtolower($email)])
            ->where('id', '!=', $uzivatel)
            ->exists();

        if ($obsazeno) {
            return;
        }

        DB::table('users')->where('id', $uzivatel)->update(['email' => $email, 'updated_at' => now()]);
    }
};
