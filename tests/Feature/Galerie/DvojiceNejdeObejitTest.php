<?php

namespace Tests\Feature\Galerie;

use App\Models\AuditLog;
use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Planovani\DvojiceSHostem;
use Tests\TestCase;

/**
 * Vlastník nemůže partnera přeřadit na hosta.
 *
 * Mazání fotek čeká na souhlas druhého z dvojice (`MazaniFotek`) a dvojici
 * tvoří role v prostoru. Kdyby šlo partnera přeřadit na hosta, zůstal by
 * vlastník v dvojici sám a mazal by bez souhlasu. Stejně tak nesmí do úplné
 * dvojice přibýt třetí správce, kterého si vlastník vybere — schvaloval by
 * mazání místo partnera. Pravidlo platí na všech cestách: změna role, pozvánka,
 * předání vlastnictví i starší klient přes stav.
 */
class DvojiceNejdeObejitTest extends TestCase
{
    use DvojiceSHostem, RefreshDatabase;

    private User $vlastnik;

    private User $partner;

    private User $host;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Notification::fake();

        [$this->vlastnik, $this->partner, $this->host, $this->prostor] = $this->dvojiceSHostem();
        $this->vlastnik->update(['name' => 'Adrian']);
        $this->partner->update(['name' => 'Makinka']);
        $this->host->update(['name' => 'Klára']);

