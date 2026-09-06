<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Co pravidla doopravdy udělala.
 *
 * `automation_rules` dosud počítala jen `run_count` a `last_run_at`, takže
 * obrazovka „Automatizace a pravidla" ukazovala historii běhů, kterou nikdo
 * nezapisoval — a **selhání se nedozvěděl vůbec nikdo**: chyba šla do logu
 * Laravelu, kam se dvojice nikdy nepodívá. Pravidlo, které tři týdny padá,
 * tak vypadá stejně jako pravidlo, na které nic nesedlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->boolean('succeeded')->default(true);
            // Jednou větou, co se stalo — přesně jak to obrazovka vypisuje.
            $table->string('message', 500);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['gallery_space_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_runs');
    }
};
