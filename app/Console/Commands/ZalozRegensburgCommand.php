<?php

namespace App\Console\Commands;

use App\Models\Budget;
use App\Models\FinanceAccess;
use App\Models\FinanceCategory;
use App\Models\FinanceProject;
use App\Models\FinanceRecurring;
use App\Models\GallerySpace;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Založí Makinčin pobyt v Regensburgu — účty, cestu a nájem.
 *
 * Existuje proto, že **nasazení veze kód, ne data**. Cokoli se založí ve vývojové
 * databázi, v produkci nevznikne; jediná cesta, jak tam ta čísla dostat, je příkaz,
 * který se dá spustit na tom serveru, kde ta data mají být.
 *
 * Je záměrně opakovatelný: hledá podle jména a co existuje, to neduplikuje. Spustit
 * ho dvakrát tedy nezaloží dvě cesty a dva nájmy — což je přesně ta chyba, kterou by
 * nikdo nezpozoroval, dokud by rozpočet neukazoval dvojnásobné výdaje.
 */
class ZalozRegensburgCommand extends Command
{
    protected $signature = 'rozpocet:regensburg
        {--prostor= : ID prostoru; bez něj se vezme jediný existující}
        {--vlastnik= : Komu cesta patří — jméno, e-mail nebo ID; bez něj bude společná}
        {--sdilet= : Kdo se na cestu smí dívat, ale ne ji měnit — jméno, e-mail nebo ID}';

    protected $description = 'Založí cestu Regensburg 1.9.2026–28.2.2027, účty a pravidelný nájem.';

    /** 70 000 Kč směněných po 24,21 Kč za euro. */
    private const KORUN = 70000.0;

    private const KURZ = 24.21;

