<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabulky pro obrazovky, které dosud neměly kam psát.
 *
 * Každá z nich má svého pisatele — je jím ta obrazovka. Bez toho by platilo,
 * co v tomhle projektu platí jinak: tabulka, do které nikdo nepíše, je horší
 * než žádná, protože vypadá jako pravda a přitom je prázdná.
 *
 * Co tady schválně **není**: počasí. To se nemá odkud vzít a tabulka pro
 * předpověď, kterou nikdo neplní, by byla přesně ta chyba.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Náš příběh: kapitoly a milníky.
         *
         * `album_story_blocks` je vyprávění **uvnitř alba**; tohle je příběh
         * dvojice, který alba jen používá.
         */
        Schema::create('couple_story_chapters', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('year', 9)->nullable();
            // `draft` = píše se, `done` = hotovo, `published` = zveřejněno.
            $table->string('status', 20)->default('draft');
            $table->text('body')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('couple_story_milestones', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chapter_id')->nullable()->constrained('couple_story_chapters')->nullOnDelete();
            $table->date('happened_on');
            $table->string('title');
            $table->string('note')->nullable();
            $table->string('icon', 40)->nullable();
            $table->timestamps();

            $table->index(['gallery_space_id', 'happened_on']);
        });

        /*
         * Objednávky tisku.
         *
         * Prototyp říká, že objednání řeší tiskárna — aplikace ho nezakládá.
         * Zakázku ale někdo odeslal a dvojice chce vědět, kde je; tohle je
         * tedy **evidence odeslaného**, ne objednávkový formulář.
         */
        Schema::create('print_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('photo_book_id')->nullable()->constrained('photo_books')->nullOnDelete();
            $table->string('title');
            // `kniha`, `kalendar`, `ramecek`, `plakat`, `prani`, `arch`
            $table->string('kind', 20)->default('kniha');
            $table->unsignedInteger('price')->default(0);
            $table->string('currency', 3)->default('CZK');
            // 0 = přijato, 1 = v tisku, 2 = expedováno, 3 = doručeno.
            $table->unsignedTinyInteger('step')->default(0);
            $table->date('due_on')->nullable();
            $table->boolean('due_estimated')->default(true);
            $table->string('tracking', 60)->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();
        });

        /*
         * Nouzový přístup: co se druhému otevře, když jeden nemůže.
         *
         * `travel_emergency_cards` je karta k cestě — papír do peněženky, ne
         * přístup k datům. Tohle je druhá věc.
         */
        Schema::create('emergency_access_items', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('note')->nullable();
            $table->boolean('is_shared')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('emergency_access_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('text', 500);
            $table->timestamp('happened_at');
            $table->timestamps();

            $table->index(['gallery_space_id', 'happened_at']);
        });

        /*
         * Tierlisty: co dvojice viděla a kam to zařadila.
         *
         * Pásmo je písmeno (S, A, B, C…) a titul je titul — víc k tomu není.
         */
        Schema::create('watch_titles', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            // `film` nebo `series`
            $table->string('kind', 12)->default('film');
            // `watchlist`, `watching`, `seen`
            $table->string('status', 12)->default('watchlist');
            $table->string('tier', 2)->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->timestamps();

            $table->index(['gallery_space_id', 'status']);
        });

        /*
         * Papírová záloha: co musí být na papíře, když nic nefunguje.
         *
         * Většina těch řádků je text, který zná jen člověk — kde leží obálka
         * se záložním klíčem, číslo na právníka. Pár jich aplikace umí
         * předvyplnit, ale majitelem je dvojice.
         */
        Schema::create('paper_backup_rows', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->text('value')->nullable();
            $table->boolean('is_done')->default(false);
            $table->boolean('changed')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        /*
         * Komentáře hostů.
         *
         * Host nemá uživatele — má odkaz, kterým přišel, a jméno, které si
         * napsal. Proto `guest_name`, ne `user_id`: kdyby se to připnulo na
         * účet, buď by ho host musel mít, nebo by komentář lhal o autorovi.
         */
        Schema::create('guest_comments', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shared_link_id')->nullable()->constrained('shared_links')->nullOnDelete();
            $table->foreignId('media_item_id')->nullable()->constrained('media_items')->nullOnDelete();
            $table->string('guest_name');
            $table->text('body');
            // `text` nebo `voice`
            $table->string('kind', 10)->default('text');
            $table->string('duration', 10)->nullable();
            $table->boolean('is_hidden')->default(false);
            $table->boolean('is_pinned')->default(false);
            $table->timestamps();

            $table->index(['gallery_space_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_comments');
        Schema::dropIfExists('paper_backup_rows');
        Schema::dropIfExists('watch_titles');
        Schema::dropIfExists('emergency_access_log');
        Schema::dropIfExists('emergency_access_items');
        Schema::dropIfExists('print_orders');
        Schema::dropIfExists('couple_story_milestones');
        Schema::dropIfExists('couple_story_chapters');
    }
};
