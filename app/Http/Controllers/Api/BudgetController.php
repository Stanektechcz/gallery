<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Budget;
use App\Models\BudgetCategory;
use App\Models\BudgetEntry;
use App\Models\GallerySpace;
use App\Models\MoneyRequest;
use App\Services\Finance\BudgetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BudgetController extends Controller
{
    public function __construct(private readonly BudgetService $budgets) {}

    public function index(Request $request): JsonResponse
    {
        $space = $this->space($request);
        $user = $request->user();

        return response()->json([
            'budgets' => $this->budgets->forUser($space, $user)->map(fn (Budget $budget) => [
                'uuid' => $budget->uuid,
                'name' => $budget->name,
                'currency' => $budget->currency,
                'starts_on' => $budget->starts_on->toDateString(),
                'ends_on' => $budget->ends_on?->toDateString(),
                'is_shared' => $budget->is_shared,
                'is_mine' => $budget->owner_user_id === $user->id,
                'owner' => $budget->owner?->name,
            ])->values(),
            'requests' => $this->requests($space, $user),
            'members' => $space->members()->get(['users.id', 'users.name'])
                ->reject(fn ($member) => $member->id === $user->id)
                ->map(fn ($member) => ['id' => $member->id, 'name' => $member->name])
                ->values(),
        ]);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $budget = $this->budget($request, $uuid);

        return response()->json($this->budgets->overview($budget));
    }

    public function store(Request $request): JsonResponse
    {
        $this->write($request);
        $space = $this->space($request);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'currency' => 'required|string|size:3',
            'starts_on' => 'required|date',
            'ends_on' => 'nullable|date|after_or_equal:starts_on',
            'monthly_income' => 'nullable|numeric|min:0',
            'note' => 'nullable|string|max:5000',
            'is_shared' => 'sometimes|boolean',
            'personal' => 'sometimes|boolean',
            'savings_target' => 'nullable|numeric|min:0',
            'savings_target_on' => 'nullable|date',
            'period_unit' => 'sometimes|in:month,week',
            'period_mode' => 'sometimes|in:fixed,rolling',
        ]);

        $budget = Budget::create([
            'gallery_space_id' => $space->id,
            // Osobní rozpočet patří tomu, kdo ho založil; společný nikomu.
            'owner_user_id' => ($data['personal'] ?? true) ? $request->user()->id : null,
            'name' => $data['name'],
            'currency' => strtoupper($data['currency']),
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'] ?? null,
            'monthly_income' => $data['monthly_income'] ?? null,
            'savings_target' => $data['savings_target'] ?? null,
            'savings_target_on' => $data['savings_target_on'] ?? null,
            'period_unit' => $data['period_unit'] ?? 'month',
            'period_mode' => $data['period_mode'] ?? 'fixed',
            'note' => $data['note'] ?? null,
            'is_shared' => $data['is_shared'] ?? false,
            'created_by' => $request->user()->id,
        ]);

        return response()->json($this->budgets->overview($budget), 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $this->write($request);
        $budget = $this->budget($request, $uuid, owned: true);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'currency' => 'sometimes|string|size:3',
            'starts_on' => 'sometimes|date',
            'ends_on' => 'nullable|date',
            'monthly_income' => 'nullable|numeric|min:0',
            'note' => 'nullable|string|max:5000',
            'is_shared' => 'sometimes|boolean',
            'savings_target' => 'nullable|numeric|min:0',
            'savings_target_on' => 'nullable|date',
            'period_unit' => 'sometimes|in:month,week',
            'period_mode' => 'sometimes|in:fixed,rolling',
        ]);

        $budget->update($data);

        return response()->json($this->budgets->overview($budget->fresh()));
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $this->write($request);
        $this->budget($request, $uuid, owned: true)->delete();

        return response()->json(['ok' => true]);
    }

    public function storeCategory(Request $request, string $uuid): JsonResponse
    {
        $this->write($request);
        $budget = $this->budget($request, $uuid, owned: true);

        $data = $request->validate([
            'name' => 'required|string|max:120',
            'planned_monthly' => 'nullable|numeric|min:0',
            'color' => 'nullable|string|max:20',
            'icon' => 'nullable|string|max:50',
        ]);

        BudgetCategory::create($data + [
            'budget_id' => $budget->id,
            'planned_monthly' => $data['planned_monthly'] ?? 0,
            'sort_order' => (int) $budget->categories()->max('sort_order') + 10,
        ]);

        return response()->json($this->budgets->overview($budget->fresh()), 201);
    }

    /**
     * Cíle spoření uvnitř rozpočtu.
     *
     * Rozpočet měl jedno pole na cíl. „Letenky domů za čtyři sta" a „notebook za dvanáct
     * set" jsou ale dva různé cíle s různými termíny.
     */
    public function storeGoal(Request $request, string $uuid): JsonResponse
    {
        $this->write($request);
        $budget = $this->budget($request, $uuid, owned: true);

        $data = $request->validate([
            'name' => 'required|string|max:160',
            'target_amount' => 'required|numeric|min:0.01',
            'currency' => 'nullable|string|size:3',
            'saved_amount' => 'nullable|numeric|min:0',
            'target_on' => 'nullable|date',
            'note' => 'nullable|string|max:500',
        ]);

        \App\Models\BudgetGoal::create($data + [
            'budget_id' => $budget->id,
            'currency' => strtoupper($data['currency'] ?? $budget->currency),
            'saved_amount' => $data['saved_amount'] ?? 0,
            'sort_order' => (int) $budget->goals()->max('sort_order') + 10,
        ]);

        return response()->json($this->budgets->overview($budget->fresh()), 201);
    }

    public function updateGoal(Request $request, string $uuid, string $goalUuid): JsonResponse
    {
        $this->write($request);
        $budget = $this->budget($request, $uuid, owned: true);

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:160',
            'target_amount' => 'sometimes|required|numeric|min:0.01',
            'saved_amount' => 'sometimes|nullable|numeric|min:0',
            'target_on' => 'sometimes|nullable|date',
            'note' => 'sometimes|nullable|string|max:500',
        ]);

        $budget->goals()->where('uuid', $goalUuid)->firstOrFail()->update($data);

        return response()->json($this->budgets->overview($budget->fresh()));
    }

    public function destroyGoal(Request $request, string $uuid, string $goalUuid): JsonResponse
    {
        $this->write($request);
        $budget = $this->budget($request, $uuid, owned: true);
        $budget->goals()->where('uuid', $goalUuid)->delete();

        return response()->json($this->budgets->overview($budget->fresh()));
    }

    /**
     * Kdo a kdy měnil plán kategorie.
     *
     * U společného rozpočtu je to informace, která předchází hádce: „jídlo je najednou
     * o padesát víc" má jinou váhu, když je vidět, že to zvedl ten, kdo se ptá.
     *
     * Čte se z kroniky, která v systému už je — vlastní tabulka na historii jednoho pole
     * by znamenala druhý systém na totéž.
     */
    public function categoryHistory(Request $request, string $uuid, int $categoryId): JsonResponse
    {
        $budget = $this->budget($request, $uuid);
        $kategorie = $budget->categories()->whereKey($categoryId)->firstOrFail();

        $zaznamy = \App\Models\AuditLog::where('subject_type', 'BudgetCategory')
            ->where('subject_id', $kategorie->id)
            ->where('action', 'budget.category.plan')
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return response()->json([
            'category' => ['id' => $kategorie->id, 'name' => $kategorie->name],
            'changes' => $zaznamy->map(fn ($z) => [
                'at' => $z->created_at?->toDateTimeString(),
                'by' => $z->user?->name,
                'from' => $z->payload['from'] ?? null,
                'to' => $z->payload['to'] ?? null,
            ])->values(),
        ]);
    }

    /**
     * Souhrn napříč všemi rozpočty, ke kterým člověk má přístup.
     *
     * Kdo má osobní i společný rozpočet, nevidí nikde součet — „kolik nás to dohromady
     * stálo" se musí sčítat ručně mezi dvěma obrazovkami.
     *
     * Po měnách zvlášť, jako všude jinde. Rozpočty se můžou lišit měnou a sečíst je do
     * jednoho čísla by znamenalo hádat kurz.
     */
    public function summary(Request $request): JsonResponse
    {
        $space = $this->space($request);
        $user = $request->user();

        $radky = $this->budgets->forUser($space, $user)->map(function (Budget $budget) {
            $budget->loadMissing(['entries', 'categories']);

            [$od, $do] = $budget->activeWindow();

            $vObdobi = $budget->isRolling()
                ? $budget->entries->filter(fn (BudgetEntry $e) => $e->spent_on->betweenIncluded($od, $do ?? now()))
                : $budget->entries;

            return [
                'uuid' => $budget->uuid,
                'name' => $budget->name,
                'currency' => $budget->currency,
                'is_shared' => (bool) $budget->is_shared,
                'is_rolling' => $budget->isRolling(),
                'spent' => round((float) $vObdobi->where('kind', 'expense')->where('currency', $budget->currency)->sum('amount'), 2),
                'income' => round((float) $vObdobi->where('kind', 'income')->where('currency', $budget->currency)->sum('amount'), 2),
                'planned' => round((float) $budget->categories->sum('planned_monthly') * $budget->monthsCovered(), 2),
                'entries' => $vObdobi->count(),
            ];
        })->values();

        $poMenach = $radky->groupBy('currency')->map(fn ($skupina) => [
            'spent' => round($skupina->sum('spent'), 2),
            'income' => round($skupina->sum('income'), 2),
            'planned' => round($skupina->sum('planned'), 2),
            'budgets' => $skupina->count(),
        ]);

        return response()->json([
            'budgets' => $radky,
            'by_currency' => $poMenach,
        ]);
    }

    /**
     * Kopie rozpočtu na další období.
     *
     * Po půl roce v Německu přijde další pobyt a s ním šest kategorií, jejich plány,
     * přenosy, výchozí dělení a příjmy — všechno znovu ručně. Kopíruje se nastavení,
     * ne historie: zapsané položky patří k minulému období a v novém by lhaly o tom,
     * kolik už je utraceno.
     *
     * Pravidelné platby se dají převzít jako první výskyt v novém období. Bez nich by
     * nový rozpočet neuměl předpovídat ani upozorňovat, dokud by nájem nepřišel podruhé.
     */
    public function duplicate(Request $request, string $uuid): JsonResponse
    {
        $this->write($request);
        $vzor = $this->budget($request, $uuid);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'starts_on' => 'required|date',
            'ends_on' => 'nullable|date|after_or_equal:starts_on',
            'with_recurring' => 'sometimes|boolean',
        ]);

        $vzor->loadMissing(['categories', 'members', 'entries']);

        $novy = Budget::create([
            'gallery_space_id' => $vzor->gallery_space_id,
            'owner_user_id' => $vzor->owner_user_id,
            'name' => $data['name'],
            'currency' => $vzor->currency,
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'] ?? null,
            'monthly_income' => $vzor->monthly_income,
            'savings_target' => null,
            'savings_target_on' => null,
            'period_unit' => $vzor->period_unit,
            'period_mode' => $vzor->period_mode ?? 'fixed',
            'note' => $vzor->note,
            'is_shared' => $vzor->is_shared,
            'created_by' => $request->user()->id,
        ]);

        $mapaKategorii = [];

        foreach ($vzor->categories as $kategorie) {
            $mapaKategorii[$kategorie->id] = BudgetCategory::create([
                'budget_id' => $novy->id,
                'name' => $kategorie->name,
                'planned_monthly' => $kategorie->planned_monthly,
                'rollover' => $kategorie->rollover,
                'default_split' => $kategorie->default_split,
                'default_payer' => $kategorie->default_payer,
                'color' => $kategorie->color,
                'icon' => $kategorie->icon,
                'sort_order' => $kategorie->sort_order,
            ])->id;
        }

        foreach ($vzor->members as $clen) {
            \App\Models\BudgetMember::create([
                'budget_id' => $novy->id,
                'user_id' => $clen->user_id,
                'monthly_income' => $clen->monthly_income,
                'currency' => $clen->currency,
            ]);
        }

        $prevzato = 0;

        if ($data['with_recurring'] ?? false) {
            $zacatek = \Illuminate\Support\Carbon::parse($data['starts_on']);

            foreach ($this->budgets->recurring($vzor) as $platba) {
                // Den v měsíci se přenáší, ale nesmí přetéct do dalšího měsíce —
                // třicátý první v únoru neexistuje.
                $den = $zacatek->copy()->day(min($platba['day_of_month'], $zacatek->daysInMonth));

                BudgetEntry::create([
                    'budget_id' => $novy->id,
                    'budget_category_id' => $mapaKategorii[$this->kategoriePodleJmena($vzor, $platba['category'])] ?? null,
                    'user_id' => $request->user()->id,
                    'paid_by' => $request->user()->id,
                    'split' => $platba['split'],
                    'kind' => $platba['kind'],
                    'amount' => $platba['amount'],
                    'currency' => $platba['currency'],
                    'spent_on' => $den,
                    'note' => $platba['note'],
                    'is_recurring' => true,
                ]);

                $prevzato++;
            }
        }

        return response()->json($this->budgets->overview($novy->fresh()) + ['copied_recurring' => $prevzato], 201);
    }

    /** Id kategorie podle jména — opakované platby nesou jméno, ne id. */
    private function kategoriePodleJmena(Budget $vzor, ?string $jmeno): ?int
    {
        if ($jmeno === null) {
            return null;
        }

        return $vzor->categories->firstWhere('name', $jmeno)?->id;
    }

    /**
     * Rozdělení jednoho nákupu mezi kategorie.
     *
     * Účtenka za devadesát eur, z toho šedesát jídlo a třicet drogerie. Dosud to musely
     * být dvě položky zapsané zvlášť a účtenka šla jen k jedné.
     *
     * Původní položka se nahradí několika novými se stejným datem, plátcem, dělením
     * i účtenkou. Zůstávají samostatné, takže součty nemají jak začít lhát — jen o sobě
     * vědí přes společnou značku a v seznamu se dají ukázat pohromadě.
     *
     * Součet dílů musí sedět na původní částku. Rozdělit devadesát na šedesát a dvacet
     * znamená, že deset zmizelo z rozpočtu, aniž by to kdokoliv poznal.
     */
    public function splitEntry(Request $request, string $uuid, string $entryUuid): JsonResponse
    {
        $this->write($request);
        $budget = $this->budget($request, $uuid);

        $data = $request->validate([
            'parts' => 'required|array|min:2|max:10',
            'parts.*.amount' => 'required|numeric|min:0.01',
            'parts.*.budget_category_id' => 'nullable|integer',
            'parts.*.note' => 'nullable|string|max:500',
        ]);

        $puvodni = $budget->entries()->where('uuid', $entryUuid)->firstOrFail();

        $soucet = collect($data['parts'])->sum('amount');

        abort_if(
            abs($soucet - (float) $puvodni->amount) > 0.01,
            422,
            sprintf('Součet dílů (%s) nesedí na původní částku (%s).', number_format($soucet, 2, ',', ' '), number_format((float) $puvodni->amount, 2, ',', ' ')),
        );

        $platneKategorie = $budget->categories()->pluck('id')->flip();
        $znacka = (string) \Illuminate\Support\Str::uuid();

        foreach ($data['parts'] as $dil) {
            BudgetEntry::create([
                'budget_id' => $budget->id,
                'budget_category_id' => isset($dil['budget_category_id']) && $platneKategorie->has($dil['budget_category_id'])
                    ? (int) $dil['budget_category_id']
                    : null,
                'user_id' => $puvodni->user_id,
                'paid_by' => $puvodni->paid_by,
                'split' => $puvodni->split,
                'split_group' => $znacka,
                'kind' => $puvodni->kind,
                'amount' => $dil['amount'],
                'currency' => $puvodni->currency,
                'exchange_rate' => $puvodni->exchange_rate,
                'spent_on' => $puvodni->spent_on,
                'note' => $dil['note'] ?? $puvodni->note,
                'is_recurring' => false,
                // Účtenka jde ke všem dílům: patří k celému nákupu, ne k jedné jeho části.
                'media_item_id' => $puvodni->media_item_id,
            ]);
        }

        $puvodni->delete();

        return response()->json($this->budgets->overview($budget->fresh()));
    }

    /**
     * Příjmy účastníků — podklad pro poměrné dělení.
     *
     * „Napůl" je férové jen tehdy, když oba vydělávají zhruba stejně. U nájmu za devět
     * set eur to při dvojnásobném rozdílu férové není a končí to tím, že se výdaje raději
     * nedělí vůbec a účtuje se „nějak potom".
     *
     * Vyplnění je dobrovolné. Kdo příjem neuvede, u toho se poměrné dělení nepoužije
     * a spadne zpátky na půlku — hádat, kolik druhý vydělává, je horší než to nevědět.
     */
    public function updateMembers(Request $request, string $uuid): JsonResponse
    {
        $this->write($request);
        $budget = $this->budget($request, $uuid, owned: true);

        $data = $request->validate([
            'members' => 'required|array|max:10',
            'members.*.user_id' => 'required|integer',
            'members.*.monthly_income' => 'nullable|numeric|min:0',
            'members.*.currency' => 'nullable|string|size:3',
        ]);

        $prostor = $budget->gallerySpace;

        foreach ($data['members'] as $radek) {
            // Jen členové prostoru. Bez téhle kontroly by šlo do rozpočtu přidat kohokoliv
            // a jeho příjem by pak ovlivňoval poměr, ve kterém se dělí cizí výdaje.
            if (! $prostor?->members()->whereKey($radek['user_id'])->exists()) {
                continue;
            }

            \App\Models\BudgetMember::updateOrCreate(
                ['budget_id' => $budget->id, 'user_id' => (int) $radek['user_id']],
                [
                    'monthly_income' => $radek['monthly_income'] ?? null,
                    'currency' => isset($radek['currency']) ? strtoupper($radek['currency']) : $budget->currency,
                ],
            );
        }

        return response()->json($this->budgets->overview($budget->fresh()));
    }

    /**
     * Návrh plánu podle toho, co se v kategoriích opravdu utrácí.
     *
     * Vyplnit šest částek je nejtupější práce na celém rozpočtu a zároveň ta, kterou
     * člověk odbude — buď si vymyslí kulaté číslo, nebo pole nechá prázdné a plán pak
     * neřídí nic. Přitom odpověď v datech je: po dvou měsících aplikace ví, kolik za
     * jídlo padne, líp než ten, kdo to má odhadnout.
     *
     * Průměruje se přes uplynulé měsíce, ne přes celé období — jinak by rozpočet
     * otevřený v půlce vydělil dvěma měsíci útraty šesti a navrhl polovinu.
     *
     * Pravidelné položky se počítají zvlášť a nezprůměrují: nájem je každý měsíc stejný
     * a průměr by ho rozmělnil podle toho, kolikátého se rozpočet založil.
     *
     * Nic se nezapisuje. Vrací se návrh, který člověk v přehledu vidí vedle dosavadního
     * plánu a může ho přijmout jen u některých kategorií.
     */
    public function suggestPlan(Request $request, string $uuid): JsonResponse
    {
        $budget = $this->budget($request, $uuid);
        $dnes = \Illuminate\Support\Carbon::today();

        $konec = $budget->ends_on && $dnes->greaterThan($budget->ends_on) ? $budget->ends_on : $dnes;
        $mesicu = max(0.5, $budget->starts_on->diffInDays($konec) / $budget->periodDays());

        $budget->loadMissing(['categories', 'entries']);

        $navrhy = $budget->categories->map(function (BudgetCategory $kategorie) use ($budget, $mesicu) {
            $vydaje = $budget->entries
                ->where('kind', 'expense')
                ->where('budget_category_id', $kategorie->id)
                ->where('currency', $budget->currency);

            $pravidelne = (float) $vydaje->where('is_recurring', true)
                ->groupBy(fn ($e) => $e->spent_on->format('Y-m'))
                ->map(fn ($mesic) => $mesic->sum('amount'))
                ->avg() ?: 0.0;

            $nepravidelne = (float) $vydaje->where('is_recurring', false)->sum('amount') / $mesicu;

            // Zaokrouhluje se na desítky. Návrh „417,32" předstírá přesnost, kterou
            // odhad z pár měsíců nemá, a člověk by ho stejně přepsal na kulaté číslo.
            $navrh = round(($pravidelne + $nepravidelne) / 10) * 10;

            return [
                'id' => $kategorie->id,
                'name' => $kategorie->name,
                'current' => (float) $kategorie->planned_monthly,
                'suggested' => $navrh,
                'from_entries' => $vydaje->count(),
                'recurring_part' => round($pravidelne, 2),
            ];
        })->values()->all();

        return response()->json([
            'months_measured' => round($mesicu, 1),
            'currency' => $budget->currency,
            'period_label' => $budget->periodLabel(),
            'suggestions' => $navrhy,
        ]);
    }

    /**
     * Obvyklé kategorie pro nový rozpočet.
     *
     * Rozpočet bez kategorií neumí nic: plán je nula, „zbývá na den" je nula a přehled
     * nemá co ukázat. Založit šest kategorií po jedné je přitom prvních pět minut práce,
     * které vypadají jako administrativa, a je to poslední místo, kde chce mít člověk
     * pocit, že mu aplikace nepomáhá.
     *
     * Částky zůstávají nulové schválně. Vymyslet za někoho, že na jídlo padne pět tisíc,
     * je horší než nechat pole prázdné — kdo si to nastaví sám, ví, proti čemu se měří.
     * Doplňuje se, nepřepisuje: kategorie, které už podle jména existují, se přeskočí.
     */
    public function storeStarterCategories(Request $request, string $uuid): JsonResponse
    {
        $this->write($request);
        $budget = $this->budget($request, $uuid, owned: true);

        $zaklad = [
            ['Bydlení', '#6366f1'],
            ['Jídlo a nákupy', '#22c55e'],
            ['Doprava', '#f59e0b'],
            ['Zdraví', '#ef4444'],
            ['Volný čas', '#ec4899'],
            ['Ostatní', '#64748b'],
        ];

        $uzJsou = $budget->categories()->pluck('name')->map(fn (string $n) => mb_strtolower($n))->flip();
        $poradi = (int) $budget->categories()->max('sort_order');

        foreach ($zaklad as [$nazev, $barva]) {
            if ($uzJsou->has(mb_strtolower($nazev))) {
                continue;
            }

            BudgetCategory::create([
                'budget_id' => $budget->id,
                'name' => $nazev,
                'planned_monthly' => 0,
                'color' => $barva,
                'sort_order' => $poradi += 10,
            ]);
        }

        return response()->json($this->budgets->overview($budget->fresh()), 201);
    }

    /**
     * Úprava kategorie.
     *
     * Dosud šla kategorie jen založit a smazat. Změnit „Jídlo 420" na „Jídlo 450" tedy
     * znamenalo smazat ji a založit znovu — a protože se položky na kategorii vážou cizím
     * klíčem s `nullOnDelete`, přišly tím všechny dosavadní výdaje o zařazení. Za změnu
     * jednoho čísla se platilo historií, což je cena, kterou nikdo čekat nemůže.
     */
    public function updateCategory(Request $request, string $uuid, int $categoryId): JsonResponse
    {
        $this->write($request);
        $budget = $this->budget($request, $uuid, owned: true);

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:120',
            'planned_monthly' => 'sometimes|nullable|numeric|min:0',
            'rollover' => 'sometimes|boolean',
            // Předvyplní se u nové položky v téhle kategorii. Nákupy bývají vždycky
            // napůl a oblečení nikdy — vybírat to znovu u každé položky znamená stovky
            // kliknutí za pololetí a jednu chybu, která se najde až u vyrovnání.
            'default_split' => 'sometimes|nullable|in:none,equal,other,ratio',
            'default_payer' => 'sometimes|nullable|integer',
            'color' => 'sometimes|nullable|string|max:20',
            'icon' => 'sometimes|nullable|string|max:50',
        ]);

        if (! empty($data['default_payer'])
            && ! $budget->gallerySpace?->members()->whereKey($data['default_payer'])->exists()) {
            $data['default_payer'] = null;
        }

        $kategorie = $budget->categories()->whereKey($categoryId)->firstOrFail();

        if (array_key_exists('planned_monthly', $data) && $data['planned_monthly'] === null) {
            $data['planned_monthly'] = 0;
        }

        // Změna plánu do kroniky. U společného rozpočtu je „jídlo je najednou o padesát
        // víc" informace, která předchází hádce — a bez záznamu se nedá zjistit ani kdo,
        // ani kdy. Ostatní pole se nezaznamenávají: přejmenování kategorie nikoho
        // nepřipraví o peníze.
        if (array_key_exists('planned_monthly', $data)
            && abs((float) $data['planned_monthly'] - (float) $kategorie->planned_monthly) > 0.001) {
            \App\Models\AuditLog::record('budget.category.plan', $kategorie, [
                'from' => (float) $kategorie->planned_monthly,
                'to' => (float) $data['planned_monthly'],
                'budget' => $budget->name,
                'category' => $kategorie->name,
            ]);
        }

        $kategorie->update($data);

        return response()->json($this->budgets->overview($budget->fresh()));
    }

    public function destroyCategory(Request $request, string $uuid, int $categoryId): JsonResponse
    {
        $this->write($request);
        $budget = $this->budget($request, $uuid, owned: true);
        $budget->categories()->whereKey($categoryId)->delete();

        return response()->json($this->budgets->overview($budget->fresh()));
    }

    public function storeEntry(Request $request, string $uuid): JsonResponse
    {
        $this->write($request);
        $budget = $this->budget($request, $uuid);

        $data = $request->validate([
            'kind' => 'required|in:expense,income',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'nullable|string|size:3',
            'spent_on' => 'required|date',
            'budget_category_id' => 'nullable|integer',
            'note' => 'nullable|string|max:500',
            'is_recurring' => 'sometimes|boolean',
            // Kdo zaplatil a jak se to dělí — bez toho se společné výdaje na dálku
            // po půl roce nedopočítají.
            'paid_by' => 'nullable|integer',
            'split' => 'sometimes|in:none,equal,other,ratio',
            'receipt_uuid' => 'nullable|uuid',
        ]);

        // Kategorie musí patřit tomuhle rozpočtu, jinak by šlo zapsat do cizího.
        $kategorie = null;

        if (! empty($data['budget_category_id'])) {
            $kategorie = $budget->categories()->whereKey($data['budget_category_id'])->first();

            abort_unless($kategorie !== null, 422, 'Kategorie do tohoto rozpočtu nepatří.');
        }

        /*
         * Výchozí dělení a plátce z kategorie — ale jen tam, kde volající nic neposlal.
         * Doplňuje se, nepřepisuje: kdo u konkrétního nákupu vybere jinak, má přednost
         * před tím, co je u kategorie nastavené obecně.
         */
        if ($kategorie !== null) {
            if (! array_key_exists('split', $data) && $kategorie->default_split !== null) {
                $data['split'] = $kategorie->default_split;
            }

            if (empty($data['paid_by']) && $kategorie->default_payer !== null) {
                $data['paid_by'] = $kategorie->default_payer;
            }
        }

        // Účtenka se hledá v prostoru rozpočtu, ne kdekoli — jinak by šlo k položce
        // připnout cizí fotku podle uuid.
        $receipt = ! empty($data['receipt_uuid'])
            ? \App\Models\MediaItem::where('uuid', $data['receipt_uuid'])
                ->where('gallery_space_id', $budget->gallery_space_id)
                ->first()
            : null;

        // Plátce musí být člen prostoru; jinak by rozvaha dluhů ukazovala někoho, kdo
        // do ní nepatří.
        $paidBy = ! empty($data['paid_by'])
            && $budget->gallerySpace?->members()->whereKey($data['paid_by'])->exists()
                ? (int) $data['paid_by']
                : $request->user()->id;

        $zapsana = BudgetEntry::create(collect($data)->except(['receipt_uuid', 'paid_by'])->all() + [
            'budget_id' => $budget->id,
            'user_id' => $request->user()->id,
            'paid_by' => $paidBy,
            'media_item_id' => $receipt?->id,
            'currency' => strtoupper($data['currency'] ?? $budget->currency),
        ]);

        $this->ohlasVetsiVydaj($budget, $zapsana, $request->user());

        return response()->json($this->budgets->overview($budget->fresh()), 201);
    }

    /**
     * Kolikanásobek obvyklého výdaje se považuje za velký.
     *
     * Poměr, ne pevná částka: co je hodně, se liší podle rozpočtu i měny. Trojnásobek
     * obvyklého výdaje je dost na to, aby to nebyl běžný nákup, a málo na to, aby zpráva
     * chodila každý druhý den.
     */
    private const VELKY_NASOBEK = 3.0;

    /** Pod tímhle počtem položek se obvyklá částka nedá odhadnout a nehlásí se nic. */
    private const MIN_POLOZEK_PRO_ODHAD = 5;

    /**
     * Dá vědět druhému, když někdo zapsal nezvykle velký výdaj.
     *
     * Pro dva lidi ve dvou zemích je „Makinka zapsala 180 € zubař" zpráva, kvůli které
     * se telefonuje — a dosud se o ní ten druhý dozvěděl, jen když se sám podíval.
     *
     * Hranice je poměrná k tomu, co se v rozpočtu obvykle utrácí, ne pevná částka: pět
     * tisíc korun je jinak velké číslo v rozpočtu na týden a jinak v pololetním. Bere se
     * medián, ne průměr — jeden nájem by průměr vytáhl tak vysoko, že by se nehlásilo nic.
     */
    private function ohlasVetsiVydaj(Budget $budget, BudgetEntry $polozka, $autor): void
    {
        if ($polozka->kind !== 'expense' || ! $budget->is_shared) {
            return;
        }

        $castky = $budget->entries()
            ->where('kind', 'expense')
            ->where('currency', $polozka->currency)
            ->where('id', '!=', $polozka->id)
            ->where('is_recurring', false)
            ->pluck('amount')
            ->map(fn ($c) => (float) $c)
            ->sort()
            ->values();

        if ($castky->count() < self::MIN_POLOZEK_PRO_ODHAD) {
            return;
        }

        $stred = (float) $castky[(int) floor($castky->count() / 2)];

        if ($stred <= 0 || (float) $polozka->amount < $stred * self::VELKY_NASOBEK) {
            return;
        }

        $castka = number_format((float) $polozka->amount, 0, ',', ' ').' '.$polozka->currency;
        $popis = $polozka->note ? sprintf(' — %s', $polozka->note) : '';

        foreach ($budget->gallerySpace?->members()->get() ?? [] as $clen) {
            // Sobě ne: kdo výdaj zapsal, o něm ví.
            if ($clen->id === $autor->id) {
                continue;
            }

            $clen->notify(new \App\Notifications\GalleryNotification(
                'finance.budget',
                sprintf('%s zapsal(a) do rozpočtu „%s" větší výdaj %s%s.', $autor->name, $budget->name, $castka, $popis),
                '/rozpocty?sekce=polozky',
                '💸',
                ['budget_uuid' => $budget->uuid, 'entry_uuid' => $polozka->uuid],
            ));
        }
    }

    public function destroyEntry(Request $request, string $uuid, string $entryUuid): JsonResponse
    {
        $this->write($request);
        $budget = $this->budget($request, $uuid);
        $budget->entries()->where('uuid', $entryUuid)->delete();

        return response()->json($this->budgets->overview($budget->fresh()));
    }

    /**
     * Označí dluh v jedné měně za vyrovnaný.
     *
     * Volitelně z toho rovnou udělá žádost o peníze — panel dosud ukázal částku a vedle
     * něj stál nesouvisející formulář, do kterého ji člověk musel přepsat.
     */
    public function settleUp(Request $request, string $uuid): JsonResponse
    {
        $this->write($request);
        $budget = $this->budget($request, $uuid);

        $data = $request->validate([
            'currency' => 'required|string|size:3',
            'settled_through' => 'nullable|date',
            'request_money' => 'sometimes|boolean',
        ]);

        $radek = collect($this->budgets->settlement($budget))->firstWhere('currency', strtoupper($data['currency']));

        abort_if($radek === null, 422, 'V téhle měně teď není co vyrovnávat.');

        // Žádost odchází dřív než uzávěrka. Kdyby se poslala až potom a odeslání selhalo,
        // dluh by byl zavřený a partner by se o něm nedozvěděl.
        if (($data['request_money'] ?? false) && ! empty($radek['from_id'])) {
            $komu = \App\Models\User::find($radek['from_id']);

            if ($komu && $komu->id !== $request->user()->id) {
                $this->budgets->requestMoney($this->space($request), $request->user(), $komu, [
                    'amount' => $radek['amount'],
                    'currency' => $radek['currency'],
                    'reason' => 'Vyrovnání společných výdajů — '.$budget->name,
                ]);
            }
        }

        $this->budgets->settleUp(
            $budget,
            $request->user(),
            $data['currency'],
            ! empty($data['settled_through']) ? \Illuminate\Support\Carbon::parse($data['settled_through']) : null,
        );

        return response()->json($this->budgets->overview($budget->fresh()), 201);
    }

    /** Vezme uzávěrku zpět — dluh se od téhle chvíle zase počítá od původního data. */
    public function destroySettlement(Request $request, string $uuid, string $settlementUuid): JsonResponse
    {
        $this->write($request);
        $budget = $this->budget($request, $uuid);

        $budget->settlements()->where('uuid', $settlementUuid)->firstOrFail()->delete();

        return response()->json($this->budgets->overview($budget->fresh()));
    }

    /**
     * Opraví existující položku.
     *
     * Dosud šlo položku jen smazat a napsat znovu — a s ní zmizela účtenka i nastavení
     * dělení, takže překlep v částce stál nové focení a nové naklikání.
     */
    public function updateEntry(Request $request, string $uuid, string $entryUuid): JsonResponse
    {
        $this->write($request);
        $budget = $this->budget($request, $uuid);

        $entry = $budget->entries()->where('uuid', $entryUuid)->firstOrFail();

        $data = $request->validate([
            'kind' => 'sometimes|in:expense,income',
            'amount' => 'sometimes|numeric|min:0.01',
            'currency' => 'sometimes|string|size:3',
            'spent_on' => 'sometimes|date',
            'budget_category_id' => 'nullable|integer',
            'note' => 'nullable|string|max:500',
            'is_recurring' => 'sometimes|boolean',
            'paid_by' => 'nullable|integer',
            'split' => 'sometimes|in:none,equal,other,ratio',
            'receipt_uuid' => 'nullable|uuid',
        ]);

        if (! empty($data['budget_category_id'])) {
            abort_unless($budget->categories()->whereKey($data['budget_category_id'])->exists(), 422, 'Kategorie do tohoto rozpočtu nepatří.');
        }

        // Účtenka i plátce se ověřují stejně jako při zakládání — bez toho by šlo
        // úpravou obejít kontrolu, kterou zápis dělá.
        if (array_key_exists('receipt_uuid', $data)) {
            $entry->media_item_id = $data['receipt_uuid']
                ? \App\Models\MediaItem::where('uuid', $data['receipt_uuid'])
                    ->where('gallery_space_id', $budget->gallery_space_id)
                    ->value('id')
                : null;
        }

        if (array_key_exists('paid_by', $data)) {
            $entry->paid_by = ! empty($data['paid_by'])
                && $budget->gallerySpace?->members()->whereKey($data['paid_by'])->exists()
                    ? (int) $data['paid_by']
                    : $entry->user_id;
        }

        if (array_key_exists('currency', $data)) {
            $data['currency'] = strtoupper($data['currency']);
        }

        $entry->fill(collect($data)->except(['receipt_uuid', 'paid_by'])->all())->save();

        return response()->json($this->budgets->overview($budget->fresh()));
    }

    /** Jedna položka tak, jak ji čeká obrazovka. @return array<string, mixed> */
    private function entryPayload(BudgetEntry $entry): array
    {
        return [
            'uuid' => $entry->uuid,
            'kind' => $entry->kind,
            'amount' => (float) $entry->amount,
            'currency' => $entry->currency,
            'spent_on' => $entry->spent_on->toDateString(),
            'note' => $entry->note,
            'is_recurring' => $entry->is_recurring,
            'category' => $entry->category?->name,
            'budget_category_id' => $entry->budget_category_id,
            'author' => $entry->author?->name,
            'paid_by' => $entry->payer?->name ?? $entry->author?->name,
            'paid_by_id' => $entry->paid_by,
            'split' => $entry->split,
            'receipt_uuid' => $entry->receipt?->uuid,
        ];
    }

    /**
     * Měsíce, ve kterých rozpočet vůbec něco má.
     *
     * Formátuje se v PHP, ne v SQL. Lokálně běží SQLite a na serveru MySQL a každá má
     * na formátování data jinou funkci — takový dotaz projde v testech a spadne až
     * v provozu. Datum se vrací jako sloupec a měsíc se z něj udělá tady.
     *
     * @return array<int, string>
     */
    private function entryMonths(Budget $budget): array
    {
        return $budget->entries()
            ->orderByDesc('spent_on')
            ->pluck('spent_on')
            ->map(fn ($datum) => \Illuminate\Support\Carbon::parse($datum)->format('Y-m'))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Položky po stránkách, s hledáním a filtry.
     *
     * Vlastní koncový bod, ne součást přehledu. Přehled počítá souhrny přes všechno a
     * posílat k nim pokaždé i pět set řádků by znamenalo, že se celý seznam přenese
     * znovu při každém zapsání jedné částky.
     */
    public function entries(Request $request, string $uuid): JsonResponse
    {
        $budget = $this->budget($request, $uuid);

        $data = $request->validate([
            'q' => 'nullable|string|max:120',
            'kind' => 'nullable|in:expense,income',
            'category' => 'nullable|string|max:20',
            'month' => 'nullable|date_format:Y-m',
            // Kdo platil. Seznam uměl hledat text, druh, kategorii i měsíc, ale ne
            // plátce — a „ukaž, co jsem platil já" se pak dalo zjistit jen scrollováním.
            'payer' => 'nullable|integer',
            // Jen položky bez účtenky. U velkých částek je to jediný způsob, jak zpětně
            // dohledat, co to vlastně bylo.
            'no_receipt' => 'nullable|boolean',
            'page' => 'nullable|integer|min:1',
        ]);

        $naStranku = 50;
        $stranka = (int) ($data['page'] ?? 1);

        $dotaz = $budget->entries()
            ->with(['category:id,name', 'payer:id,name', 'author:id,name', 'receipt:id,uuid'])
            ->when($data['kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))
            ->when($data['q'] ?? null, fn ($q, $hledane) => $q->where('note', 'like', '%'.$hledane.'%'))
            // 'none' je záměrně jiná hodnota než prázdno: prázdno znamená „nefiltrovat",
            // kdežto 'none' znamená „jen ty, které kategorii nemají".
            ->when(isset($data['category']) && $data['category'] !== '', function ($q) use ($data) {
                $data['category'] === 'none'
                    ? $q->whereNull('budget_category_id')
                    : $q->where('budget_category_id', (int) $data['category']);
            })
            // paid_by je nepovinný sloupec: u starých položek je prázdný a platí, že
            // platil ten, kdo zapsal. Filtr proto musí hledat v obojím, jinak by
            // „co platil Adrian" vynechalo všechno zapsané před zavedením plátce.
            ->when($data['payer'] ?? null, fn ($q, $kdo) => $q->where(function ($vnitrni) use ($kdo) {
                $vnitrni->where('paid_by', $kdo)
                    ->orWhere(fn ($bez) => $bez->whereNull('paid_by')->where('user_id', $kdo));
            }))
            ->when($data['no_receipt'] ?? false, fn ($q) => $q->whereNull('media_item_id'))
            ->when($data['month'] ?? null, function ($q, $mesic) {
                $od = \Illuminate\Support\Carbon::createFromFormat('Y-m', $mesic)->startOfMonth();
                $q->whereBetween('spent_on', [$od->toDateString(), $od->copy()->endOfMonth()->toDateString()]);
            });

        $celkem = (clone $dotaz)->count();

        $polozky = $dotaz
            ->orderByDesc('spent_on')
            ->orderByDesc('id')
            ->forPage($stranka, $naStranku)
            ->get();

        return response()->json([
            'entries' => $polozky->map(fn (BudgetEntry $entry) => $this->entryPayload($entry))->values(),
            'total' => $celkem,
            'page' => $stranka,
            'per_page' => $naStranku,
            'has_more' => $celkem > $stranka * $naStranku,
            // Měsíce, ve kterých vůbec něco je — aby filtr nenabízel prázdná období.
            'months' => $this->entryMonths($budget),
        ]);
    }

    /**
     * Přečte výpis a vrátí, co v něm je. Nic neuloží.
     *
     * Náhled je záměrně samostatný krok: dvě stě položek zapsaných jedním kliknutím se
     * maže hůř, než se ručně píšou.
     */
    public function previewStatement(Request $request, string $uuid): JsonResponse
    {
        $this->write($request);
        $budget = $this->budget($request, $uuid);

        $request->validate([
            'file' => 'required|file|max:5120|mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel,application/octet-stream',
        ]);

        $vysledek = app(\App\Services\Finance\StatementParser::class)
            ->parse((string) file_get_contents($request->file('file')->getRealPath()));

        // Co už v rozpočtu je. Duplicitní řádky se předem odškrtnou — výpisy se stahují
        // po měsících a překryv je pravidlo, ne výjimka.
        $existujici = $budget->entries()
            ->get(['spent_on', 'amount', 'kind'])
            ->map(fn (BudgetEntry $e) => $e->spent_on->toDateString().'|'.number_format((float) $e->amount, 2, '.', '').'|'.$e->kind)
            ->flip();

        // Kategorie se hádají z popisu. Klasifikátor obchodníků v systému už je i s
        // vlastními pravidly prostoru — bez tohohle kroku by každý řádek přistál jako
        // „bez kategorie" a člověk by je proklikával po jednom.
        $rows = collect(app(\App\Services\Finance\CategoryMatcher::class)->suggest($budget, $vysledek['rows']))
            ->map(function (array $radek) use ($existujici, $budget) {
                $klic = $radek['spent_on'].'|'.number_format($radek['amount'], 2, '.', '').'|'.$radek['kind'];
                $radek['duplicate'] = $existujici->has($klic);
                $radek['currency'] ??= $budget->currency;

                return $radek;
            });

        return response()->json([
            'rows' => $rows->values(),
            'skipped' => $vysledek['skipped'],
            'recognised' => array_keys(array_filter($vysledek['columns'], fn ($i) => $i !== null)),
            'duplicates' => $rows->where('duplicate', true)->count(),
            'categorised' => $rows->whereNotNull('suggested_category_id')->count(),
        ]);
    }

    /** Zapíše potvrzené řádky výpisu. */
    public function importStatement(Request $request, string $uuid): JsonResponse
    {
        $this->write($request);
        $budget = $this->budget($request, $uuid);

        $data = $request->validate([
            'rows' => 'required|array|min:1|max:500',
            'rows.*.kind' => 'required|in:expense,income',
            'rows.*.amount' => 'required|numeric|min:0.01',
            'rows.*.currency' => 'nullable|string|size:3',
            'rows.*.spent_on' => 'required|date',
            'rows.*.note' => 'nullable|string|max:500',
            'rows.*.budget_category_id' => 'nullable|integer',
        ]);

        $kategorie = $budget->categories()->pluck('id')->flip();
        $ulozeno = 0;

        foreach ($data['rows'] as $radek) {
            BudgetEntry::create([
                'budget_id' => $budget->id,
                'user_id' => $request->user()->id,
                'paid_by' => $request->user()->id,
                'kind' => $radek['kind'],
                'amount' => $radek['amount'],
                'currency' => strtoupper($radek['currency'] ?? $budget->currency),
                'spent_on' => $radek['spent_on'],
                'note' => $radek['note'] ?? null,
                // Cizí kategorie se zahodí, ne odmítne: kvůli jednomu řádku by nemělo
                // spadnout celé nahrání výpisu.
                'budget_category_id' => isset($radek['budget_category_id']) && $kategorie->has($radek['budget_category_id'])
                    ? $radek['budget_category_id']
                    : null,
            ]);

            $ulozeno++;
        }

        return response()->json($this->budgets->overview($budget->fresh()) + ['imported' => $ulozeno], 201);
    }

    /**
     * Položky jako CSV.
     *
     * Pro účetní, pro daňové přiznání, nebo prostě proto, že data mají patřit tomu, kdo
     * je zapsal. Středník a BOM kvůli Excelu: bez nich česká verze otevře soubor jako
     * jeden sloupec a rozsype diakritiku, což z exportu udělá práci navíc.
     */
    public function export(Request $request, string $uuid): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $budget = $this->budget($request, $uuid);
        $budget->loadMissing(['entries.category', 'entries.payer:id,name', 'entries.author:id,name']);

        $nazev = \Illuminate\Support\Str::slug($budget->name) . '-' . now()->toDateString() . '.csv';

        return response()->streamDownload(function () use ($budget) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            // Prázdný escape je RFC-správné chování a od PHP 8.4 se musí uvést výslovně —
            // jinak se do staženého souboru vypíše deprecation hláška a Excel z ní udělá
            // první řádek tabulky.
            fputcsv($out, ['Datum', 'Druh', 'Částka', 'Měna', 'Kategorie', 'Poznámka', 'Zaplatil', 'Dělení', 'Pravidelné'], ';', '"', '');

            foreach ($budget->entries->sortBy('spent_on') as $entry) {
                fputcsv($out, [
                    $entry->spent_on->toDateString(),
                    $entry->kind === 'income' ? 'příjem' : 'výdaj',
                    number_format((float) $entry->amount, 2, ',', ''),
                    $entry->currency,
                    $entry->category?->name ?? '',
                    $entry->note ?? '',
                    $entry->payer?->name ?? $entry->author?->name ?? '',
                    match ($entry->split) { 'equal' => 'napůl', 'other' => 'za druhého', default => 'moje' },
                    $entry->is_recurring ? 'ano' : 'ne',
                ], ';', '"', '');
            }

            fclose($out);
        }, $nazev, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function requestMoney(Request $request): JsonResponse
    {
        $this->write($request);
        $space = $this->space($request);

        $data = $request->validate([
            'to_user_id' => 'required|integer',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'required|string|size:3',
            'reason' => 'nullable|string|max:500',
        ]);

        $recipient = $space->members()->whereKey($data['to_user_id'])->firstOrFail();

        $this->budgets->requestMoney($space, $request->user(), $recipient, $data);

        return response()->json(['requests' => $this->requests($space, $request->user())], 201);
    }

    public function respondToRequest(Request $request, string $uuid): JsonResponse
    {
        $this->write($request);
        $space = $this->space($request);

        $data = $request->validate([
            'status' => 'required|in:sent,declined,cancelled',
            'settled_amount' => 'nullable|numeric|min:0',
            'settled_currency' => 'nullable|string|size:3',
            'exchange_rate' => 'nullable|numeric|min:0',
            'response_note' => 'nullable|string|max:500',
        ]);

        $money = MoneyRequest::where('gallery_space_id', $space->id)->where('uuid', $uuid)->firstOrFail();

        // Zrušit může jen ten, kdo žádal; poslat nebo zamítnout jen ten, koho se to týká.
        $user = $request->user();
        abort_unless(
            $data['status'] === 'cancelled' ? $money->from_user_id === $user->id : $money->to_user_id === $user->id,
            403,
            'O této žádosti nemůžete rozhodovat.',
        );

        $this->budgets->respond($money, $user, $data['status'], $data);

        return response()->json(['requests' => $this->requests($space, $user)]);
    }

    private function requests(GallerySpace $space, $user): array
    {
        return MoneyRequest::where('gallery_space_id', $space->id)
            ->where(fn ($q) => $q->where('from_user_id', $user->id)->orWhere('to_user_id', $user->id))
            ->with(['requester:id,name', 'recipient:id,name'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (MoneyRequest $money) => [
                'uuid' => $money->uuid,
                'amount' => (float) $money->amount,
                'currency' => $money->currency,
                'settled_amount' => $money->settled_amount !== null ? (float) $money->settled_amount : null,
                'settled_currency' => $money->settled_currency,
                'exchange_rate' => $money->exchange_rate !== null ? (float) $money->exchange_rate : null,
                'reason' => $money->reason,
                'status' => $money->status,
                'response_note' => $money->response_note,
                'created_at' => $money->created_at?->toIso8601String(),
                'responded_at' => $money->responded_at?->toIso8601String(),
                'from' => $money->requester?->name,
                'to' => $money->recipient?->name,
                'mine' => $money->from_user_id === $user->id,
            ])->values()->all();
    }

    private function budget(Request $request, string $uuid, bool $owned = false): Budget
    {
        $space = $this->space($request);
        $budget = Budget::where('gallery_space_id', $space->id)->where('uuid', $uuid)->firstOrFail();

        abort_unless($budget->isVisibleTo($request->user()), 403, 'Tento rozpočet vám není zpřístupněný.');

        if ($owned) {
            abort_unless(
                $budget->owner_user_id === null || $budget->owner_user_id === $request->user()->id,
                403,
                'Upravovat může jen vlastník rozpočtu.',
            );
        }

        return $budget;
    }

    private function space(Request $request): GallerySpace
    {
        return $request->user()->gallerySpaces()->firstOrFail();
    }

    private function write(Request $request): void
    {
        abort_if($request->user()->read_only_mode, 403, 'V režimu pouze pro čtení nelze rozpočty měnit.');
    }
}
