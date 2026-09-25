<?php

namespace Tests\Feature\Obsah41;

use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\GallerySpace;
use App\Models\User;
use App\Support\SpaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Zprávy jen z hovorů, ve kterých divák je.
 *
 * Obsah obrazovky bral všechny zprávy prostoru — i z kanálu jen na pozvánku
 * nebo z přímého hovoru partnera s hostem, kam divák nepatří.
 */
class ZpravyClenstviTest extends TestCase
{
    use RefreshDatabase;

    public function test_zpravy_z_cizich_hovoru_se_neposilaji(): void
    {
        $adri = User::factory()->create(['name' => 'Adrian']);
        $maki = User::factory()->create(['name' => 'Makinka']);
        $host = User::factory()->create(['name' => 'Host']);
        $prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $adri->id]);
        $prostor->members()->syncWithoutDetaching([
            $adri->id => ['role' => 'owner'],
            $maki->id => ['role' => 'editor'],
            $host->id => ['role' => 'viewer'],
        ]);

        $nas = $this->hovor($prostor, 'direct', null, [$adri, $maki]);
        $jejich = $this->hovor($prostor, 'direct', null, [$maki, $host]);
        $tajny = $this->hovor($prostor, 'channel', 'invite', [$maki]);
        $otevreny = $this->hovor($prostor, 'channel', 'open', []);

        $this->zprava($prostor, $maki, 'Náš hovor', $nas);
        $this->zprava($prostor, $maki, 'Jejich hovor', $jejich);
        $this->zprava($prostor, $maki, 'Tajný kanál', $tajny);
        $this->zprava($prostor, $maki, 'Otevřený kanál', $otevreny);
        $this->zprava($prostor, $maki, 'Stará bez hovoru', null);

        Sanctum::actingAs($adri);
        $texty = collect($this->getJson('/api/data/zpravy')->assertOk()->json('data.MSGS'))->pluck(5)->all();

        $this->assertEqualsCanonicalizing(['Náš hovor', 'Otevřený kanál', 'Stará bez hovoru'], $texty);
    }

    /** @param  list<User>  $ucastnici */
    private function hovor(GallerySpace $prostor, string $druh, ?string $viditelnost, array $ucastnici): int
    {
        $id = DB::table('conversations')->insertGetId([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $prostor->id, 'created_by' => $prostor->owner_id,
            'kind' => $druh, 'visibility' => $viditelnost ?? Conversation::VISIBILITY_INVITE, 'title' => $druh,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ($ucastnici as $u) {
            DB::table('conversation_participants')->insert([
                'conversation_id' => $id, 'user_id' => $u->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }

    private function zprava(GallerySpace $prostor, User $kdo, string $text, ?int $hovor): void
    {
        ChatMessage::withoutGlobalScope(SpaceContext::SCOPE)->create([
            'gallery_space_id' => $prostor->id, 'conversation_id' => $hovor, 'created_by' => $kdo->id, 'body' => $text,
        ]);
    }
}
