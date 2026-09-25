<?php

namespace Tests\Feature\Cesty;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class DrobnostiCestyTest extends CestyTestCase
{
    /** Volba dopravy nese měnu cesty — stejnou jako výdaj, který k ní vznikl. */
    public function test_volba_dopravy_bez_meny_ma_menu_cesty(): void
    {
        $tripId = $this->cesta('2026-10-10', '2026-10-12', ['currency' => 'EUR']);

        $volba = $this->postJson("/api/v1/trips/{$tripId}/travel-choices/transport", ['title' => 'Railjet', 'amount' => 39])->assertCreated()->json();

        $this->assertSame('EUR', $volba['currency']);
        $this->assertSame('EUR', DB::table('trip_expenses')->where('id', $volba['trip_expense_id'])->value('currency'));
    }

    /** Chyba nástroje pro čtení souboru (cesta, výpis procesu) nepatří klientovi. */
    public function test_chyba_cteni_souboru_vrati_obecnou_hlasku(): void
    {
        $tripId = $this->cesta('2026-10-10', '2026-10-12');
        // „Nástroj", který skončí chybou a vypíše na stderr vlastní text.
        config(['services.travel_documents.pdftotext_path' => PHP_BINARY]);

        $import = $this->post("/api/v1/trips/{$tripId}/reservation-imports", [
            'file' => UploadedFile::fake()->createWithContent('letenka.pdf', "%PDF-1.4\n%%EOF")->mimeType('application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('import');

        $this->assertSame('manual', $import['extraction_method']);
        $this->assertSame('Soubor se nepodařilo přečíst automaticky. Údaje doplňte v následné kontrole.', $import['processing_error']);
    }

    /** Účet bez galerie dostane srozumitelnou odpověď, ne chybu serveru. */
    public function test_ucet_bez_galerie_dostane_403_misto_500(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']));

        $this->getJson('/api/v1/trips')->assertForbidden();
        $this->postJson('/api/v1/trips', ['name' => 'X', 'start_date' => '2026-10-01', 'end_date' => '2026-10-02'])->assertForbidden();
        $this->getJson('/api/v1/journey')->assertForbidden();
        $this->getJson('/api/v1/itinerary')->assertForbidden();
    }
}
