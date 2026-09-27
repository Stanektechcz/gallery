<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fotky „k tisku" a sady, které už se stáhly.
 *
 * Dvojice si fotku rychlou ikonou označí k tisku a pak všechny označené
 * stáhne najednou do telefonu. Stažené se přesunou do sady (`print_batches`),
 * aby šlo tentýž výběr stáhnout znovu — třeba když se fotky do alba v telefonu
 * neuložily nebo je chce tisknout ještě jednou.
 *
 * Označení patří **prostoru**, ne člověku: oba vidí tentýž seznam „k tisku".
 * Ve sdíleném stavu dvojice neleží — tam by ho starší opis z druhého zařízení
 * přepsal. `marked_by` a `created_by` jsou jen údaj, kdo to udělal; se
 * smazáním účtu zůstane označení i sada.
 *
 * Jména indexů jsou krátká: MySQL bere nejvýš 64 znaků.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('print_marks')) {
            Schema::create('print_marks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
                $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();
                $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('marked_at')->useCurrent();

                // Jedna fotka je v seznamu jednou, ať ji označil kdokoli z dvojice.
                $table->unique(['gallery_space_id', 'media_item_id'], 'print_marks_prostor_media_uq');
                $table->index(['gallery_space_id', 'marked_at'], 'print_marks_prostor_kdy_idx');
            });
        }

        if (! Schema::hasTable('print_batches')) {
            Schema::create('print_batches', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                // „K tisku 27. 9. 2026", druhá sada téhož dne s „(2)".
                $table->string('name', 120);
                // Jméno archivu bez přípony: „K-tisku-2026-09-27", druhá sada „…-2".
                $table->string('file_stem', 80);
                $table->unsignedInteger('items_count')->default(0);
                $table->unsignedInteger('download_count')->default(0);
                $table->timestamp('downloaded_at')->nullable();
                $table->timestamps();

                $table->index(['gallery_space_id', 'created_at'], 'print_batches_prostor_kdy_idx');
            });
        }

        if (! Schema::hasTable('print_batch_items')) {
            Schema::create('print_batch_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('print_batch_id')->constrained()->cascadeOnDelete();
                // Trvalé smazání fotky ji ze sady odebere; koš a trezor ne — ty
                // se jen vynechají při stažení a po vrácení fotky jsou zpátky.
                $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();
                $table->unsignedInteger('position')->default(0);

                $table->unique(['print_batch_id', 'media_item_id'], 'print_batch_items_sada_media_uq');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('print_batch_items');
        Schema::dropIfExists('print_batches');
        Schema::dropIfExists('print_marks');
    }
};
