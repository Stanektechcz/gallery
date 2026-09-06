<?php

namespace Tests\Feature\Galerie;

use App\Models\ChatMessage;
use App\Models\GallerySpace;
use App\Models\User;
use App\Support\SpaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Odeslaná zpráva se opravdu odešle.
 *
 * „Zpráva odeslána," řekl prototyp — a nikam ji neodeslal. Bublina se objevila
 * v prohlížeči odesílatele, druhý z dvojice o ní nevěděl a po zavření záložky
 * zmizela.
 */
class ZpravyVeStavuTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private User $maki;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create(['name' => 'Adrian']);
        $this->maki = User::factory()->create(['name' => 'Makinka']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([
            $this->adri->id => ['role' => 'owner'],
            $this->maki->id => ['role' => 'editor'],
        ]);

        Sanctum::actingAs($this->adri);
    }

    /** Nová replika skončí v chatu, ne ve stavu. */
    public function test_nova_replika_skonci_v_chatu(): void
    {
        $this->zprava($this->maki, 'Vzala jsem fotky z Pusteven.');

        $odpoved = $this->stav(['chat' => [
            ['who' => 'm', 'text' => 'Vzala jsem fotky z Pusteven.', 'meta' => '7:02', 'id' => 'm0'],
            ['who' => 'a', 'text' => 'Viděl. Ta s mlhou je do fotoknihy.', 'meta' => '7:11 · odesláno', 'id' => 'm-n1'],
        ]])->assertOk();

        $zpravy = ChatMessage::withoutGlobalScope(SpaceContext::SCOPE)->orderBy('id')->get();

        $this->assertCount(2, $zpravy);
        $this->assertSame('Viděl. Ta s mlhou je do fotoknihy.', $zpravy[1]->body);
        $this->assertSame($this->adri->id, $zpravy[1]->created_by);

        // Ve stavu klíč nezůstane a odpověď nese hovor ze serveru.
        $this->assertArrayNotHasKey('chat', (array) $this->getJson('/api/state')->assertOk()->json('data'));
        $this->assertSame('m1', $odpoved->json('data.chat.1.id'));
        $this->assertSame('a', $odpoved->json('data.chat.1.who'));
        $this->assertContains('chat', $odpoved->json('docasne'));
    }

    /** Repliky, které přišly ze serveru, se neukládají znovu. */
    public function test_stary_hovor_se_neuklada_znovu(): void
    {
        $this->zprava($this->maki, 'Stará zpráva');

        $this->stav(['chat' => [
            ['who' => 'm', 'text' => 'Stará zpráva', 'meta' => '7:02', 'id' => 'm0'],
        ]])->assertOk();

        $this->assertSame(1, ChatMessage::withoutGlobalScope(SpaceContext::SCOPE)->count());
    }

    /** Tentýž patch po výpadku sítě hovor nezopakuje. */
    public function test_opakovany_patch_zpravu_nezdvoji(): void
    {
        $patch = ['chat' => [
            ['who' => 'a', 'text' => 'Dneska večer.', 'meta' => '7:14 · odesláno', 'id' => 'm-n1'],
        ]];

        $this->stav($patch)->assertOk();
        $this->stav($patch)->assertOk();

        $this->assertSame(1, ChatMessage::withoutGlobalScope(SpaceContext::SCOPE)->count());
    }

    /** Cizí bublinu za druhého nikdo nenapíše. */
    public function test_replika_druheho_se_neodesle(): void
    {
        $this->stav(['chat' => [
            ['who' => 'm', 'text' => 'Tohle jsem nenapsala', 'meta' => '7:02', 'id' => 'm-n9'],
        ]])->assertOk();

        $this->assertSame(0, ChatMessage::withoutGlobalScope(SpaceContext::SCOPE)->count());
    }

    /** Prázdná bublina není zpráva. */
    public function test_prazdna_bublina_se_neodesle(): void
    {
        $this->stav(['chat' => [
            ['who' => 'a', 'text' => '   ', 'meta' => '7:14', 'id' => 'm-n1'],
        ]])->assertOk();

        $this->assertSame(0, ChatMessage::withoutGlobalScope(SpaceContext::SCOPE)->count());
    }

    /** Odeslaná zpráva z obrazovky Zpráv skončí v chatu taky. */
    public function test_replika_z_vlakna_skonci_v_chatu(): void
    {
        $odpoved = $this->stav(['msgList' => [
            ['id' => 'g-n1', 'who' => 'A', 'day' => 'Dnes', 'time' => '7:11', 'type' => 't',
                'text' => 'Ta s mlhou je do fotoknihy.', 'extra' => '', 'n' => 0],
        ]])->assertOk();

        $zpravy = ChatMessage::withoutGlobalScope(SpaceContext::SCOPE)->get();

        $this->assertCount(1, $zpravy);
        $this->assertSame('Ta s mlhou je do fotoknihy.', $zpravy[0]->body);
        $this->assertSame('A', $odpoved->json('data.msgList.0.who'));
        $this->assertContains('msgList', $odpoved->json('docasne'));
    }

    /**
     * Odpověď, kterou si prototyp dopisoval sám, se neuloží.
     *
     * Ukázka po 2,6 sekundy dopsala větu a označila ji jako od druhého
     * z dvojice. Se skutečným chatem je to nejhorší možná lež.
     */
    public function test_vymyslena_odpoved_se_neulozi(): void
    {
        $this->stav(['msgList' => [
            ['id' => 'g-n1-r', 'who' => 'M', 'day' => 'Dnes', 'time' => '7:12', 'type' => 't',
                'text' => 'Tohle jsem nenapsala', 'extra' => '', 'n' => 0],
        ]])->assertOk();

        $this->assertSame(0, ChatMessage::withoutGlobalScope(SpaceContext::SCOPE)->count());
    }

    /** Zpráva patří páru, ve kterém se napsala. */
    public function test_zprava_patri_svemu_paru(): void
    {
        $this->stav(['chat' => [
            ['who' => 'a', 'text' => 'Naše zpráva', 'meta' => '7:14', 'id' => 'm-n1'],
        ]])->assertOk();

        $this->assertSame(
            $this->prostor->id,
            ChatMessage::withoutGlobalScope(SpaceContext::SCOPE)->value('gallery_space_id'),
        );
    }

    // ——— pomůcky ———

    private function stav(array $patch)
    {
        return $this->patchJson('/api/state', ['data' => $patch]);
    }

    private function zprava(User $kdo, string $text): void
    {
        ChatMessage::withoutGlobalScope(SpaceContext::SCOPE)->create([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $kdo->id,
            'body' => $text,
        ]);
    }
}
