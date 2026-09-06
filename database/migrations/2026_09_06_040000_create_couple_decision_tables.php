<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mechanismy vztahu, které se dají zapsat.
 *
 * Prototyp jich má patnáct, ale upravovat jde jen pět: paměť rozhodnutí,
 * rozvaha před nákupem, protokol nesouhlasu a veto banka (návrhy a použití).
 * Právě ty dostávají tabulku. **Tabulka, do které nikdo nepíše, je horší než
 * žádná tabulka** — zbytek (tiché dohody, kdo mluví za nás, premortem) zůstává
 * v katalogu, dokud pro něj v prototypu nevznikne obrazovka, kde se dá měnit.
 *
 * `client_id` je most k prototypu: ten si zakládá vlastní identifikátory
 * (`d1725…`) a při další změně pošle celé pole zpátky.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('couple_decisions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('client_id', 64)->nullable();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->string('title', 255);
            $table->date('decided_on');
            // Kdo rozhodl. Společné rozhodnutí nemá jednoho autora, a to je
            // podstatný rozdíl — proto vlastní příznak, ne prázdný cizí klíč.
            $table->boolean('together')->default(true);
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            // `platí`, `k revizi`, `změněno`.
            $table->string('status', 24)->default('platí');
            /*
             * Proč ano a co jsme zavrhli.
             *
             * Celý smysl paměti rozhodnutí: za rok se nikdo neptá, co jste
             * rozhodli, ale proč — a co tehdy bylo na stole.
             */
            $table->json('why')->nullable();
            $table->json('rejected')->nullable();
            $table->string('review_note', 120)->nullable();
            $table->date('review_on')->nullable();
            /*
             * Kdo měl poslední slovo a podle jakého klíče.
             *
             * Z tohohle se skládá přehled arbitráže — ne z druhého seznamu,
             * který by se s rozhodnutími rozešel.
             */
            $table->foreignId('arbiter_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('arbiter_method', 60)->nullable();
            $table->timestamp('changed_at')->nullable();
            $table->timestamps();

            $table->index(['gallery_space_id', 'decided_on']);
            $table->unique(['gallery_space_id', 'client_id']);
        });

        Schema::create('couple_decision_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('couple_decision_id')->constrained()->cascadeOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            // Znění, které tehdy platilo. Původní zápis se nepřepisuje —
            // právě proto, aby za rok bylo vidět, co jste si tehdy mysleli.
            $table->text('wording');
            $table->date('valid_from');
            $table->timestamps();

            $table->index(['couple_decision_id', 'valid_from']);
        });

        Schema::create('couple_cooling_purchases', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('client_id', 64)->nullable();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->string('what', 255);
            $table->unsignedInteger('price')->default(0);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at');
            // Do kdy se čeká. Hodiny, ne dny: rozvaha je 72 hodin.
            $table->timestamp('cools_until');
            $table->text('opinion')->nullable();
            $table->foreignId('opinion_by')->nullable()->constrained('users')->nullOnDelete();
            // `koupit`, `nechat být` — nebo nic, dokud lhůta běží.
            $table->string('verdict', 24)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['gallery_space_id', 'cools_until']);
            $table->unique(['gallery_space_id', 'client_id']);
        });

        Schema::create('couple_disagreement_points', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('client_id', 64)->nullable();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('topic', 160)->nullable();
            $table->string('text', 255);
            $table->string('tag', 40)->nullable();
            /*
             * `podmínka` nebo `přání`.
             *
             * Rozdíl mezi nimi je celý smysl protokolu: podmínek se nedá mít
             * pět a přání se nedá vetovat.
             */
            $table->string('kind', 16)->default('podmínka');
            $table->timestamps();

            $table->index(['gallery_space_id', 'author_user_id']);
            $table->unique(['gallery_space_id', 'client_id']);
        });

        Schema::create('couple_veto_proposals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('client_id', 64)->nullable();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('proposed_by')->constrained('users')->cascadeOnDelete();
            $table->string('text', 255);
            $table->unsignedInteger('price')->default(0);
            $table->date('proposed_on');
            // `veto`, `prošlo` — nebo nic, dokud je návrh otevřený.
            $table->string('outcome', 16)->nullable();
            $table->timestamps();

            $table->index(['gallery_space_id', 'proposed_on']);
            $table->unique(['gallery_space_id', 'client_id']);
        });

        Schema::create('couple_vetoes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('client_id', 64)->nullable();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('couple_veto_proposal_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('text', 255);
            /*
             * Datum, ne popisek: veto se vrací po dvanácti měsících a bez data
             * by se nedalo spočítat, kolik jich komu zbývá.
             */
            $table->date('used_on');
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index(['gallery_space_id', 'user_id', 'used_on']);
            $table->unique(['gallery_space_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('couple_vetoes');
        Schema::dropIfExists('couple_veto_proposals');
        Schema::dropIfExists('couple_disagreement_points');
        Schema::dropIfExists('couple_cooling_purchases');
        Schema::dropIfExists('couple_decision_revisions');
        Schema::dropIfExists('couple_decisions');
    }
};
