<?php

namespace Tests\Feature\Media;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Jména souborů z trezoru, která už v protokolu leží, z něj zmizí.
 *
 * `vault.add` / `vault.remove` a trvalé smazání dřív ukládaly `filename`
 * i u fotek z trezoru a přehled „Dnes" jména z protokolu vypisuje i se
 * zamčeným trezorem. Kód to už nedělá; jednorázová migrace uklidí, co zůstalo.
 */
class TrezorBezJmenVProtokoluTest extends TestCase
{
    use RefreshDatabase;
    use VytvariMedia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->zalozProstor();
    }

    public function test_migrace_odebere_jmena_z_trezoru_a_zbytek_necha(): void
    {
        $skryta = $this->media(['is_hidden' => true, 'original_filename' => 'pas.jpg']);
        $bezna = $this->media(['original_filename' => 'more.jpg']);

        $pridani = $this->zaznam('vault.add', $skryta->id, ['filename' => 'pas.jpg', 'via' => 'web']);
        $vyjmuti = $this->zaznam('vault.remove', $bezna->id, ['filename' => 'more.jpg']);
        $nahraniSkryte = $this->zaznam('media.upload', $skryta->id, ['filename' => 'pas.jpg']);
        $nahraniBezne = $this->zaznam('media.upload', $bezna->id, ['filename' => 'more.jpg']);
        // Předmět už neexistuje — jestli byl v trezoru, se nedá zjistit.
        $smazani = $this->zaznam('media.purge', 999999, ['filename' => 'kdovi.jpg']);

        $this->spustMigraci();

        $this->assertSame(['via' => 'web'], $this->payload($pridani), 'Ostatní klíče zůstávají.');
        $this->assertNull($this->payload($vyjmuti), 'Bez jediného klíče je payload prázdný, jako u nového zápisu.');
        $this->assertNull($this->payload($nahraniSkryte));
        $this->assertSame(['filename' => 'more.jpg'], $this->payload($nahraniBezne));
        $this->assertSame(['filename' => 'kdovi.jpg'], $this->payload($smazani));
    }

    /** Opakované spuštění nic nerozbije. */
    public function test_migrace_je_opakovatelna(): void
    {
        $skryta = $this->media(['is_hidden' => true]);
        $id = $this->zaznam('vault.add', $skryta->id, ['filename' => 'pas.jpg', 'via' => 'web']);

        $this->spustMigraci();
        $this->spustMigraci();

        $this->assertSame(['via' => 'web'], $this->payload($id));
    }

    private function spustMigraci(): void
    {
        (require database_path('migrations/2026_09_29_120000_trezor_bez_jmen_v_protokolu.php'))->up();
    }

    /** @param  array<string, mixed>  $payload */
    private function zaznam(string $akce, int $predmet, array $payload): int
    {
        return (int) DB::table('audit_logs')->insertGetId([
            'user_id' => $this->adri->id,
            'gallery_space_id' => $this->prostor->id,
            'action' => $akce,
            'subject_type' => 'MediaItem',
            'subject_id' => $predmet,
            'payload' => json_encode($payload),
            'created_at' => now(),
        ]);
    }

    /** @return array<string, mixed>|null */
    private function payload(int $id): ?array
    {
        $surovy = DB::table('audit_logs')->where('id', $id)->value('payload');

        return $surovy === null ? null : json_decode((string) $surovy, true);
    }
}
