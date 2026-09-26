<?php

namespace Tests\Feature\Planovani;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\MemoryEvening;
use App\Models\SharedTodo;
use App\Models\User;
use App\Services\Memories\MemoryEveningService;
use App\Services\Planning\SharedTodoService;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Zápisy, které se spoléhaly na `insertOrIgnore` nebo na chybu 500.
 *
 * `insertOrIgnore` bez unikátního klíče nededuplikuje nic a na MySQL
 * (`INSERT IGNORE`) navíc potichu spolkne i jiné chyby než duplicitu —
 * oříznutý text, chybný JSON, cizí klíč. Tady se hlídá, že zápis je
 * idempotentní podle přirozeného klíče a že se ignoruje jen duplicita.
 */
class ZapisyBezDuplicitTest extends TestCase
{
    use DvojiceSHostem;
    use RefreshDatabase;

    public function test_stejny_rozpocet_darku_podruhe_vrati_422_ne_500(): void
    {
        [$vlastnik, , , $prostor] = $this->dvojiceSHostem();
        $rozpocet = ['gallery_space_id' => $prostor->id, 'year' => 2026, 'scope' => 'shared', 'title' => 'Vánoce', 'planned_amount' => 5000];

        $this->actingAs($vlastnik)->postJson('/api/v1/calendar/gift-budgets', $rozpocet)->assertCreated();

        // Na MySQL (`utf8mb4_unicode_ci`) narazí stejně i „vanoce"; SQLite to umí dokázat jen u přesné shody.
        $this->postJson('/api/v1/calendar/gift-budgets', $rozpocet)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title' => 'už máte']);

        $this->assertSame(1, DB::table('gift_budgets')->count());
    }

