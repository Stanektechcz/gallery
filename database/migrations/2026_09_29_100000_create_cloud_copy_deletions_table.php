<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kopie v cloudu, které se mají smazat.
 *
 * Varianty `cloud_copy` mizí kaskádou spolu s položkou, takže odkaz na vzdálený
 * soubor se musí uložit jinam dřív, než se řádek smaže — jinak by po výpadku
 * cloudu nebylo co zkusit znovu a fotka by v cizím úložišti ležela navždy.
 *
 * Bez jména souboru a bez titulku: záznam přežije položku a nesmí prozradit, co
 * bylo v trezoru. `remote_ref` je cesta nebo id v cloudu, jak ho tam cloud vede
 * (u Dropboxu a WebDAV cesta z `uuid`). Bez unikátního indexu — 1024 znaků
 * se do klíče MySQL nevejde a dva záznamy téže kopie nevadí (smazání je
 * idempotentní, „nenalezeno" je úspěch).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cloud_copy_deletions')) {
            return;
        }

        Schema::create('cloud_copy_deletions', function (Blueprint $table) {
            $table->id();
            // `nullOnDelete`: se zrušením prostoru nebo odpojením cloudu záznam
            // zůstane — úloha pak řekne, proč smazat nešlo, a doktor to ukáže.
            $table->foreignId('gallery_space_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('storage_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 20);
            $table->string('remote_ref', 1024);
            $table->uuid('media_uuid');
            $table->string('reason', 20)->default('purge');
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->timestamps();

            // Doktor a `gallery:cloud-mazani` se ptají „kolik selhalo" a „kolik
            // visí déle než den" — visí podle `updated_at` (viz model).
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloud_copy_deletions');
    }
};
