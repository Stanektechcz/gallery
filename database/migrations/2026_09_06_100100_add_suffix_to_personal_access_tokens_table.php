<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Poslední znaky klíče k API.
 *
 * Klíč se ukládá jen jako otisk, takže po vytvoření už ho nikdo nepřečte — a to
 * je správně. Administrace ale musí umět říct, **který** z klíčů se ruší;
 * „…8f2a" u řádku je jediné, podle čeho se dá klíč v seznamu poznat. Čtyři znaky
 * ze čtyřiceti nikoho nikam nepustí.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('suffix', 8)->nullable()->after('token');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn('suffix');
        });
    }
};
