<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Klíč zápisu z klienta — ochrana proti duplicitě při opakovaném odeslání.
 *
 * Když Maki zapíše výdaj v obchodě bez signálu, záznam počká v telefonu a odešle se
 * po obnovení spojení. Jenže „odeslalo se to?" je otázka, na kterou klient nemusí
 * znát odpověď: požadavek mohl dojít a odpověď se ztratit. Pak se pokus zopakuje a
 * výdaj by v knize byl dvakrát.
 *
 * Odhadovat duplicitu podle částky a času nejde — dva stejné nákupy za den jsou
 * legitimní a modul je jinde výslovně povoluje. Proto si klíč vyrábí klient jednou
 * při rozepsání a posílá ho s každým pokusem. Server podle něj pozná, že už ten
 * zápis má, a vrátí ho místo založení nového.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->uuid('client_key')->nullable()->after('uuid');

            // Unikát je v rámci prostoru, ne globálně: klíče vyrábí klient a shoda
            // napříč prostory by neměla nic bránit.
            $table->unique(['gallery_space_id', 'client_key']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['gallery_space_id', 'client_key']);
            $table->dropColumn('client_key');
        });
    }
};
