<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Co dvojice z akčního inboxu odbyla nebo odložila.
 *
 * Řádky inboxu se **nikde neukládají** — počítají se z toho, co v aplikaci
 * chybí („12 fotek bez data", „nezařazená transakce"). To je správně: seznam
 * se tím sám vyprázdní, jakmile se ta věc opraví.
 *
 * Chybělo k tomu ale druhé: říct „tohle teď neřeš". Tlačítko „Vyřešit"
 * přeškrtlo řádek jen v prohlížeči a při dalším načtení byl zpátky, protože
 * server o tom rozhodnutí nevěděl. A záložky „Odloženo" a „Hotovo" vedle něj
 * kreslily tři napsané řádky z ukázky.
 *
 * Ukládá se proto jen **rozhodnutí**, ne obsah: klíč kategorie a co s ní být
 * má. Odložení má datum, po kterém se řádek sám vrátí — jinak by z odložení
 * bylo tiché smazání.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            // Klíč kategorie, ne konkrétní položky: „fotky bez data" je jeden
            // řádek, i když se počet mění. Kdyby se ukládal text řádku, každá
            // nová fotka bez data by odložení zrušila.
            $table->string('item_key', 64);
            $table->string('state', 16);
            $table->string('title');
            $table->date('snoozed_until')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['gallery_space_id', 'item_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_states');
    }
};
