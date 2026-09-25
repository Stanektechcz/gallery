<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fotky se mažou jen po společném schválení.
 *
 * Režim patří prostoru do vlastních sloupců, ne do `settings`: automatizace
 * (`AutomationRegistryService::setEnabled/markRan`) přepisují celé JSON
 * nastavení ze zastaralého modelu a tiše by dohodu dvojice vrátily.
 *
 * Výchozí `spolecne` dostanou i všechny stávající prostory — tak to dvojice
 * rozhodla. Návrh ke smazání nese sama fotka; kdo ji nakonec do koše
 * přesunul, zůstává v `trashed_by`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('gallery_spaces') && ! Schema::hasColumn('gallery_spaces', 'media_delete_mode')) {
            Schema::table('gallery_spaces', function (Blueprint $table) {
                $table->string('media_delete_mode', 16)->default('spolecne');
                $table->string('media_delete_mode_requested', 16)->nullable();
                $table->foreignId('media_delete_mode_requested_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('media_delete_mode_requested_at')->nullable();
            });
        }

        if (Schema::hasTable('media_items') && ! Schema::hasColumn('media_items', 'trash_requested_by')) {
            Schema::table('media_items', function (Blueprint $table) {
                $table->foreignId('trash_requested_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('trash_requested_at')->nullable();
                $table->foreignId('trashed_by')->nullable()->constrained('users')->nullOnDelete();
                // „Čeká na smazání" v knihovně prostoru.
                $table->index(['gallery_space_id', 'trash_requested_at'], 'media_items_space_trash_req_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('media_items') && Schema::hasColumn('media_items', 'trash_requested_by')) {
            Schema::table('media_items', function (Blueprint $table) {
                $table->dropIndex('media_items_space_trash_req_idx');
                $table->dropConstrainedForeignId('trash_requested_by');
                $table->dropConstrainedForeignId('trashed_by');
                $table->dropColumn('trash_requested_at');
            });
        }

        if (Schema::hasTable('gallery_spaces') && Schema::hasColumn('gallery_spaces', 'media_delete_mode')) {
            Schema::table('gallery_spaces', function (Blueprint $table) {
                $table->dropConstrainedForeignId('media_delete_mode_requested_by');
                $table->dropColumn(['media_delete_mode', 'media_delete_mode_requested', 'media_delete_mode_requested_at']);
            });
        }
    }
};
