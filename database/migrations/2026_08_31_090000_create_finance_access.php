<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Komu který rozpočet a která cesta patří.
 *
 * Dosud byly všechny společné. To stačí, dokud je rozpočet jeden — jenže Makinka jede
 * do Německa, Adri zůstává v Česku a k tomu je něco společného. Tři různé věci, které
 * se v jednom seznamu pletou a jejichž čísla nemají co dělat v jednom součtu.
 *
 * Model má dvě úrovně, protože „čí to je" a „kdo se na to smí podívat" jsou dvě různé
 * otázky. Vlastník je jeden a rozhoduje; přístup se dá dát komukoli dalšímu.
 *
 *  - `owner_user_id = null` → společné, vidí celý prostor
 *  - `owner_user_id = X`    → patří X a vidí to jen on
 *  - záznam v `finance_access` → a navíc ten, kdo je tu uvedený
 *
 * Polymorfní schválně: rozpočet i cesta se sdílejí stejně a dvě skoro totožné tabulky
 * by znamenaly dvě místa, kde se dá pravidlo viditelnosti napsat jinak.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_projects', function (Blueprint $table) {
            // Null znamená společná cesta. Dosavadní cesty tím zůstanou společné —
            // což je stav, ve kterém byly, takže se nikomu nic neschová.
            $table->foreignId('owner_user_id')->nullable()->after('gallery_space_id')
                ->constrained('users')->nullOnDelete();
        });

        Schema::create('finance_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();

            // 'budget' | 'trip'
            $table->string('subject_type', 20);
            $table->unsignedBigInteger('subject_id');

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Přístup bez práva zápisu je běžnější případ, než by se čekalo: Adri má
            // vidět, jak je Makinka na tom, ale zapisovat jí do rozpočtu nemá.
            $table->boolean('can_edit')->default(false);

            $table->timestamps();

            $table->unique(['subject_type', 'subject_id', 'user_id']);
            $table->index(['gallery_space_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_access');

        Schema::table('finance_projects', function (Blueprint $table) {
            $table->dropForeign(['owner_user_id']);
            $table->dropColumn('owner_user_id');
        });
    }
};
