<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tři sloupce pro zabezpečení účtu.
 *
 * - `users.invitation_sent_at` — pozvánka platí týden od odeslání. Odkaz bez
 *   data platil navždy, takže starý e-mail v cizí schránce byl pořád klíč.
 * - `users.two_factor_last_step` — poslední přijatý časový krok TOTP. Kód
 *   z aplikace platí ±30 s, a bez téhle paměti šel tentýž kód použít znovu.
 * - `webauthn_credentials.personal_access_token_id` — ke kterému přihlášení
 *   (tokenu) otisk patří. Bez vazby „Odhlásit ostatní" nevěděl, který otisk je
 *   „tohohle zařízení", a otisky přežily odhlášení i obnovu hesla.
 *
 * Všechno nullable: MySQL ve striktním režimu jinak odmítne přidat sloupec
 * do tabulky, která už řádky má.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'invitation_sent_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('invitation_sent_at')->nullable();
            });

            // Čekající pozvánky dostanou týden ode dneška — jinak by po nasazení
            // přestaly platit všechny naráz, i ta odeslaná včera.
            DB::table('users')
                ->whereNotNull('invitation_token')
                ->whereNull('invitation_accepted_at')
                ->update(['invitation_sent_at' => now()]);
        }

        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'two_factor_last_step')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedBigInteger('two_factor_last_step')->nullable();
            });
        }

        if (Schema::hasTable('webauthn_credentials') && ! Schema::hasColumn('webauthn_credentials', 'personal_access_token_id')) {
            Schema::table('webauthn_credentials', function (Blueprint $table) {
                $table->unsignedBigInteger('personal_access_token_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('webauthn_credentials', 'personal_access_token_id')) {
            Schema::table('webauthn_credentials', function (Blueprint $table) {
                $table->dropIndex(['personal_access_token_id']);
                $table->dropColumn('personal_access_token_id');
            });
        }

        foreach (['two_factor_last_step', 'invitation_sent_at'] as $sloupec) {
            if (Schema::hasColumn('users', $sloupec)) {
                Schema::table('users', function (Blueprint $table) use ($sloupec) {
                    $table->dropColumn($sloupec);
                });
            }
        }
    }
};
