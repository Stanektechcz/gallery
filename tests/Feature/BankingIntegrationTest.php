<?php

namespace Tests\Feature;

use App\Models\BankConnection;
use App\Models\BankImport;
use App\Models\BankTransaction;
use App\Models\GallerySpace;
use App\Models\IntegrationSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Shared\Date as SpreadsheetDate;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class BankingIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $partner;

    private GallerySpace $space;

    private int $tripId;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->partner = User::factory()->create(['role' => 'partner']);
        $this->space = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Naše finance', 'slug' => 'nase-finance', 'owner_id' => $this->owner->id]);
        $this->space->members()->attach($this->owner->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $this->space->members()->attach($this->partner->id, ['role' => 'editor', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $this->tripId = DB::table('trips')->insertGetId(['gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id,
            'name' => 'Brno ve dvou', 'start_date' => '2026-08-10', 'end_date' => '2026-08-12', 'status' => 'planned',
            'timezone' => 'Europe/Prague', 'currency' => 'CZK', 'created_at' => now(), 'updated_at' => now()]);
        $dayId = DB::table('trip_days')->insertGetId(['trip_id' => $this->tripId, 'date' => '2026-08-10', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('trip_activities')->insert(['trip_day_id' => $dayId, 'created_by' => $this->owner->id, 'type' => 'stay',
            'title' => 'Ubytování', 'place_name' => 'Booking.com', 'status' => 'planned', 'currency' => 'CZK', 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->owner);
    }

    public function test_revolut_statement_history_is_encrypted_deduplicated_and_linked_to_trip_budget(): void
    {
        $rule = $this->postJson('/api/v1/banking/rules', ['gallery_space_id' => $this->space->id, 'field' => 'merchant',
            'operator' => 'contains', 'pattern' => 'Infinit', 'category' => 'activities', 'trip_action' => 'include', 'priority' => 200])
            ->assertCreated()->assertJsonPath('category', 'activities')->json();
        $first = <<<'CSV'
Type,Product,Started Date,Completed Date,Description,Amount,Fee,Currency,State,Balance
CARD,Joint Account,2026-07-20 10:00:00,2026-07-20 10:05:00,Booking.com Hotel,-200,0,CZK,COMPLETED,1000
CARD,Joint Account,2026-08-10 12:00:00,2026-08-10 12:01:00,Infinit Restaurant,-50,0,CZK,COMPLETED,950
CSV;
        $response = $this->post('/api/v1/banking/imports', ['gallery_space_id' => $this->space->id,
            'statement' => UploadedFile::fake()->createWithContent('revolut-cervenec.csv', $first)]);
        $response->assertCreated()->assertJsonPath('import.rows_imported', 2)->assertJsonPath('trip_links_created', 2);

        $this->assertDatabaseCount('bank_transactions', 2);
        $this->assertDatabaseCount('trip_expenses', 2);
        $this->assertDatabaseHas('trip_expenses', ['trip_id' => $this->tripId, 'title' => 'Booking.com Hotel', 'category' => 'accommodation',
            'amount' => 200, 'state' => 'actual', 'automation_source' => 'bank_transaction']);
        $this->assertDatabaseHas('trip_expenses', ['trip_id' => $this->tripId, 'title' => 'Infinit Restaurant', 'category' => 'activities']);
        $bankExpenseId = DB::table('trip_expenses')->where('automation_source', 'bank_transaction')->value('id');
        $this->deleteJson("/api/v1/trips/{$this->tripId}/expenses/{$bankExpenseId}")->assertStatus(409)
            ->assertJsonPath('message', 'Bankovní výdaj upravte nebo vyřaďte v Revolut přehledu cesty, aby zůstala zachována historie.');
        $raw = DB::table('bank_transactions')->orderBy('id')->first();
        $this->assertNotSame('Booking.com Hotel', $raw->merchant_name);
        $this->assertNotSame('Booking.com Hotel', $raw->description);
        $this->assertSame('Booking.com Hotel', BankTransaction::firstOrFail()->merchant_name);
        $this->assertSame('Booking.com Hotel', BankTransaction::firstOrFail()->description);

        $second = $first."\nMUSEUM,Joint Account,2026-08-11 14:00:00,2026-08-11 14:01:00,Museum Brno,-20,0,CZK,COMPLETED,930\n";
        $this->post('/api/v1/banking/imports', ['gallery_space_id' => $this->space->id,
            'statement' => UploadedFile::fake()->createWithContent('revolut-srpen.csv', $second)])
            ->assertCreated()->assertJsonPath('import.rows_imported', 1)->assertJsonPath('import.rows_duplicate', 2);
        $this->assertDatabaseCount('bank_transactions', 3);
        $this->assertDatabaseCount('trip_expenses', 3);

        $this->post('/api/v1/banking/imports', ['gallery_space_id' => $this->space->id,
            'statement' => UploadedFile::fake()->createWithContent('stejny-soubor.csv', $second)])
            ->assertOk()->assertJsonPath('duplicate_file', true);
        $this->assertDatabaseCount('bank_transactions', 3);

        $snapshot = $this->getJson("/api/v1/trips/{$this->tripId}/banking-finance")->assertOk()
            ->assertJsonPath('connected', true)->assertJsonPath('summary.spent_by_currency.CZK', 270)->json();
        $this->assertCount(3, $snapshot['transactions']);
        $linkId = $snapshot['transactions'][0]['link_id'];
        $this->patchJson("/api/v1/trips/{$this->tripId}/banking-finance/{$linkId}", ['category' => 'activities'])
            ->assertOk()->assertJsonPath('transactions.0.category', 'activities');
        $this->assertDatabaseHas('trip_bank_transactions', ['id' => $linkId, 'category' => 'activities', 'linked_by' => $this->owner->id]);

        $exclude = $this->postJson('/api/v1/banking/rules', ['gallery_space_id' => $this->space->id, 'field' => 'merchant',
            'operator' => 'contains', 'pattern' => 'Coffee', 'category' => 'food', 'trip_action' => 'exclude', 'priority' => 300])
            ->assertCreated()->json();
        $withExcluded = $second."COFFEE,Joint Account,2026-08-12 09:00:00,2026-08-12 09:01:00,Coffee Stop,-30,0,CZK,COMPLETED,900\n";
        $this->post('/api/v1/banking/imports', ['gallery_space_id' => $this->space->id,
            'statement' => UploadedFile::fake()->createWithContent('revolut-komplet.csv', $withExcluded)])->assertCreated()->assertJsonPath('import.rows_imported', 1);
        $this->assertDatabaseCount('bank_transactions', 4);
        $this->assertDatabaseCount('trip_bank_transactions', 3);
        $this->assertDatabaseCount('trip_expenses', 3);
        $this->deleteJson("/api/v1/banking/rules/{$exclude['uuid']}")->assertOk();

        DB::table('calendar_events')->insert([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id,
            'trip_id' => $this->tripId, 'title' => 'Společný výlet', 'type' => 'trip', 'status' => 'planned',
            'starts_at' => '2026-08-10 09:00:00', 'timezone' => 'Europe/Prague', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $dashboard = $this->getJson('/api/v1/banking/dashboard?gallery_space_id='.$this->space->id.'&from=2026-07-01&to=2026-08-31')
            ->assertOk()->assertJsonPath('summary.transaction_count', 4)
            ->assertJsonPath('summary.currencies.0.currency', 'CZK')
            ->assertJsonPath('summary.currencies.0.expenses', 300)
            ->assertJsonPath('transactions.meta.total', 4)
            ->assertJsonPath('events.0.title', 'Společný výlet')->json();
        $this->assertNotEmpty($dashboard['cashflow']);
        $this->assertNotEmpty($dashboard['categories']);
        $this->assertNotEmpty($dashboard['balance_series'][0]['points']);

        $coffee = BankTransaction::all()->first(fn (BankTransaction $item) => $item->description === 'Coffee Stop');
        $this->assertNotNull($coffee);
        $this->patchJson("/api/v1/banking/transactions/{$coffee->uuid}", ['category' => 'activities'])
            ->assertOk()->assertJsonPath('updated', true);
        $this->assertDatabaseHas('bank_transactions', ['id' => $coffee->id, 'category' => 'activities', 'category_is_manual' => true]);
        $this->postJson("/api/v1/banking/transactions/{$coffee->uuid}/trip", ['trip_id' => $this->tripId, 'allocated_amount' => 30])
            ->assertCreated()->assertJsonPath('linked', true);
        $this->assertDatabaseHas('trip_bank_transactions', ['trip_id' => $this->tripId, 'bank_transaction_id' => $coffee->id, 'status' => 'confirmed']);
        $this->assertDatabaseHas('trip_expenses', ['trip_id' => $this->tripId, 'title' => 'Coffee Stop', 'amount' => 30, 'automation_source' => 'bank_transaction']);

        $this->actingAs($this->partner)->getJson("/api/v1/trips/{$this->tripId}/banking-finance")->assertOk();
        $this->actingAs($this->owner)->getJson('/api/v1/banking?gallery_space_id='.$this->space->id)
            ->assertOk()->assertJsonPath('rules.0.pattern', 'Infinit');
        $this->deleteJson("/api/v1/banking/rules/{$rule['uuid']}")->assertOk()->assertJsonPath('deleted', true);
        $outsider = User::factory()->create(['role' => 'partner']);
        $this->actingAs($outsider)->getJson("/api/v1/trips/{$this->tripId}/banking-finance")->assertNotFound();
        $this->actingAs($outsider)->getJson('/api/v1/banking/dashboard?gallery_space_id='.$this->space->id)->assertNotFound();
        $readonly = User::factory()->create(['role' => 'partner', 'read_only_mode' => true]);
        $this->space->members()->attach($readonly->id, ['role' => 'viewer', 'joined_at' => now()]);
        $this->actingAs($readonly)->post('/api/v1/banking/imports', ['gallery_space_id' => $this->space->id,
            'statement' => UploadedFile::fake()->createWithContent('readonly.csv', $first)])->assertForbidden();
    }

    public function test_revolut_import_recognizes_localized_split_amounts_and_real_excel_files(): void
    {
        $localized = <<<'CSV'
Revolut společný účet
Export vytvořen 17. 9. 2026
Datum;Popis;Výdaj;Příjem;Měna;Zůstatek
15.09.2026 08:30;Kavárna;-125,50;;CZK;1874,50
16.09.2026 12:00;Vrácení platby;;250,00;CZK;2124,50
CSV;
        $this->post('/api/v1/banking/imports', [
            'gallery_space_id' => $this->space->id,
            'statement' => UploadedFile::fake()->createWithContent('revolut-cz.csv', $localized),
        ])->assertCreated()->assertJsonPath('import.rows_imported', 2)->assertJsonPath('import.rows_failed', 0);

        $transactions = BankTransaction::all()->keyBy('description');
        $this->assertSame(-125.5, (float) $transactions['Kavárna']->amount);
        $this->assertSame(250.0, (float) $transactions['Vrácení platby']->amount);

        foreach (['xlsx', 'xls'] as $offset => $extension) {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->fromArray([
                ['Revolut account statement'],
                [],
                ['Completed Date', 'Merchant Name', 'Paid out', 'Paid in', 'Product', 'Balance'],
                [SpreadsheetDate::PHPToExcel(new \DateTimeImmutable('2026-09-17 18:45:00')), strtoupper($extension).' nákup', '320.40', '', 'Joint CZK', '1804.10'],
            ]);
            $sheet->getStyle('A4')->getNumberFormat()->setFormatCode('dd/mm/yyyy hh:mm');
            $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'revolut-'.Str::uuid().'.'.$extension;
            ($extension === 'xlsx' ? new Xlsx($spreadsheet) : new Xls($spreadsheet))->save($path);
            $spreadsheet->disconnectWorksheets();
            try {
                $this->post('/api/v1/banking/imports', [
                    'gallery_space_id' => $this->space->id,
                    'statement' => new UploadedFile($path, 'revolut.'.$extension, null, null, true),
                ])->assertCreated()->assertJsonPath('import.rows_imported', 1)->assertJsonPath('import.rows_failed', 0);
            } finally {
                @unlink($path);
            }
        }

        $this->assertSame(-320.4, (float) BankTransaction::all()->firstWhere('description', 'XLSX nákup')->amount);
        $this->assertSame(-320.4, (float) BankTransaction::all()->firstWhere('description', 'XLS nákup')->amount);
        $this->assertDatabaseCount('bank_transactions', 4);
    }

    public function test_failed_csv_wrapped_revolut_xlsx_is_retried_and_decoded(): void
    {
        $mojibake = static fn (string $value): string => (string) iconv('Windows-1250', 'UTF-8', $value);
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $lines = [
            'Typ,Produkt,Datum zahájení,Datum dokončení,Popis,Částka,Poplatek,Měna,State,Zůstatek',
            'Převod,Aktuální,2026-06-27 14:33:36,2026-06-27 14:33:38,Převod od uživatele ADRIAN STANEK,320.00,0.00,CZK,DOKONČENO,320.00',
            'Platba kartou,Aktuální,2026-06-27 15:03:48,2026-06-28 13:34:51,Můj obchod,-69.70,0.00,CZK,DOKONČENO,250.30',
        ];
        foreach ($lines as $index => $line) {
            $sheet->setCellValue('A'.($index + 1), $mojibake($line));
        }
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'revolut-csv-wrapped-'.Str::uuid().'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        $sha = hash_file('sha256', $path);
        BankImport::create([
            'gallery_space_id' => $this->space->id,
            'imported_by' => $this->owner->id,
            'original_filename' => 'account-statement.xlsx',
            'file_sha256' => $sha,
            'format' => 'xlsx',
            'status' => 'failed',
            'rows_total' => 0,
            'rows_imported' => 0,
            'rows_failed' => 0,
            'error_summary' => 'Ve výpisu chybí sloupec amount.',
        ]);

        try {
            $this->post('/api/v1/banking/imports', [
                'gallery_space_id' => $this->space->id,
                'statement' => new UploadedFile($path, 'account-statement.xlsx', null, null, true),
            ])->assertCreated()
                ->assertJsonPath('duplicate_file', false)
                ->assertJsonPath('retried_import', true)
                ->assertJsonPath('import.rows_imported', 2)
                ->assertJsonPath('import.rows_failed', 0);

            $this->assertDatabaseCount('bank_imports', 1);
            $this->assertDatabaseCount('bank_transactions', 2);
            $this->assertNotNull(BankTransaction::all()->firstWhere('description', 'Převod od uživatele ADRIAN STANEK'));
            $this->assertNotNull(BankTransaction::all()->firstWhere('description', 'Můj obchod'));

            $this->post('/api/v1/banking/imports', [
                'gallery_space_id' => $this->space->id,
                'statement' => new UploadedFile($path, 'account-statement.xlsx', null, null, true),
            ])->assertOk()
                ->assertJsonPath('duplicate_file', true)
                ->assertJsonPath('retried_import', false);
            $this->assertDatabaseCount('bank_transactions', 2);
        } finally {
            @unlink($path);
        }
    }

    public function test_valid_statement_is_kept_when_optional_trip_reconciliation_fails(): void
    {
        $reconciliation = \Mockery::mock(\App\Services\Banking\TripBankReconciliationService::class);
        $reconciliation->shouldReceive('reconcileSpace')->once()->andThrow(new \RuntimeException('older trip schema'));
        $this->app->instance(\App\Services\Banking\TripBankReconciliationService::class, $reconciliation);

        $statement = <<<'CSV'
Type,Product,Completed Date,Description,Amount,Currency,State,Balance
CARD,Joint Account,2026-07-15 12:00:00,Testovací nákup,-125.50,CZK,COMPLETED,1874.50
CSV;

        $this->post('/api/v1/banking/imports', [
            'gallery_space_id' => $this->space->id,
            'statement' => UploadedFile::fake()->createWithContent('revolut.csv', $statement),
        ])->assertCreated()
            ->assertJsonPath('import.status', 'completed')
            ->assertJsonPath('import.rows_imported', 1)
            ->assertJsonPath('trip_links_created', 0)
            ->assertJsonCount(1, 'warnings');

        $this->assertDatabaseHas('bank_imports', ['status' => 'completed', 'rows_imported' => 1]);
        $this->assertDatabaseHas('bank_transactions', ['amount' => -125.5, 'currency' => 'CZK']);
    }

    public function test_read_only_psd2_connection_syncs_idempotently_and_preserves_historical_balances(): void
    {
        $setting = new IntegrationSetting(['provider' => 'gocardless_bank_data', 'is_enabled' => true, 'updated_by' => $this->owner->id]);
        $setting->replaceConfig(['secret_id' => 'test-id', 'secret_key' => 'test-secret']);
        $setting->save();
        $callback = null;
        Http::fake(function (ClientRequest $request) use (&$callback) {
            $url = $request->url();
            $method = $request->method();
            if (str_contains($url, '/token/new/')) {
                return Http::response(['access' => 'read-only-token', 'access_expires' => 3600]);
            }
            if (str_contains($url, '/institutions/')) {
                return Http::response([['id' => 'REVOLUT_REVOGB21', 'name' => 'Revolut',
                    'bic' => 'REVOGB21', 'transaction_total_days' => 730, 'max_access_valid_for_days' => 90]]);
            }
            if (str_contains($url, '/agreements/enduser/')) {
                return Http::response(['id' => 'agreement-1'], 201);
            }
            if (str_contains($url, '/requisitions/') && $method === 'POST') {
                $callback = $request->data()['redirect'] ?? null;

                return Http::response(['id' => 'requisition-1', 'link' => 'https://ob.gocardless.com/authorize/read-only'], 201);
            }
            if (str_contains($url, '/requisitions/requisition-1/')) {
                return Http::response(['id' => 'requisition-1', 'status' => 'LN', 'accounts' => ['account-1']]);
            }
            if (str_contains($url, '/accounts/account-1/details/')) {
                return Http::response(['account' => ['currency' => 'CZK', 'iban' => 'CZ6508000000001234567899',
                    'name' => 'Společný Revolut', 'ownerName' => ['Adrian', 'Markétka'], 'accountType' => 'joint_current']]);
            }
            if (str_contains($url, '/accounts/account-1/balances/')) {
                return Http::response(['balances' => [[
                    'balanceType' => 'closingBooked', 'balanceAmount' => ['amount' => '960', 'currency' => 'CZK']]]]);
            }
            if (str_contains($url, '/accounts/account-1/transactions/')) {
                return Http::response(['transactions' => ['booked' => [
                    $this->apiTransaction('booking-1', '2026-07-20T10:05:00+02:00', 200, 'DBIT', 'Booking.com Hotel', 1000),
                    $this->apiTransaction('food-1', '2026-08-10T12:01:00+02:00', 50, 'DBIT', 'Infinit Restaurant', 950),
                    $this->apiTransaction('exchange-1', '2026-08-11T10:00:00+02:00', 100, 'DBIT', 'Exchanged to EUR between your accounts', 850),
                    $this->apiTransaction('refund-1', '2026-08-11T13:00:00+02:00', 10, 'CRDT', 'Refund Infinit Restaurant', 860),
                ], 'pending' => []]]);
            }

            return Http::response(['message' => "Unexpected request {$method} {$url}"], 500);
        });

        $this->postJson('/api/v1/banking/connections', ['gallery_space_id' => $this->space->id,
            'institution_id' => 'REVOLUT_REVOGB21', 'country' => 'CZ', 'return_trip_id' => $this->tripId])
            ->assertCreated()->assertJsonPath('authorization_url', 'https://ob.gocardless.com/authorize/read-only');
        $this->assertNotNull($callback);
        $parts = parse_url($callback);
        $callbackPath = ($parts['path'] ?? '').(isset($parts['query']) ? '?'.$parts['query'] : '');
        $this->get($callbackPath)->assertRedirect("/trips/{$this->tripId}/plan#bank-finance");

        $connection = BankConnection::firstOrFail();
        $this->assertSame('active', $connection->status);
        $this->assertNull($connection->oauth_state_hash);
        $this->assertDatabaseCount('bank_transactions', 4);
        $this->assertDatabaseCount('trip_expenses', 2);
        $this->assertDatabaseHas('bank_accounts', ['iban_last4' => '7899', 'is_joint' => true]);
        $rawAccount = DB::table('bank_accounts')->first();
        $this->assertStringNotContainsString('Adrian', (string) $rawAccount->owner_name);
        $this->assertStringNotContainsString('account-1', (string) $rawAccount->encrypted_external_id);

        $snapshot = $this->getJson("/api/v1/trips/{$this->tripId}/banking-finance")->assertOk()
            ->assertJsonPath('summary.spent_by_currency.CZK', 250)
            ->assertJsonPath('summary.refunds_by_currency.CZK', 10)
            ->assertJsonPath('summary.internal_transfers', 0)
            ->assertJsonPath('summary.suggested_count', 1)
            ->assertJsonPath('balances.0.before.amount', 1000)
            ->assertJsonPath('balances.0.after.amount', 860)->json();
        $this->assertSame(0.9, $snapshot['transactions'][0]['confidence']);

        $this->postJson("/api/v1/banking/connections/{$connection->uuid}/sync")->assertOk()
            ->assertJsonPath('transactions_inserted', 0)->assertJsonPath('transactions_updated', 4);
        $this->assertDatabaseCount('bank_transactions', 4);
        $this->assertDatabaseCount('trip_expenses', 2);
        Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), '/agreements/enduser/')
            && ($request->data()['access_scope'] ?? []) === ['balances', 'details', 'transactions']);
        Http::assertNotSent(fn (ClientRequest $request) => str_contains($request->url(), 'payment'));
    }

    private function apiTransaction(string $id, string $date, float $amount, string $indicator, string $description, float $balance): array
    {
        return ['transactionId' => $id, 'bookingDateTime' => $date, 'valueDate' => substr($date, 0, 10),
            'creditDebitIndicator' => $indicator, 'transactionAmount' => ['amount' => (string) $amount, 'currency' => 'CZK'],
            'remittanceInformationUnstructured' => $description, 'merchantName' => $description,
            'proprietaryBankTransactionCode' => ['code' => 'CARD_PAYMENT', 'issuer' => 'Revolut'],
            'bankTransactionCode' => ['domain' => 'PMNT', 'family' => 'CCRD'],
            'balanceAfterTransaction' => ['balanceAmount' => ['amount' => (string) $balance, 'currency' => 'CZK']]];
    }
}
