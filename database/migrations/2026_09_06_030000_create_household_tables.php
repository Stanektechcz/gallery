<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domácnost.
 *
 * Prototyp ji celou kreslil z napsaných řádků a všechny změny si nechával
 * v jednom JSON dokumentu stavu páru. Fungovalo to, ale znamenalo to, že se na
 * dělbu práce nedá zeptat odjinud — ani z připomínek, ani z automatizace, ani
 * z výročního přehledu — a že se z ní nedá nic spočítat na serveru.
 *
 * Tabulky drží skutečnost, ne popisky: `last_done_at` místo „před 11 dny",
 * datum místo „14. 10. 2026" a odpovědný člověk místo jména v řetězci.
 *
 * `client_id` je most k prototypu: ten si zakládá vlastní identifikátory
 * (`c1`, `l1725…`) a při další změně pošle celé pole zpátky. Bez něj by se
 * každý zápis tvářil jako nová položka a seznam by se množil.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('house_chores', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('client_id', 64)->nullable();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            // „denně", „týdně", „2× týdně" — slovy, protože tak je zadává člověk
            // a prototyp je tak i nabízí.
            $table->string('every', 40)->default('týdně');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('rotate')->default(true);
            $table->unsignedSmallInteger('minutes')->default(30);
            // Den v týdnu, na který práce padá: `po`…`ne`, nebo nic.
            $table->string('day', 4)->nullable();
            $table->string('icon', 40)->default('ph-broom');
            $table->timestamp('last_done_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['gallery_space_id', 'sort_order']);
            $table->unique(['gallery_space_id', 'client_id']);
        });

        Schema::create('house_chore_log', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('client_id', 64)->nullable();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('house_chore_id')->nullable()->constrained('house_chores')->nullOnDelete();
            // Jméno práce se opisuje: záznam má přežít i smazání té práce,
            // jinak by se rozpadla historie dělby.
            $table->string('chore_name', 160);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('minutes')->default(0);
            $table->timestamp('done_at');
            $table->timestamps();

            $table->index(['gallery_space_id', 'done_at']);
            $table->unique(['gallery_space_id', 'client_id']);
        });

        Schema::create('house_dues', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('client_id', 64)->nullable();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->string('what', 200);
            // `lhůta` (nedá se odložit), `platba`, `předplatné`.
            $table->string('kind', 24)->default('lhůta');
            $table->date('due_on');
            $table->unsignedInteger('amount')->default(0);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            // Co se stane, když se to nechá být — a kolik to stojí. Tohle je
            // celý smysl obrazovky: lhůta bez ceny odkladu je jen další řádek.
            $table->text('delay_note')->nullable();
            $table->unsignedInteger('delay_cost')->nullable();
            $table->text('change_note')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index(['gallery_space_id', 'due_on']);
            $table->unique(['gallery_space_id', 'client_id']);
        });

        Schema::create('house_inventory', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('client_id', 64)->nullable();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('subtitle', 200)->nullable();
            $table->string('room', 60)->nullable();
            $table->date('bought_on')->nullable();
            $table->date('warranty_to')->nullable();
            $table->boolean('has_doc')->default(false);
            $table->boolean('needs_service')->default(false);
            $table->date('service_next_on')->nullable();
            $table->unsignedInteger('service_price')->nullable();
            $table->unsignedInteger('price')->nullable();
            // Kolik let to má vydržet a co stojí ročně — z toho se počítá,
            // co ta věc doopravdy stojí za rok.
            $table->unsignedTinyInteger('life_years')->nullable();
            $table->unsignedInteger('energy_per_year')->nullable();
            $table->unsignedInteger('upkeep_per_year')->nullable();
            $table->timestamps();

            $table->index(['gallery_space_id', 'room']);
            $table->unique(['gallery_space_id', 'client_id']);
        });

        Schema::create('house_pantry', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('category', 60)->default('Špajz');
            $table->decimal('quantity', 8, 2)->default(0);
            $table->string('unit', 30)->nullable();
            // Datum, ne „za 4 dny": z data se dá spočítat den, ze dne datum ne.
            $table->date('expires_on')->nullable();
            // Slova, pod kterými to hledá kuchařka („rajčata", „olej").
            $table->json('keywords')->nullable();
            $table->timestamps();

            $table->index(['gallery_space_id', 'expires_on']);
        });

        Schema::create('house_week', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->string('weekday', 4);
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->unique(['gallery_space_id', 'weekday']);
        });

        Schema::create('house_week_capacity', function (Blueprint $table) {
            $table->id();
            $table->foreignId('house_week_id')->constrained('house_week')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Kolik hodin má ten den volných. Půlhodiny jsou tu běžné.
            $table->decimal('free_hours', 4, 2)->default(0);
            $table->timestamps();

            $table->unique(['house_week_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('house_week_capacity');
        Schema::dropIfExists('house_week');
        Schema::dropIfExists('house_pantry');
        Schema::dropIfExists('house_inventory');
        Schema::dropIfExists('house_dues');
        Schema::dropIfExists('house_chore_log');
        Schema::dropIfExists('house_chores');
    }
};
