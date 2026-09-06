<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nálada dne.
 *
 * „Klid a pohoda" kreslí čtrnáct dní zpátky křivku za každého z dvojice a celá
 * obrazovka na ní stojí — bez ní se nedá říct, jestli se něco zlepšuje. Zapsat
 * ji jde jedním klikem, takže si zaslouží místo, kde přežije zavření prohlížeče.
 *
 * Jeden zápis na člověka a den: nálada se přepisuje, ne přidává.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wellbeing_moods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            // 1 až 5. Nula neznamená nic — chybějící den je chybějící řádek.
            $table->unsignedTinyInteger('value');
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->unique(['gallery_space_id', 'user_id', 'day']);
            $table->index(['gallery_space_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wellbeing_moods');
    }
};
