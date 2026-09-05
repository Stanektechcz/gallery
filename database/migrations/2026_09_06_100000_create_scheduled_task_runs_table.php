<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Běhy plánovaných úloh.
 *
 * Aplikace dosud vedla jediný záznam o plánovači — tep každou minutu. Jestli
 * noční záloha proběhla, jak dlouho trvala a co vypsala, se nedalo zjistit
 * odnikud; správce viděl jen to, že plánovač žije. Administrace prototypu přitom
 * ukazuje „poslední běh" a „incidenty za 30 dní", a bez téhle tabulky by to byla
 * vymyšlená čísla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_task_runs', function (Blueprint $table) {
            $table->id();
            // Jméno z `->name()` v routes/console.php; podle něj se úloha pozná
            // v administraci i při ručním spuštění.
            $table->string('task', 191);
            $table->string('command', 512)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            // running · ok · failed · skipped
            $table->string('state', 20)->default('running');
            $table->unsignedSmallInteger('exit_code')->nullable();
            // Výpis se ořezává — u úlohy, která vypíše megabajt, není v protokolu
            // co číst a tabulka by rostla rychleji než galerie.
            $table->text('output')->nullable();
            $table->boolean('manual')->default(false);
            $table->timestamps();

            $table->index(['task', 'started_at']);
            $table->index(['state', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_task_runs');
    }
};
