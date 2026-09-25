<?php

namespace Tests\Feature\Banka;

use App\Models\BankTransaction;
use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImportRevolutuTest extends TestCase
{
    use RefreshDatabase;

    private User $vlastnik;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vlastnik = User::factory()->create(['role' => 'owner']);
        $this->prostor = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Naše finance', 'slug' => 'nase-finance-import', 'owner_id' => $this->vlastnik->id]);
        $this->prostor->members()->attach($this->vlastnik->id, ['role' => 'owner', 'joined_at' => now()]);
        $this->actingAs($this->vlastnik);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function nahrat(string $nazev, string $obsah): TestResponse
    {
        return $this->post('/api/v1/banking/imports', ['gallery_space_id' => $this->prostor->id,
            'statement' => UploadedFile::fake()->createWithContent($nazev, $obsah)]);
    }

    /**
     * Datum bez času nesmí brát hodiny ze serveru.
     *
     * `createFromFormat('d.m.Y')` doplní chybějící čas z aktuálních hodin —
     * ten šel do otisku řádku (jediný klíč proti duplicitě u Revolutu), takže
     * překrývající se výpis nahraný o pár sekund později zapsal stejné platby znovu.
     */
    public function test_prekryvajici_vypis_s_datem_bez_casu_nezdvoji_pohyby(): void
    {
        Carbon::setTestNow('2026-09-25 10:00:00');
        $this->nahrat('leden.csv', "Completed Date;Amount;Description\n15.01.2026;-100;Lidl\n")
            ->assertCreated()->assertJsonPath('import.rows_imported', 1);

        Carbon::setTestNow('2026-09-25 10:00:07');
        $this->nahrat('leden-unor.csv', "Completed Date;Amount;Description\n15.01.2026;-100;Lidl\n02.02.2026;-50;Albert\n")
            ->assertCreated()->assertJsonPath('import.rows_imported', 1)->assertJsonPath('import.rows_duplicate', 1);

        $this->assertDatabaseCount('bank_transactions', 2);
        $this->assertSame('2026-01-15 00:00:00', BankTransaction::all()->firstWhere('description', 'Lidl')->booked_at->format('Y-m-d H:i:s'));
    }

    /**
     * Vrácená nebo zamítnutá platba není útrata.
     */
    public function test_vracena_platba_se_nepocita_do_vydaju_ani_k_ceste(): void
    {
        $tripId = DB::table('trips')->insertGetId(['gallery_space_id' => $this->prostor->id, 'created_by' => $this->vlastnik->id,
            'name' => 'Vídeň', 'start_date' => '2026-08-10', 'end_date' => '2026-08-12', 'status' => 'planned',
            'timezone' => 'Europe/Prague', 'currency' => 'CZK', 'created_at' => now(), 'updated_at' => now()]);

        $this->nahrat('vraceno.csv', "Type,Completed Date,Description,Amount,Currency,State\n"
            ."CARD,2026-08-11 12:00:00,Hotel Sacher,-5000,CZK,REVERTED\n"
            ."CARD,2026-08-11 13:00:00,Kavárna,-80,CZK,DECLINED\n")->assertCreated();

        $this->assertSame(['cancelled', 'cancelled'], BankTransaction::orderBy('id')->pluck('status')->all());
        $this->assertDatabaseMissing('trip_bank_transactions', ['trip_id' => $tripId]);
        $this->assertDatabaseCount('trip_expenses', 0);

        $dashboard = $this->getJson('/api/v1/banking/dashboard?gallery_space_id='.$this->prostor->id.'&from=2026-08-01&to=2026-08-31')->assertOk()->json();
        $this->assertEqualsWithDelta(0, $dashboard['summary']['currencies'][0]['expenses'] ?? 0, 0.001);
        $this->assertEqualsWithDelta(0, $dashboard['summary']['currencies'][0]['net_change'] ?? 0, 0.001);
        $this->assertSame([], $dashboard['categories']);
    }

    public function test_poskozena_tabulka_nevraci_text_vyjimky(): void
    {
        // Skutečné XLSX useknuté v půlce: podle obsahu je to pořád tabulka, ale ZIP nejde otevřít.
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([['Completed Date', 'Amount', 'Description'], ['2026-01-15', -100, 'Lidl']]);
        $cesta = sys_get_temp_dir().DIRECTORY_SEPARATOR.'poskozeny-'.Str::uuid().'.xlsx';
        (new Xlsx($spreadsheet))->save($cesta);
        $spreadsheet->disconnectWorksheets();
        $obsah = (string) file_get_contents($cesta);
        @unlink($cesta);

        $zprava = $this->nahrat('vypis.xlsx', substr($obsah, 0, (int) (strlen($obsah) * 0.6)))->assertStatus(422)->json('message');

        $this->assertSame('Tabulku XLS/XLSX nelze přečíst. Ověřte, že není chráněná heslem ani poškozená.', $zprava);
    }
}
