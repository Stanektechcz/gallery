<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Do záznamu v deníku akcí patří i to, ve které galerii vznikl.
 *
 * `audit_logs` nesly jen uživatele a předmět akce. Úvodní obrazovka z nich
 * skládá „co se dnes dělo" a filtrovala podle členů prostoru — jenže člověk
 * může být ve dvou galeriích, a pak se mu v jedné ukazovalo, co udělal
 * v druhé: jméno souboru z cizí galerie v textu řádku, i když náhled
 * (ten se dohledává zvlášť) zůstal prázdný.
 *
 * Sloupec je nullable schválně: starým řádkům prostor nedopočítáme, protože
 * u smazaného předmětu už se nedá zjistit. Čtení je proto tolerantní —
 * záznam bez prostoru se do přehledu galerie nepočítá.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('audit_logs') || Schema::hasColumn('audit_logs', 'gallery_space_id')) {
            return;
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('gallery_space_id')->nullable()->after('user_id');
            $table->index(['gallery_space_id', 'created_at'], 'audit_logs_space_created_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('audit_logs') || ! Schema::hasColumn('audit_logs', 'gallery_space_id')) {
            return;
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_logs_space_created_idx');
            $table->dropColumn('gallery_space_id');
        });
    }
};