    public function handle(): int
    {
        $space = $this->option('prostor')
            ? GallerySpace::findOrFail((int) $this->option('prostor'))
            : GallerySpace::query()->firstOrFail();

        try {
            $vlastnik = $this->uzivatel($this->option('vlastnik'));
            $divak = $this->uzivatel($this->option('sdilet'));
        } catch (\RuntimeException $problem) {
            $this->error($problem->getMessage());
            $this->newLine();
            // Sloupce se vypisují jménem, ne přes toArray() — model si k sobě přidává
            // odvozené hodnoty a tabulka na první z nich spadne.
            $this->table(['ID', 'Jméno', 'E-mail'], User::orderBy('id')->get()
                ->map(fn (User $u) => [(string) $u->id, (string) $u->name, (string) $u->email])->all());

            return self::FAILURE;
        }

        $autor = $vlastnik ?? User::query()->value('id');

        if ($autor === null) {
            $this->error('V databázi není žádný uživatel — nemá kdo být autorem záznamů.');

            return self::FAILURE;
        }

        $eur = round(self::KORUN / self::KURZ, 2);

        DB::transaction(function () use ($space, $vlastnik, $divak, $autor, $eur) {
            $koruny = $this->ucet($space, 'Koruny na cestu', 'CZK', 'bank', self::KORUN);
            $karta = $this->ucet($space, 'Eura na kartě', 'EUR', 'bank', 0);
            $hotovost = $this->ucet($space, 'Eura v hotovosti', 'EUR', 'cash', 0);

            $cesta = FinanceProject::firstOrCreate(
                ['gallery_space_id' => $space->id, 'kind' => 'trip', 'name' => 'Regensburg'],
                [
                    'country' => 'Německo',
                    'city' => 'Regensburg',
                    'starts_on' => '2026-09-01',
                    'ends_on' => '2027-02-28',
                    'base_currency' => 'EUR',
                    'budget_amount' => $eur,
                    // Rezerva na cestu zpátky a na to, co se nedá naplánovat. Bez ní by
                    // „bezpečně na den" slibovalo i poslední euro, které být nemá.
                    'reserve_amount' => 150,
                    'default_wallet_id' => $karta->id,
                    'state' => 'draft',
                    'owner_user_id' => $vlastnik,
                ],
            );

            // Vlastníka nastaví i u cesty, která už existovala. Příkaz se spouští znovu
            // právě proto, aby se nastavení dorovnalo — kdyby vlastníka měnil jen při
            // vzniku, druhé spuštění by tiše nechalo cestu společnou.
            if ($cesta->owner_user_id !== $vlastnik) {
                $cesta->update(['owner_user_id' => $vlastnik]);
            }

            $najem = FinanceCategory::firstOrCreate(
                ['gallery_space_id' => $space->id, 'name' => 'Ubytování', 'kind' => 'expense'],
                ['color' => '#6366f1', 'is_active' => true],
            );

            $najemPredpis = FinanceRecurring::firstOrCreate(
                ['gallery_space_id' => $space->id, 'name' => 'Nájem Regensburg'],
                [
                    'type' => 'expense',
                    'amount' => 280,
                    'currency' => 'EUR',
                    'wallet_id' => $karta->id,
                    'finance_category_id' => $najem->id,
                    'finance_project_id' => $cesta->id,
                    'day_of_month' => 1,
                    'starts_on' => '2026-09-01',
                    'ends_on' => '2027-02-28',
                    'is_active' => true,
                    'created_by' => $autor,
                    'note' => 'Šest nájmů, 1 680 € z celkových '.number_format($eur, 2, ',', ' ').' €.',
                ],
            );

            // Rozpočet na cestu je strop, cesta je seskupení útrat. Dvě různé věci, které
            // se ale musí shodnout na období — proto si ho rozpočet bere z cesty.
            $rozpocet = Budget::firstOrCreate(
                ['gallery_space_id' => $space->id, 'scope' => 'ledger', 'name' => 'Německo — Regensburg'],
                [
                    'budget_kind' => 'trip',
                    'finance_project_id' => $cesta->id,
                    'currency' => 'EUR',
                    'starting_funds' => $eur,
                    'reserve_amount' => 150,
                    'starts_on' => $cesta->starts_on,
                    'ends_on' => $cesta->ends_on,
                    'period_mode' => 'fixed',
                    // Jede se s pevnou sumou, takže cokoli, co cestou přijde, je navíc
                    // a rozdělí se to samo podle pořadí.
                    'income_adds' => true,
                    'owner_user_id' => $vlastnik,
                    'is_shared' => $vlastnik === null,
                    'created_by' => $autor,
                ],
            );

            // Dorovnání pro rozpočet, který už existoval — příkaz se spouští znovu právě
            // proto, aby nastavení sedělo, ne aby ho nechal takové, jaké zrovna je.
            $rozpocet->update([
                'owner_user_id' => $vlastnik,
                'is_shared' => $vlastnik === null,
                'income_adds' => true,
                'starting_funds' => $eur,
                'reserve_amount' => 150,
            ]);

            // Předpis, který vznikl při dřívějším spuštění, může ukazovat na jinou
            // kategorii, než jakou hlídá plán. Pak by nájem padal mimo rozpočet a
            // vypadalo by to, že se na bydlení nic neutrácí.
            $najemPredpis->update([
                'finance_category_id' => $najem->id,
                'finance_project_id' => $cesta->id,
                'wallet_id' => $karta->id,
            ]);

            $this->vyhrad($space, $rozpocet);
            $this->uklidZdvojenou($space, 'Bydlení');

            $this->pristup($space, 'trip', $cesta->id, $divak);
            $this->pristup($space, 'budget', $rozpocet->id, $divak);

            // Vypisuje se jméno účtu, který se doopravdy použil, ne to, které chtěl
            // příkaz. Kdo má eurovou kartu pojmenovanou „EUR karta", musí v protokolu
            // vidět ji — jinak by hledal „Eura na kartě", které nikde není.
            $this->radek($koruny->name, number_format((float) $koruny->opening_balance, 0, ',', ' ').' Kč', $koruny->wasRecentlyCreated);
            $this->radek($karta->name, number_format((float) $karta->opening_balance, 0, ',', ' ').' €', $karta->wasRecentlyCreated);
            $this->radek($hotovost->name, number_format((float) $hotovost->opening_balance, 0, ',', ' ').' €', $hotovost->wasRecentlyCreated);

            // Na cestu se počítá se 70 000 Kč. Když má nalezený účet jiný počátek,
            // musí to zaznít — jinak se rozpočet opře o částku, která na účtu není.
            if (abs((float) $koruny->opening_balance - self::KORUN) > 0.5) {
                $this->warn('  pozor      účet „'.$koruny->name.'" má '
                    .number_format((float) $koruny->opening_balance, 0, ',', ' ').' Kč, plán počítá se 70 000 Kč.');
            }
            $this->radek('Cesta Regensburg', number_format($eur, 2, ',', ' ').' € na 181 dní', $cesta->wasRecentlyCreated);
            $this->radek('Rozpočet Německo — Regensburg', 'strop nad cestou', $rozpocet->wasRecentlyCreated);
            $this->radek('Nájem', '280 € každého 1.', $najemPredpis->wasRecentlyCreated);
        });

        $this->newLine();
        $this->table(
            ['Na co', 'Vyhrazeno', 'Pořadí'],
            collect(self::PLAN)->map(fn (array $r, string $n) => [
                $n,
                number_format($r[0], 0, ',', ' ').' €',
                match (true) { $r[1] <= 10 => 'nutné', $r[1] <= 50 => 'důležité', default => 'když zbyde' },
            ])->values()->all(),
        );

        $vyhrazeno = array_sum(array_column(self::PLAN, 0));
        $volne = $eur - 150 - $vyhrazeno;
        $naDen = self::PLAN['Potraviny'][0] / 181;

        $this->line('Celkem '.number_format($eur, 2, ',', ' ').' €, z toho 150 € rezerva a '
            .number_format($vyhrazeno, 0, ',', ' ').' € vyhrazeno. Volných zůstává '
            .number_format($volne, 2, ',', ' ').' €.');
        $this->warn('Na jídlo to vychází '.number_format($naDen, 2, ',', ' ').' € na den. '
            .'Na Německo je to velmi málo a bez vlastního vaření to nevyjde.');

        return self::SUCCESS;
    }

