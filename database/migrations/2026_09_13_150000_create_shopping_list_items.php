<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Položky nákupního seznamu, které někdo napsal ručně.
 *
 * Seznam se počítá ze surovin naplánovaných jídel a zápis stavu klíč
 * `xRows.shopping` zahazuje (jinak by uložený snímek navždy zastínil ten
 * spočítaný). S ním ale mizelo i „Přidat položku": mléko napsané na seznam
 * bylo vidět jen do obnovení stránky a druhý z dvojice ho neviděl nikdy.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shopping_list_items')) {
            return;
        }

        Schema::create('shopping_list_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->string('title', 200);
            $table->string('detail', 200)->nullable();
            $table->boolean('is_checked')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['gallery_space_id', 'is_checked']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopping_list_items');
    }
};
