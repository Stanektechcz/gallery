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
            'color' => 'sometimes|nullable|string|max:20',
            'icon' => 'sometimes|nullable|string|max:50',
        ]);

        $kategorie = $budget->categories()->whereKey($categoryId)->firstOrFail();

        if (array_key_exists('planned_monthly', $data) && $data['planned_monthly'] === null) {
            $data['planned_monthly'] = 0;
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
            'split' => 'sometimes|in:none,equal,other',
            'receipt_uuid' => 'nullable|uuid',
        ]);

        // Kategorie musí patřit tomuhle rozpočtu, jinak by šlo zapsat do cizího.
        if (! empty($data['budget_category_id'])) {
            abort_unless($budget->categories()->whereKey($data['budget_category_id'])->exists(), 422, 'Kategorie do tohoto rozpočtu nepatří.');
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

        BudgetEntry::create(collect($data)->except(['receipt_uuid', 'paid_by'])->all() + [
            'budget_id' => $budget->id,
            'user_id' => $request->user()->id,
            'paid_by' => $paidBy,
            'media_item_id' => $receipt?->id,
            'currency' => strtoupper($data['currency'] ?? $budget->currency),
        ]);

        return response()->json($this->budgets->overview($budget->fresh()), 201);
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
            'split' => 'sometimes|in:none,equal,other',
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