    public function test_prejmenovani_rozpoctu_na_existujici_nazev_vrati_422(): void
    {
        [$vlastnik, , , $prostor] = $this->dvojiceSHostem();
        $zaklad = ['gallery_space_id' => $prostor->id, 'year' => 2026, 'scope' => 'shared', 'planned_amount' => 5000];
        $this->actingAs($vlastnik)->postJson('/api/v1/calendar/gift-budgets', $zaklad + ['title' => 'Vánoce'])->assertCreated();
        $narozeniny = $this->postJson('/api/v1/calendar/gift-budgets', $zaklad + ['title' => 'Narozeniny'])->assertCreated()->json('budget.uuid');

        $this->patchJson("/api/v1/calendar/gift-budgets/{$narozeniny}", ['gallery_space_id' => $prostor->id, 'title' => 'Vánoce'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('title');
    }

    /**
     * Příloha „vzpomínka", kterou akce už má, se při dokončení nezdvojí.
     *
     * Dvojí dokončení téhož večera zastaví zámek se stavem (`DvojityKlikTest`)
     * a unikát `shared_memory_moments.calendar_event_id`. Přílohy ale nemají
     * unikát žádný, takže řádek, který u akce už leží (dřívější částečný zápis,
     * ruční zásah), `insertOrIgnore` klidně přidal podruhé.
     */
    public function test_dokonceni_vecera_neprida_prilohu_kterou_akce_uz_ma(): void
    {
        Queue::fake();
        [$vlastnik, , , $prostor] = $this->dvojiceSHostem();
        [$vecer, $fotka] = $this->vecer($vlastnik, $prostor, 'vylet.jpg');
        DB::table('event_attachments')->insert([
            'event_id' => $vecer->calendar_event_id, 'media_item_id' => $fotka->id, 'label' => 'Dřív', 'kind' => 'memory',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app(MemoryEveningService::class)->complete($vecer, $vlastnik);

        $this->assertSame(1, DB::table('event_attachments')->where('event_id', $vecer->calendar_event_id)->where('media_item_id', $fotka->id)->where('kind', 'memory')->count());
    }

    /** Popisek přílohy se ořízne výslovně na délku sloupce — ne až tichým IGNORE na MySQL. */
    public function test_dlouhy_nazev_fotky_se_v_priloze_orizne_na_255_znaku(): void
    {
        Queue::fake();
        [$vlastnik, , , $prostor] = $this->dvojiceSHostem();
        // `display_title` pojme 512 znaků, `event_attachments.label` jen 255.
        [$vecer] = $this->vecer($vlastnik, $prostor, 'vylet.jpg', ['display_title' => str_repeat('Ř', 300)]);

        app(MemoryEveningService::class)->complete($vecer, $vlastnik);

        $prilohy = DB::table('event_attachments')->where('event_id', $vecer->calendar_event_id)->where('kind', 'memory')->get();
        $this->assertCount(1, $prilohy);
        $this->assertSame(str_repeat('Ř', 255), $prilohy->first()->label);
    }

    /** Dvě kopie téhož úkolu naplánované naráz: jedna akce a jedna připomínka. */
    public function test_ukol_naplanovany_dvakrat_naraz_da_jednu_akci_a_jednu_pripominku(): void
    {
        [$vlastnik, , , $prostor] = $this->dvojiceSHostem();
        $sluzba = app(SharedTodoService::class);
        $ukol = $sluzba->create($prostor, $vlastnik, [
            'title' => 'Koupit lístky', 'due_at' => now()->addDays(3), 'remind_at' => now()->addDays(2),
        ]);

        $prvni = SharedTodo::findOrFail($ukol->id);
        $druha = SharedTodo::findOrFail($ukol->id);

        $akce = $sluzba->schedule($prvni, $vlastnik);
        $znovu = $sluzba->schedule($druha, $vlastnik);

        $this->assertSame((int) $akce->id, (int) $znovu->id);
        $this->assertSame(1, DB::table('calendar_events')->where('type', 'todo')->count());
        $this->assertSame(1, DB::table('event_reminders')->where('event_id', $akce->id)->count());
    }

    /**
     * Souběžný požadavek zapsal potvrzení první — druhý zápis duplicitu jen přejde.
     *
     * Souběh se napodobí háčkem: těsně před zápisem potvrzení ho „zapíše" jiný
     * požadavek se stejným `request_id`. Unikát pak zápis odmítne a to se nesmí
     * stát chybou; jiná chyba by se naopak ukázat měla (viz `zapisPotvrzeni`).
     */
    public function test_duplicitni_potvrzeni_asistenta_se_prejde_bez_chyby(): void
    {
        [$vlastnik, , , $prostor] = $this->dvojiceSHostem();
        $pozadavek = (string) Str::uuid();
        $predbehl = false;

        DB::connection()->beforeExecuting(function (string $sql, array $vazby, Connection $spojeni) use (&$predbehl, $pozadavek, $prostor, $vlastnik): void {
            if ($predbehl || ! str_starts_with($sql, 'insert into') || ! str_contains($sql, 'assistant_action_receipts')) {
                return;
            }
            $predbehl = true;
            $spojeni->table('assistant_action_receipts')->insert([
                'request_id' => $pozadavek, 'gallery_space_id' => $prostor->id, 'user_id' => $vlastnik->id,
                'response' => json_encode(['created' => ['souběžný']]), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        // Film bez nastavené filmové databáze: uloží se ručně, bez sítě.
        $this->actingAs($vlastnik)->postJson('/api/v1/assistant/apply', [
            'message' => '/film Neznámý snímek', 'request_id' => $pozadavek, 'selected_actions' => ['titles'],
        ])->assertCreated();

        $this->assertTrue($predbehl, 'Háček souběhu se nespustil — test nic neověřil.');
        $this->assertSame(1, DB::table('assistant_action_receipts')->where('request_id', $pozadavek)->count());
    }

    /**
     * Naplánovaný večer se vzpomínkami s jednou fotkou.
     *
     * @return array{0: MemoryEvening, 1: MediaItem}
     */
    private function vecer(User $vlastnik, GallerySpace $prostor, string $nazev, array $navic = []): array
    {
        $fotka = $this->fotka($prostor, $vlastnik, $nazev, $navic);
        $uuid = $this->actingAs($vlastnik)->postJson('/api/v1/memory-evenings', [
            'gallery_space_id' => $prostor->id, 'fingerprint' => hash('sha256', $nazev.Str::random(8)), 'source_type' => 'on_this_day',
            'title' => 'Před rokem', 'scheduled_for' => now()->addWeek()->toIso8601String(), 'media_uuids' => [$fotka->uuid],
        ])->assertCreated()->json('uuid');

        return [MemoryEvening::where('uuid', $uuid)->firstOrFail(), $fotka];
    }
}
