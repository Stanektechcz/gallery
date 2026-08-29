<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historie změn plánu.
 *
 * Kniha vede historii peněz, ne historii toho, na čem se lidi dohodli. Když se ve
 * dvou dívají na rozpočet a na jídlo je najednou o dvě stě víc, není jak zjistit,
 * jestli to někdo posunul ručně, nebo se to dorovnalo samo — a hádka o peníze umí
 * začít i takhle.
 *
 * Zapisují se jen skutečné zápisy do plánu. Vyrovnání, které běží při každém načtení,
 * se nikam neukládá a logovat ho by znamenalo tisíc řádků denně, ve kterých se ta
 * jedna ruční změna ztratí.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_plan_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('budget_id')->constrained('budgets')->cascadeOnDelete();

            // Null u akcí, které se týkají celého plánu (přepsání, návrat k odhadu).
            $table->unsignedBigInteger('finance_category_id')->nullable();

            // 'rucne' | 'podle-skutecnosti' | 'puvodni-odhad' | 'zruseno'
            $table->string('action', 24);

            $table->decimal('amount_from', 14, 2)->nullable();
            $table->decimal('amount_to', 14, 2)->nullable();
            $table->string('currency', 3);

            // Kdo. Zůstává i po smazání účtu — „nikdo to nezměnil" by byla lež.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name', 120)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['budget_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_plan_log');
    }
};
