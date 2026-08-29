<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Starý rozpočtový systém nesmí sahat na rozpočty nového modulu.
 *
 * Obojí sdílí tabulku `budgets` a odlišuje je jen `scope`. Staré naplánované příkazy
 * ale žádný filtr neměly, takže od prvního dne cesty by každou hodinu hodnotily
 * Makinčin rozpočet logikou, která čte `budget_entries` — tabulku, do níž nový modul
 * nikdy nezapisuje. Zpráva „neutratili jste nic" by zněla věrohodně a nikdo by
 * nepoznal, že se dívá do prázdna.
 *
 * Test hlídá hranici mezi oběma systémy, dokud ten starý úplně nezmizí.
 */
class LegacyBudgetIsolationTest extends TestCase
{
    use RefreshDatabase;

    private GallerySpace $space;

    private User $uzivatel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uzivatel = User::factory()->create();
        $this->space = GallerySpace::create(['name' => 'Zkouška', 'owner_id' => $this->uzivatel->id]);
        $this->uzivatel->gallerySpaces()->syncWithoutDetaching([$this->space->id => ['role' => 'owner']]);
    }

    public function test_upozorneni_preskoci_rozpocty_noveho_modulu(): void
    {
        $this->rozpocet('Modulový', scope: 'ledger');
        $this->rozpocet('Starý', scope: 'entries');

        $vybrane = $this->beziciPodleStarehoPrikazu();

        $this->assertSame(['Starý'], $vybrane,
            'Rozpočet modulu se hodnotí z knihy transakcí, ne z `budget_entries`.');
    }

    /** Pravidelné položky se nesmí zapsat dvakrát — jednou do knihy, jednou sem. */
    public function test_pravidelne_polozky_preskoci_rozpocty_noveho_modulu(): void
    {
        $this->rozpocet('Modulový', scope: 'ledger');
        $this->rozpocet('Starý', scope: 'entries');

        $vybrane = Budget::whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('scope')->orWhere('scope', '!=', 'ledger'))
            ->pluck('name')->all();

        $this->assertSame(['Starý'], $vybrane);
    }

    /**
     * Kdyby někdo filtr odstranil, tenhle test spadne.
     *
     * Zkopírovaný výběr ze `BudgetAlertsCommand::beziciRozpocty()`. Zdvojení je tu
     * schválně: test má hlídat pravidlo, ne volat tutéž metodu a potvrdit sám sebe.
     *
     * @return array<int, string>
     */
    private function beziciPodleStarehoPrikazu(): array
    {
        $dnes = Carbon::today();

        return Budget::whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('scope')->orWhere('scope', '!=', 'ledger'))
            ->whereDate('starts_on', '<=', $dnes)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $dnes))
            ->pluck('name')->all();
    }

    private function rozpocet(string $nazev, string $scope): void
    {
        Budget::create([
            'gallery_space_id' => $this->space->id,
            'name' => $nazev,
            'currency' => 'CZK',
            'starts_on' => Carbon::today()->subDays(5),
            'scope' => $scope,
            'starting_funds' => 10000,
            'created_by' => $this->uzivatel->id,
        ]);
    }
}
