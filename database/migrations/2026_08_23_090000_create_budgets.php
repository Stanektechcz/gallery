<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rozpočty na období — a žádosti o peníze mezi partnery.
 *
 * Finance v aplikaci byly dosud navázané na cestu: `trips` nese rozpočet, měnu a denní
 * limit, `trip_settlements` řeší, kdo komu dluží. To sedí na dovolenou. Nesedí to na
 * půl roku v Německu, kde není žádný výlet, ale je příjem, nájem, pravidelné výdaje a
 * druhá měna — a partner o dva státy dál, kterého je občas potřeba požádat o peníze.
 *
 * Rozpočet tedy stojí samostatně a váže se na období, ne na cestu. Může být osobní
 * (owner_user_id) nebo společný pro celý prostor.
 *
 * Měny se nepřepočítávají. Kurz nemáme odkud brát a vymýšlet si ho u peněz je horší než
 * ho neznat, takže každá částka nese svou měnu a součty se drží po měnách zvlášť. Kurz
 * zadá jedině ten, kdo směnu opravdu provedl, u konkrétní žádosti.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();

            // Null znamená společný rozpočet prostoru. Jinak patří jednomu člověku —
            // partner ho uvidí jen tehdy, když ho vlastník sdílí.
            $table->unsignedBigInteger('owner_user_id')->nullable();

            $table->string('name', 255);
            $table->string('currency', 3)->default('CZK');
            $table->date('starts_on');

            // Otevřený konec je běžný stav: „jsem tu, dokud budu."
            $table->date('ends_on')->nullable();

            $table->decimal('monthly_income', 12, 2)->nullable();
            $table->text('note')->nullable();
            $table->boolean('is_shared')->default(false);

            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['gallery_space_id', 'starts_on']);
            $table->foreign('owner_user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('budget_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);

            // Kolik je na tuhle kategorii vyhrazeno za měsíc. Nula znamená „sleduji, ale
            // nic si nevyhrazuji" — u kategorií jako dárky nebo nenadálé výdaje je to
            // poctivější než si vymýšlet číslo.
            $table->decimal('planned_monthly', 12, 2)->default(0);
            $table->string('color', 20)->nullable();
            $table->string('icon', 50)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['budget_id', 'sort_order']);
        });

        Schema::create('budget_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('budget_category_id')->nullable();
            $table->foreignId('user_id')->constrained();

            // Příjem i výdaj v jedné tabulce: v půlročním pobytu chodí obojí průběžně a
            // rozdělit je do dvou tabulek by znamenalo dvakrát sečíst totéž období.
            $table->string('kind', 10)->default('expense');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->date('spent_on');
            $table->string('note', 500)->nullable();

            // Pravidelná položka (nájem, telefon) se v přehledu chová jinak než jednorázová.
            $table->boolean('is_recurring')->default(false);

            $table->timestamps();

            $table->index(['budget_id', 'spent_on']);
            $table->foreign('budget_category_id')->references('id')->on('budget_categories')->nullOnDelete();
        });

        Schema::create('money_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_user_id')->constrained('users');
            $table->foreignId('to_user_id')->constrained('users');

            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);

            // Kolik dorazilo a v čem. Vyplní se až při vyřízení, protože kurz zná jen ten,
            // kdo směnu provedl — odhadovat ho dopředu by znamenalo lhát si do rozpočtu.
            $table->decimal('settled_amount', 12, 2)->nullable();
            $table->string('settled_currency', 3)->nullable();
            $table->decimal('exchange_rate', 12, 6)->nullable();

            $table->string('reason', 500)->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamp('responded_at')->nullable();
            $table->string('response_note', 500)->nullable();

            $table->timestamps();

            $table->index(['gallery_space_id', 'status']);
            $table->index(['to_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('money_requests');
        Schema::dropIfExists('budget_entries');
        Schema::dropIfExists('budget_categories');
        Schema::dropIfExists('budgets');
    }
};