    /**
     * Účet na peníze dané měny a druhu — přednostně ten, který už existuje.
     *
     * Hledá se podle **měny a druhu, ne podle jména.** Kdo si eurovou kartu pojmenoval
     * „EUR karta", nechce vedle ní druhou jménem „Eura na kartě" — a přesně to dělalo
     * hledání podle názvu: každé spuštění vedle sebe postavilo paralelní sadu účtů,
     * mezi kterými se peníze rozdělily a ani jeden neukazoval celý zůstatek.
     *
     * Počáteční zůstatek se u nalezeného účtu nepřepisuje. Je to údaj, ze kterého se
     * počítá celá historie, a tiše ho posunout znamená posunout ji taky.
     */
    private function ucet(GallerySpace $space, string $nazev, string $mena, string $druh, float $pocatek): Wallet
    {
        // Hotovost je jiná věc než účet; karta a bankovní účet jsou z pohledu peněz
        // totéž. Kdyby se párovalo přesně podle druhu, „EUR karta" a „Eura na kartě"
        // by vedle sebe stály dál — jedna jako `card`, druhá jako `bank`.
        $hotovost = $druh === 'cash';

        $existujici = Wallet::where('gallery_space_id', $space->id)
            ->where('currency', $mena)
            ->when($hotovost, fn ($q) => $q->where('kind', 'cash'))
            ->when(! $hotovost, fn ($q) => $q->where('kind', '!=', 'cash'))
            ->orderBy('id')
            ->first();

        if ($existujici !== null) {
            return $existujici;
        }

        return Wallet::create([
            'gallery_space_id' => $space->id,
            'name' => $nazev,
            'currency' => $mena,
            'kind' => $druh,
            'opening_balance' => $pocatek,
            'is_active' => true,
        ]);
    }

    /**
     * Na co jsou peníze vyhrazené — celý plán na 181 dní, ne na měsíc.
     *
     * Rozpočet je jedna suma na celý pobyt, takže i vyhrazené částky jsou za celé
     * období. Přepočítávat je na měsíc by znamenalo dělit číslem, které nesedí:
     * září má 30 dní, únor 28 a jeden měsíc navíc na konci žádný není.
     *
     * Pořadí: nižší číslo je dřív. 10 je nutné, 50 důležité, 90 „když zbyde".
     *
     * @var array<string, array{0: float, 1: int}>  název kategorie => [částka, pořadí]
     */
    private const PLAN = [
        // Kategorie musí být ta, kterou aplikace nabízí sama. Vlastní „Bydlení" vedle
        // dodávaného „Ubytování" znamená dvě kategorie pro totéž — a nájem se zapíše
        // do té, kterou plán nehlídá.
        'Ubytování' => [1680, 10],          // 6 × 280 €, jediná pevně daná položka
        'Potraviny' => [720, 10],           // necelá 4 € na den — velmi málo
        'Doprava' => [120, 20],             // MHD, ne cesty domů
        'Drogerie a domácnost' => [120, 50], // 6 × 20 €
        'Volný čas a výlety' => [60, 90],   // první, co odpadne, když peníze nevyjdou
    ];