        Sanctum::actingAs($this->vlastnik);
    }

    // ——— změna role ———

    public function test_partnera_nejde_preradit_na_hosta(): void
    {
        $this->patchJson('/api/admin/users/'.$this->partner->id.'/role', ['role' => 'host'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Partnera nejde přeřadit na hosta — mazání fotek by pak nepotřebovalo jeho souhlas.');

        $this->assertSame('editor', $this->rolePivotu($this->partner));
        $this->assertSame(0, AuditLog::where('action', 'admin.role')->count(),
            'Odmítnutý zásah se do protokolu nezapisuje jako provedený.');
    }

    /** Předáním vlastnictví se pravidlo neobejde — nový vlastník nepřeřadí předchozího. */
    public function test_novy_vlastnik_nepreradi_predchoziho(): void
    {
        $this->postJson('/api/admin/users/'.$this->partner->id.'/transfer')->assertOk();
        $this->assertSame('admin', $this->rolePivotu($this->vlastnik));

        Sanctum::actingAs($this->partner);

        $this->patchJson('/api/admin/users/'.$this->vlastnik->id.'/role', ['role' => 'host'])
            ->assertStatus(422);

        $this->assertSame('admin', $this->rolePivotu($this->vlastnik));
    }

    /** „Správce" u správce nesmí přepsat `admin` na `editor` — ten by přišel o trvalé mazání. */
    public function test_spravce_u_clena_dvojice_roli_nezmeni(): void
    {
        $this->prostor->members()->updateExistingPivot($this->partner->id, ['role' => 'admin']);

        $odpoved = $this->patchJson('/api/admin/users/'.$this->partner->id.'/role', ['role' => 'správce'])
            ->assertOk();

        $this->assertSame('admin', $this->rolePivotu($this->partner));
        $this->assertSame('správce', collect($odpoved->json('data.users'))->firstWhere('name', 'Makinka')['role']);
        $this->assertSame(0, AuditLog::where('action', 'admin.role')->count());
    }

    public function test_hosta_nejde_povysit_do_uplne_dvojice(): void
    {
        $this->patchJson('/api/admin/users/'.$this->host->id.'/role', ['role' => 'správce'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Dvojice už je úplná — další správce by mohl schvalovat mazání místo partnera.');

        $this->assertSame('viewer', $this->rolePivotu($this->host));
    }

    /** Vlastník, který je v galerii sám, si partnera z hosta udělat smí. */
    public function test_hosta_lze_povysit_kdyz_je_vlastnik_sam(): void
    {
        $this->prostor->members()->detach($this->partner->id);

        $odpoved = $this->patchJson('/api/admin/users/'.$this->host->id.'/role', ['role' => 'správce'])
            ->assertOk();

        $this->assertSame('editor', $this->rolePivotu($this->host));
        $this->assertSame('správce', collect($odpoved->json('data.users'))->firstWhere('name', 'Klára')['role']);
    }

    // ——— pozvánka ———

    public function test_pozvanka_spravce_do_uplne_dvojice_neprojde(): void
    {
        $this->postJson('/api/admin/users', ['email' => 'treti@vzpominky.test', 'role' => 'správce'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Dvojice už je úplná — další správce by mohl schvalovat mazání místo partnera.');

        $this->assertNull(User::where('email', 'treti@vzpominky.test')->first());
    }

    public function test_pozvanka_hosta_do_uplne_dvojice_projde(): void
    {
        $this->postJson('/api/admin/users', ['email' => 'host@vzpominky.test', 'role' => 'host'])->assertOk();

        $pozvany = User::where('email', 'host@vzpominky.test')->sole();
        $this->assertSame('viewer', $this->rolePivotu($pozvany));
    }

    // ——— předání vlastnictví ———

    public function test_vlastnictvi_nejde_predat_hostovi_v_uplne_dvojici(): void
    {
        $this->postJson('/api/admin/users/'.$this->host->id.'/transfer')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Dvojice už je úplná — vlastnictví jde předat jen partnerovi, ne hostovi.');

        $this->assertSame($this->vlastnik->id, (int) $this->prostor->fresh()->owner_id);
        $this->assertSame('viewer', $this->rolePivotu($this->host));
        $this->assertSame('owner', $this->rolePivotu($this->vlastnik));
    }

    public function test_vlastnictvi_jde_predat_hostovi_kdyz_je_vlastnik_sam(): void
    {
        $this->prostor->members()->detach($this->partner->id);

        $this->postJson('/api/admin/users/'.$this->host->id.'/transfer')->assertOk();

        $this->assertSame($this->host->id, (int) $this->prostor->fresh()->owner_id);
        $this->assertSame('admin', $this->rolePivotu($this->vlastnik));
    }

    // ——— starší klient přes stav ———

    public function test_ve_stavu_partner_hostem_nebude(): void
    {
        $odpoved = $this->patchJson('/api/state', ['data' => [
            'admUsers' => [
                ['id' => (string) $this->vlastnik->id, 'name' => 'Adrian', 'role' => 'vlastník', 'state' => 'aktivní'],
                ['id' => (string) $this->partner->id, 'name' => 'Makinka', 'role' => 'host', 'state' => 'aktivní'],
            ],
        ]])->assertOk();

        $this->assertSame('editor', $this->rolePivotu($this->partner));
        $this->assertSame('správce', collect($odpoved->json('data.admUsers'))->firstWhere('name', 'Makinka')['role'],
            'Odpověď musí obrazovku srovnat se skutečností.');
    }

    public function test_ve_stavu_host_spravcem_ani_vlastnikem_nebude(): void
    {
        $this->patchJson('/api/state', ['data' => [
            'admUsers' => [
                ['id' => (string) $this->host->id, 'name' => 'Klára', 'role' => 'správce', 'state' => 'aktivní'],
                ['id' => 'u9'.time(), 'name' => 'Třetí', 'mail' => 'treti@vzpominky.test', 'role' => 'správce', 'state' => 'pozvaná'],
            ],
        ]])->assertOk();

        $this->assertSame('viewer', $this->rolePivotu($this->host));
        $this->assertNull(User::where('email', 'treti@vzpominky.test')->first());

        $this->patchJson('/api/state', ['data' => [
            'admUsers' => [['id' => (string) $this->host->id, 'name' => 'Klára', 'role' => 'vlastník', 'state' => 'aktivní']],
        ]])->assertOk();

        $this->assertSame($this->vlastnik->id, (int) $this->prostor->fresh()->owner_id);
    }

    // ——— to, kvůli čemu to celé je ———

    public function test_po_odmitnutem_preradeni_je_do_kose_jen_navrh(): void
    {
        $this->patchJson('/api/admin/users/'.$this->partner->id.'/role', ['role' => 'host'])->assertStatus(422);

        $fotka = $this->fotka($this->prostor, $this->vlastnik, 'more.jpg');

        $this->deleteJson('/api/media/'.$fotka->uuid)
            ->assertOk()
            ->assertJsonPath('status', 'proposed');

        $this->assertNull($fotka->fresh()->trashed_at, 'Bez souhlasu partnera fotka v koši být nesmí.');
    }

    /** Obrazovka změnu role u člena dvojice nenabízí — tlačítko by skončilo odmítnutím. */
    public function test_administrace_roli_clena_dvojice_nenabizi(): void
    {
        $skript = File::get(public_path('galerie-admin.js'));

        // `assertTrue` místo `assertMatchesRegularExpression`: ta by při chybě
        // vypsala celý skript administrace.
        $this->assertTrue(preg_match("/canCycle:[^\\n]*u\\.role !== 'správce'/u", $skript) === 1,
            'Role člena dvojice se v administraci cyklovat nesmí.');
        $this->assertTrue(preg_match('/canTransfer:[^\\n]*parUplny/u', $skript) === 1,
            'Předání hostovi se v úplné dvojici nenabízí.');
    }

    private function rolePivotu(User $kdo): ?string
    {
        return $this->prostor->members()->where('users.id', $kdo->id)->first()?->pivot->role;
    }
}