    /** Zapíše plán. Přepisuje se celý, aby se opakovaným spuštěním nesčítal. */
    private function vyhrad(GallerySpace $space, Budget $rozpocet): void
    {
        DB::table('budget_category_limits')->where('budget_id', $rozpocet->id)->delete();

        foreach (self::PLAN as $nazev => [$castka, $poradi]) {
            $kategorie = FinanceCategory::firstOrCreate(
                ['gallery_space_id' => $space->id, 'name' => $nazev, 'kind' => 'expense'],
                ['is_active' => true],
            );

            DB::table('budget_category_limits')->insert([
                'budget_id' => $rozpocet->id,
                'finance_category_id' => $kategorie->id,
                'amount' => $castka,
                'priority' => $poradi,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /**
     * Najde uživatele podle jména, e-mailu nebo ID.
     *
     * Jméno je schválně první možnost. Vnitřní ID se v produkci liší od vývojové
     * databáze, takže příkaz opsaný odjinud by přiřadil cestu **někomu jinému** — a
     * vypadalo by to, že proběhl v pořádku. Jméno se pozná na obou stranách stejně.
     *
     * Když jméno sedí na víc lidí, radši selže. Tichý výběr toho prvního by znamenal,
     * že se soukromý rozpočet ukáže cizímu člověku.
     */
    private function uzivatel(?string $zadani): ?int
    {
        if (! $zadani) {
            return null;
        }

        $nalezeni = User::query()
            ->when(ctype_digit($zadani), fn ($q) => $q->orWhere('id', (int) $zadani))
            ->orWhereRaw('lower(email) = ?', [mb_strtolower($zadani)])
            ->orWhereRaw('lower(name) = ?', [mb_strtolower($zadani)])
            ->get();

        if ($nalezeni->count() === 1) {
            return (int) $nalezeni->first()->id;
        }

        throw new \RuntimeException($nalezeni->isEmpty()
            ? "Uživatele „{$zadani}\" jsem nenašel. Použijte jméno, e-mail nebo ID z tabulky níž."
            : "Jméno „{$zadani}\" sedí na víc lidí. Použijte e-mail nebo ID z tabulky níž.");
    }

    /**
     * Odklidí kategorii, kterou dřívější verze příkazu založila zbytečně.
     *
     * Aplikace nabízí „Ubytování"; starší běh k němu přidal ještě „Bydlení" a nájem
     * skončil v jedné z nich, zatímco plán hlídal druhou. Odkládá se jen tehdy, když
     * na ni nic neukazuje — cizí kategorii se stejným jménem by to jinak smazalo
     * uživateli pod rukama.
     */
    private function uklidZdvojenou(GallerySpace $space, string $nazev): void
    {
        $kategorie = FinanceCategory::where('gallery_space_id', $space->id)
            ->where('name', $nazev)->where('kind', 'expense')->first();

        if ($kategorie === null) {
            return;
        }

        $pouzita = DB::table('transactions')->where('category_id', $kategorie->id)->exists()
            || DB::table('finance_recurring')->where('finance_category_id', $kategorie->id)->exists()
            || DB::table('budget_category_limits')->where('finance_category_id', $kategorie->id)->exists();

        if ($pouzita) {
            return;
        }

        $kategorie->delete();
        $this->line('  odklizeno  zdvojená kategorie „'.$nazev.'" — nájem patří do „Ubytování"');
    }

    /** Náhled pro druhého — vidí, jak se cesta vyvíjí, ale nezapisuje do ní. */
    private function pristup(GallerySpace $space, string $druh, int $id, ?int $divak): void
    {
        if ($divak === null) {
            return;
        }

        FinanceAccess::updateOrCreate(
            ['subject_type' => $druh, 'subject_id' => $id, 'user_id' => $divak],
            ['gallery_space_id' => $space->id, 'can_edit' => false],
        );
    }

    private function radek(string $co, string $detail, bool $nove): void
    {
        $this->line(($nove ? '  vzniklo  ' : '  bylo už  ')."{$co} — {$detail}");
    }
}
