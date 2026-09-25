<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Api\Galerie\Concerns\UrcujePar;
use App\Http\Controllers\Api\Galerie\Concerns\VraciObsah;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Budget;
use App\Models\BudgetGoal;
use App\Models\BudgetSettlement;
use App\Models\FinanceAccess;
use App\Models\FinanceCategory;
use App\Models\FinanceRecurring;
use App\Models\FinanceSettings;
use App\Models\GallerySpace;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\Obsah\Finance;
use App\Services\Obsah\FinanceRozbory;
use App\Support\Cas;
use App\Support\SpaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Finance z obrazovek galerie — to, co dosud hlásilo „zatím neumíme".
 *
 * Poznámka a vynechání transakce z rozpočtu, platba jako opakovaná, rozdělení
 * do víc kategorií, plánované platby (přidat, přeskočit), limity kategorií,
 * přesun peněz mezi nimi, vyhrazené částky a vyrovnání mezi dvojicí. Kniha
 * a rozpočet v aplikaci všechno z toho mají; obrazovky galerie jen neměly kudy
 * zapsat. Kategorie a lidé chodí jménem, jak je prototyp zná — prostor se
 * hlídá u každého řádku zvlášť.
 */
class FinanceAkceController extends Controller
{
    use UrcujePar;
    use VraciObsah;

    public function __construct(private readonly Finance $obsah) {}

    // ——— transakce ———

    public function poznamka(Request $request, string $uuid): JsonResponse
    {
        [$prostor, $t] = $this->transakce($request, $uuid);
        $data = $request->validate(['poznamka' => ['nullable', 'string', 'max:500']]);

        $t->update(['note' => trim((string) ($data['poznamka'] ?? '')) ?: null]);

        return $this->hotovo($prostor, $t->note ? 'Poznámka uložena' : 'Poznámka smazána');
    }

    /** Vynechat z rozpočtu (s důvodem) nebo vrátit zpátky. */
    public function rozpocet(Request $request, string $uuid): JsonResponse
    {
        [$prostor, $t] = $this->transakce($request, $uuid);
        $data = $request->validate([
            'vynechat' => ['required', 'boolean'],
            // Bez důvodu se za půl roku nedá zjistit, proč se částka nikde nepočítá.
            'duvod' => ['required_if:vynechat,true', 'nullable', 'string', 'max:200'],
        ]);

        $t->update([
            'excluded_from_budget' => $data['vynechat'],
            'exclusion_reason' => $data['vynechat'] ? trim((string) $data['duvod']) : null,
        ]);

        return $this->hotovo($prostor, $data['vynechat']
            ? 'Platba se do rozpočtu nepočítá · důvod zapsán'
            : 'Platba se zase počítá do rozpočtu');
    }

    /**
     * Platba jako opakovaná: z transakce vznikne předpis na stejný den v měsíci.
     *
     * Tahle transakce je jeho první splátka (`recurring_id`), takže ji generátor
     * nezapíše podruhé.
     */
    public function opakovat(Request $request, string $uuid): JsonResponse
    {
        [$prostor, $t] = $this->transakce($request, $uuid);

        if ($t->recurring_id) {
            return $this->chyba('Tahle platba už opakovaná je.');
        }

        if (! in_array($t->type, ['expense', 'income'], true)) {
            return $this->chyba('Opakovat jde výdaj nebo příjem, ne převod či směnu.');
        }

        $ucet = $t->type === 'income' ? $t->wallet_to_id : $t->wallet_from_id;
        $castka = abs((float) ($t->type === 'income' ? ($t->amount_to ?? $t->amount_from) : ($t->amount_from ?? $t->amount_to)));

        if (! $ucet || $castka <= 0) {
            return $this->chyba('Platba nemá účet nebo částku — z takové předpis nevznikne.');
        }

        $den = Carbon::parse($t->occurred_at);

        DB::transaction(function () use ($t, $prostor, $request, $ucet, $castka, $den) {
            $predpis = FinanceRecurring::create([
                'gallery_space_id' => $prostor->id,
                'name' => $t->description ?: ($t->counterparty ?: 'Pravidelná platba'),
                'type' => $t->type,
                'amount' => $castka,
                'currency' => $t->type === 'income' ? $t->currency_to : $t->currency_from,
                'wallet_id' => $ucet,
                'finance_category_id' => $t->category_id,
                'finance_project_id' => $t->finance_project_id,
                'payer_partner_id' => $t->payer_partner_id,
                'day_of_month' => $den->day,
                'starts_on' => $den->toDateString(),
                'created_by' => $request->user()->id,
                'is_active' => true,
            ]);

            $t->update(['recurring_id' => $predpis->id]);
        });

        return $this->hotovo($prostor, 'Opakuje se každý měsíc '.$den->day.'. dne · další platba se objeví v Nadcházejících');
    }

    /**
     * Rozdělení jedné platby do víc kategorií.
     *
     * Kniha zná jednu kategorii na transakci, takže se platba rozdělí na
     * víc transakcí se stejným datem, účtem a popisem. Součet musí sedět
     * do haléře — jinak by se zůstatek účtu tiše pohnul.
     */
    public function rozdelit(Request $request, string $uuid): JsonResponse
    {
        [$prostor, $t] = $this->transakce($request, $uuid);
        $data = $request->validate([
            'casti' => ['required', 'array', 'min:2', 'max:8'],
            'casti.*.kategorie' => ['required', 'string', 'max:120'],
            'casti.*.castka' => ['required', 'numeric', 'gt:0'],
        ]);

        if ($t->type !== 'expense') {
            return $this->chyba('Rozdělit do kategorií jde jen výdaj.');
        }

        $celkem = abs((float) $t->amount_from);
        $soucet = round(array_sum(array_map(fn ($c) => (float) $c['castka'], $data['casti'])), 2);

        if (abs($soucet - $celkem) > 0.01) {
            throw ValidationException::withMessages([
                'casti' => 'Části dávají '.$soucet.', platba má '.$celkem.'. Rozdíl musí být nula.',
            ]);
        }

        $kategorie = [];
        foreach ($data['casti'] as $c) {
            $kategorie[] = $this->kategorie($prostor, $c['kategorie']);
        }

        DB::transaction(function () use ($t, $data, $kategorie) {
            $puvodniSdileni = $t->shares()->exists();

            foreach ($data['casti'] as $i => $c) {
                $castka = round((float) $c['castka'], 2);

                if ($i === 0) {
                    $t->update(['amount_from' => $castka, 'category_id' => $kategorie[0]->id]);

                    continue;
                }

                $nova = $t->replicate(['uuid', 'client_key', 'recurring_id']);
                $nova->uuid = (string) Str::uuid();
                $nova->amount_from = $castka;
                $nova->category_id = $kategorie[$i]->id;
                $nova->fee_amount = 0;
                $nova->save();
            }

            // Rozdělení mezi partnery sedělo na původní částku; po změně by lhalo.
            if ($puvodniSdileni) {
                $t->shares()->delete();
            }
        });

        return $this->hotovo($prostor, 'Platba rozdělena do '.count($data['casti']).' kategorií');
    }

    // ——— plánované platby ———

    public function pridatPlatbu(Request $request): JsonResponse
    {
        $prostor = $this->prostor($request);
        $data = $request->validate([
            'nazev' => ['required', 'string', 'max:120'],
            'castka' => ['required', 'numeric', 'gt:0', 'max:100000000'],
            'den' => ['required', 'integer', 'min:1', 'max:31'],
            'kategorie' => ['nullable', 'string', 'max:120'],
            'prijem' => ['sometimes', 'boolean'],
        ]);

        $ucet = Wallet::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)->where('is_active', true)
            ->orderBy('sort_order')->orderBy('id')->first();

        if ($ucet === null) {
            return $this->chyba('Nejdřív založte účet v Rozpočtu — plánovaná platba z něj bude odcházet.');
        }

        // Dnešek dvojice (Praha), jako proměnlivý Carbon — níž se mění na místě.
        $dnes = Carbon::parse(Cas::dnes()->toDateString());
        // Začíná nejbližším takovým dnem, ne zpětně — jinak by předpis dopsal minulost.
        $start = $dnes->copy()->day(min((int) $data['den'], $dnes->daysInMonth));
        if ($start->lessThan($dnes)) {
            $start = $dnes->copy()->startOfMonth()->addMonthNoOverflow();
            $start->day(min((int) $data['den'], $start->daysInMonth));
        }

        FinanceRecurring::create([
            'gallery_space_id' => $prostor->id,
            'name' => trim($data['nazev']),
            'type' => ($data['prijem'] ?? false) ? 'income' : 'expense',
            'amount' => round((float) $data['castka'], 2),
            'currency' => $ucet->currency,
            'wallet_id' => $ucet->id,
            'finance_category_id' => ! empty($data['kategorie']) ? $this->kategorie($prostor, $data['kategorie'])->id : null,
            'day_of_month' => (int) $data['den'],
            'starts_on' => $start->toDateString(),
            'created_by' => $request->user()->id,
            'is_active' => true,
        ]);

        return $this->hotovo($prostor, 'Plánovaná platba přidána · poprvé '.$start->format('j. n.'), 201);
    }

    /**
     * Přeskočit jeden termín.
     *
     * Stejně jako smazaná splátka: v knize zůstane smazaný záznam k tomu dni
     * a generátor ho už nevytvoří. Nic se neodečte, historie ví, že se přeskočilo.
     */
    public function preskocit(Request $request, string $uuid): JsonResponse
    {
        $prostor = $this->prostor($request);
        $data = $request->validate(['datum' => ['required', 'date']]);

        $predpis = FinanceRecurring::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)->where('uuid', $uuid)->firstOrFail();

        $den = Carbon::parse($data['datum'])->startOfDay();
        $terminy = collect($predpis->terminy($den, $den))->map->toDateString();

        if (! $terminy->contains($den->toDateString())) {
            return $this->chyba('V tenhle den platba z předpisu nepřichází.');
        }

        $uz = Transaction::withTrashed()->where('recurring_id', $predpis->id)
            ->whereDate('occurred_at', $den->toDateString())->exists();

        if (! $uz) {
            $prijem = $predpis->type === 'income';
            $zaznam = Transaction::create([
                'gallery_space_id' => $prostor->id,
                'type' => $predpis->type,
                'occurred_at' => $den->toDateString(),
                'wallet_from_id' => $prijem ? null : $predpis->wallet_id,
                'wallet_to_id' => $prijem ? $predpis->wallet_id : null,
                'amount_from' => $prijem ? null : $predpis->amount,
                'currency_from' => $prijem ? null : $predpis->currency,
                'amount_to' => $prijem ? $predpis->amount : null,
                'currency_to' => $prijem ? $predpis->currency : null,
                'category_id' => $predpis->finance_category_id,
                'description' => $predpis->name,
                'note' => 'přeskočeno',
                'recurring_id' => $predpis->id,
                'state' => 'approved',
                'created_by' => $request->user()->id,
            ]);
            $zaznam->delete();
        }

        return $this->hotovo($prostor, $predpis->name.' — '.$den->format('j. n.').' přeskočeno');
    }

    /** Ručně vedený účet — napojení na banku z galerie není. */
    public function pridatUcet(Request $request): JsonResponse
    {
        $prostor = $this->prostor($request);
        $data = $request->validate([
            'nazev' => ['required', 'string', 'max:160'],
            'druh' => ['required', 'in:bank,card,cash,other'],
            'mena' => ['required', 'string', 'size:3', 'alpha'],
            'zustatek' => ['nullable', 'numeric', 'between:-100000000,100000000'],
        ]);

        $existuje = Wallet::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($data['nazev']))])
            ->exists();

        if ($existuje) {
            return $this->chyba('Účet „'.trim($data['nazev']).'“ už máte.');
        }

        Wallet::create([
            'gallery_space_id' => $prostor->id,
            'name' => trim($data['nazev']),
            'kind' => $data['druh'],
            'currency' => strtoupper($data['mena']),
            'opening_balance' => round((float) ($data['zustatek'] ?? 0), 2),
            'is_active' => true,
            'sort_order' => (int) Wallet::withoutGlobalScope(SpaceContext::SCOPE)->where('gallery_space_id', $prostor->id)->max('sort_order') + 10,
        ]);

        return $this->hotovo($prostor, 'Účet „'.trim($data['nazev']).'“ založen', 201);
    }

    /**
     * Převod mezi vlastními účty — třeba vklad na spoření.
     *
     * „Dokoupit" u spořicího účtu hlásilo „zatím neumíme". Převod není výdaj
     * ani příjem: do rozpočtu se nepočítá, jen přesune zůstatek. Bez `z` jde
     * z hlavního účtu.
     */
    public function prevod(Request $request): JsonResponse
    {
        $prostor = $this->prostor($request);
        $data = $request->validate([
            'z' => ['nullable', 'string', 'max:160'],
            'na' => ['required', 'string', 'max:160'],
            'castka' => ['required', 'numeric', 'gt:0', 'max:100000000'],
        ]);

        $ucty = Wallet::withoutGlobalScope(SpaceContext::SCOPE)->where('gallery_space_id', $prostor->id)->where('is_active', true)
            ->orderBy('sort_order')->orderBy('id')->get();
        $jmenem = fn (string $n) => $ucty->first(fn (Wallet $w) => mb_strtolower($w->name) === mb_strtolower(trim($n)));

        $na = $jmenem($data['na']);
        $z = ! empty($data['z']) ? $jmenem($data['z']) : $ucty->first(fn (Wallet $w) => $na === null || $w->id !== $na->id);

        if ($na === null || $z === null) {
            return $this->chyba('Převod potřebuje dva vaše účty — založte je v záložce Účty.');
        }

        if ($z->id === $na->id) {
            return $this->chyba('Zdrojový a cílový účet nemůže být stejný.');
        }

        if ($z->currency !== $na->currency) {
            return $this->chyba('Účty mají různou měnu ('.$z->currency.' a '.$na->currency.') — převod mezi nimi je směna, tu zapíšete v Rozpočtu.');
        }

        $castka = round((float) $data['castka'], 2);

        Transaction::create([
            'gallery_space_id' => $prostor->id,
            'type' => 'transfer',
            'occurred_at' => Cas::dnes()->toDateString(),
            'wallet_from_id' => $z->id,
            'wallet_to_id' => $na->id,
            'amount_from' => $castka,
            'currency_from' => $z->currency,
            'amount_to' => $castka,
            'currency_to' => $na->currency,
            'description' => 'Převod na '.$na->name,
            'excluded_from_budget' => true,
            'exclusion_reason' => 'převod mezi vlastními účty',
            'state' => 'approved',
            'created_by' => $request->user()->id,
        ]);

        return $this->hotovo($prostor, 'Převedeno z '.$z->name.' na '.$na->name, 201);
    }

    // ——— rozpočet ———

    /**
     * Založení rozpočtu z obrazovky Rozpočty.
     *
     * Bez rozpočtu obrazovka radila „založte ho v Rozpočtech" — tedy sama na
     * sebe — a limity, tlačítka − / + ani obálka neměly do čeho zapisovat.
     * Limity se odhadnou z průměrné útraty tří celých měsíců (po stovkách
     * nahoru); kategorie bez útrat začínají na nule. Odhad je zároveň
     * „původní plán", ke kterému se dá vrátit.
     */
    public function zalozRozpocet(Request $request): JsonResponse
    {
        $prostor = $this->prostor($request);
        $data = $request->validate(['prijem' => ['nullable', 'numeric', 'min:0', 'max:100000000']]);

        $rozpocet = $this->aktualniRozpocet($prostor);

        if ($rozpocet !== null && DB::table('budget_category_limits')->where('budget_id', $rozpocet->id)->exists()) {
            return $this->chyba('Rozpočet už je založený — limity měňte u kategorií.');
        }

        if ($rozpocet !== null) {
            abort_unless(FinanceAccess::smiUpravit('budget', $rozpocet->id, $rozpocet->owner_user_id, $request->user()->id), 403,
                'Do tohohle rozpočtu se smíte dívat, ale ne v něm měnit.');
        }

        // Měna existujícího rozpočtu, jinak domácí měna prostoru — odhad limitů
        // počítá jen útratu ve stejné měně, ve které se rozpočet zakládá.
        $mena = $rozpocet?->currency ?: (FinanceSettings::proProstor($prostor->id)->home_currency ?: 'CZK');
        $obvykle = $this->obsah->obvykleUtraty($prostor, now()->toImmutable(), $mena);
        $kategorii = 0;

        DB::transaction(function () use ($prostor, $request, $data, $obvykle, &$rozpocet, &$kategorii) {
            FinanceCategory::nachystej($prostor->id);

            $rozpocet ??= Budget::withoutGlobalScope(SpaceContext::SCOPE)->create([
                'gallery_space_id' => $prostor->id,
                'owner_user_id' => null,
                'name' => 'Domácnost',
                'currency' => FinanceSettings::proProstor($prostor->id)->home_currency ?: 'CZK',
                'starts_on' => Cas::dnes()->startOfMonth()->toDateString(),
                'period_mode' => 'rolling',
                'budget_kind' => 'monthly',
                'scope' => 'ledger',
                'is_shared' => true,
                'monthly_income' => isset($data['prijem']) ? round((float) $data['prijem'], 2) : null,
                'created_by' => $request->user()->id,
            ]);

            $kategorie = FinanceCategory::withoutGlobalScope(SpaceContext::SCOPE)
                ->where('gallery_space_id', $prostor->id)->where('kind', 'expense')->where('is_active', true)
                ->orderBy('sort_order')->get(['id']);

            foreach ($kategorie as $k) {
                $odhad = (float) (ceil(($obvykle[$k->id] ?? 0) / 100) * 100);

                DB::table('budget_category_limits')->insert([
                    'budget_id' => $rozpocet->id, 'finance_category_id' => $k->id,
                    'amount' => $odhad, 'baseline_amount' => $odhad, 'priority' => 50,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $kategorii++;
            }

            $this->log($prostor, $rozpocet, 'puvodni-odhad', null, null, null, $request);
        });

        return $this->hotovo($prostor, 'Rozpočet založen · '.$kategorii.' '.($kategorii === 1 ? 'kategorie' : ($kategorii < 5 ? 'kategorie' : 'kategorií')).' s odhadem z posledních tří měsíců', 201);
    }

    /** Měsíční limity kategorií: `{ kategorie: částka za měsíc }`. */
    public function limity(Request $request): JsonResponse
    {
        [$prostor, $rozpocet] = $this->rozpocetKZapisu($request);
        $data = $request->validate([
            'limity' => ['required', 'array', 'min:1', 'max:60'],
            'limity.*' => ['numeric', 'min:0', 'max:100000000'],
        ]);

        $mesicu = max(1, $rozpocet->monthsCovered());
        $zmeneno = 0;

        DB::transaction(function () use ($data, $prostor, $rozpocet, $mesicu, $request, &$zmeneno) {
            foreach ($data['limity'] as $nazev => $mesicne) {
                $kategorie = $this->kategorie($prostor, (string) $nazev);
                $radek = DB::table('budget_category_limits')->where('budget_id', $rozpocet->id)->where('finance_category_id', $kategorie->id);
                $puvodni = $radek->value('amount');
                $nove = round((float) $mesicne * $mesicu, 2);

                if ($puvodni !== null && abs((float) $puvodni - $nove) < 0.01) {
                    continue;
                }

                if ($puvodni === null) {
                    DB::table('budget_category_limits')->insert([
                        'budget_id' => $rozpocet->id, 'finance_category_id' => $kategorie->id,
                        'amount' => $nove, 'baseline_amount' => $nove, 'priority' => 50,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                } else {
                    $radek->update(['amount' => $nove, 'updated_at' => now()]);
                }

                $this->log($prostor, $rozpocet, 'rucne', $kategorie->id, $puvodni === null ? null : (float) $puvodni, $nove, $request);
                $zmeneno++;
            }
        });

        return $this->hotovo($prostor, $zmeneno ? 'Plán uložen · změněno '.$zmeneno.' '.($zmeneno === 1 ? 'kategorie' : 'kategorií') : 'Plán už byl uložený takhle');
    }

    /**
     * Obálka jen pro sebe: měsíční limit její kategorie.
     *
     * Obálka se pozná podle názvu kategorie (viz FinanceRozbory). Kdo ji ještě
     * nemá, dostával na „Zvednout obálku" jen „zatím neumíme" — tady se
     * kategorie založí rovnou, i s limitem.
     */
    public function obalka(Request $request): JsonResponse
    {
        [$prostor, $rozpocet] = $this->rozpocetKZapisu($request);
        $data = $request->validate(['castka' => ['required', 'numeric', 'min:0', 'max:100000000']]);

        $nove = round((float) $data['castka'] * max(1, $rozpocet->monthsCovered()), 2);
        $zalozena = false;

        $nazev = DB::transaction(function () use ($prostor, $rozpocet, $nove, $request, &$zalozena) {
            $existujici = FinanceRozbory::osobniKategorie($prostor);
            $kategorie = $existujici
                ? FinanceCategory::withoutGlobalScope(SpaceContext::SCOPE)->findOrFail($existujici->id)
                : null;

            if ($kategorie === null) {
                // Dřív smazaná obálka se vrátí — nová by narazila na jedinečný název.
                $kategorie = FinanceCategory::withoutGlobalScope(SpaceContext::SCOPE)->withTrashed()->firstOrNew(
                    ['gallery_space_id' => $prostor->id, 'name' => 'Obálka pro sebe', 'kind' => 'expense'],
                    ['icon' => 'wallet', 'sort_order' => (int) FinanceCategory::withoutGlobalScope(SpaceContext::SCOPE)->where('gallery_space_id', $prostor->id)->max('sort_order') + 10],
                );
                $kategorie->is_active = true;
                $kategorie->deleted_at = null;
                $kategorie->save();
                $zalozena = true;
            }

            $radek = DB::table('budget_category_limits')->where('budget_id', $rozpocet->id)->where('finance_category_id', $kategorie->id);
            $puvodni = $radek->value('amount');

            if ($puvodni === null) {
                DB::table('budget_category_limits')->insert([
                    'budget_id' => $rozpocet->id, 'finance_category_id' => $kategorie->id,
                    'amount' => $nove, 'baseline_amount' => $nove, 'priority' => 50,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } elseif (abs((float) $puvodni - $nove) >= 0.01) {
                $radek->update(['amount' => $nove, 'updated_at' => now()]);
            } else {
                return $kategorie->name;
            }

            $this->log($prostor, $rozpocet, 'rucne', $kategorie->id, $puvodni === null ? null : (float) $puvodni, $nove, $request);

            return $kategorie->name;
        });

        return $this->hotovo($prostor, $zalozena ? 'Obálka založena — kategorie „'.$nazev.'“ v rozpočtu' : 'Limit obálky „'.$nazev.'“ uložen', $zalozena ? 201 : 200);
    }

    /** Přesun peněz mezi kategoriemi: limit jedné dolů, druhé nahoru — plán celkem se nemění. */
    public function presun(Request $request): JsonResponse
    {
        [$prostor, $rozpocet] = $this->rozpocetKZapisu($request);
        $data = $request->validate([
            'z' => ['required', 'string', 'max:120'],
            'do' => ['required', 'string', 'max:120', 'different:z'],
            'castka' => ['required', 'numeric', 'gt:0'],
        ]);

        $z = $this->kategorie($prostor, $data['z']);
        $do = $this->kategorie($prostor, $data['do']);
        $mesicu = max(1, $rozpocet->monthsCovered());
        $castka = round((float) $data['castka'] * $mesicu, 2);

        $limitZ = (float) DB::table('budget_category_limits')->where('budget_id', $rozpocet->id)->where('finance_category_id', $z->id)->value('amount');

        if ($limitZ + 0.001 < $castka) {
            return $this->chyba('V kategorii '.$z->name.' tolik volných peněz není.');
        }

        DB::transaction(function () use ($rozpocet, $z, $do, $castka, $limitZ, $prostor, $request) {
            DB::table('budget_category_limits')->where('budget_id', $rozpocet->id)->where('finance_category_id', $z->id)
                ->update(['amount' => $limitZ - $castka, 'updated_at' => now()]);

            $cil = DB::table('budget_category_limits')->where('budget_id', $rozpocet->id)->where('finance_category_id', $do->id);
            $puvodni = $cil->value('amount');

            if ($puvodni === null) {
                DB::table('budget_category_limits')->insert([
                    'budget_id' => $rozpocet->id, 'finance_category_id' => $do->id,
                    'amount' => $castka, 'baseline_amount' => 0, 'priority' => 50,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                $cil->update(['amount' => (float) $puvodni + $castka, 'updated_at' => now()]);
            }

            $this->log($prostor, $rozpocet, 'presun', $z->id, $limitZ, $limitZ - $castka, $request);
            $this->log($prostor, $rozpocet, 'presun', $do->id, $puvodni === null ? null : (float) $puvodni, (float) ($puvodni ?? 0) + $castka, $request);
        });

        return $this->hotovo($prostor, 'Přesunuto z '.$z->name.' do '.$do->name);
    }

    public function puvodniPlan(Request $request): JsonResponse
    {
        [$prostor, $rozpocet] = $this->rozpocetKZapisu($request);

        DB::table('budget_category_limits')->where('budget_id', $rozpocet->id)->whereNotNull('baseline_amount')
            ->update(['amount' => DB::raw('baseline_amount'), 'updated_at' => now()]);

        $this->log($prostor, $rozpocet, 'puvodni-odhad', null, null, null, $request);

        return $this->hotovo($prostor, 'Plán je zpátky na původním odhadu');
    }

    public function pridatCil(Request $request): JsonResponse
    {
        [$prostor, $rozpocet] = $this->rozpocetKZapisu($request);
        $data = $request->validate([
            'nazev' => ['required', 'string', 'max:120'],
            'castka' => ['required', 'numeric', 'gt:0', 'max:100000000'],
            'termin' => ['nullable', 'date', 'after:'.Cas::dnes()->toDateString()],
            'poznamka' => ['nullable', 'string', 'max:500'],
        ]);

        BudgetGoal::create([
            'budget_id' => $rozpocet->id,
            'name' => trim($data['nazev']),
            'target_amount' => round((float) $data['castka'], 2),
            'currency' => $rozpocet->currency ?: 'CZK',
            'saved_amount' => 0,
            'target_on' => $data['termin'] ?? null,
            'note' => $data['poznamka'] ?? null,
            'sort_order' => (int) $rozpocet->goals()->max('sort_order') + 1,
        ]);

        return $this->hotovo($prostor, 'Vyhrazená částka „'.trim($data['nazev']).'“ založena', 201);
    }

    /** Vklad do vyhrazené částky — i záporný, když se z ní bralo. */
    public function vklad(Request $request, string $uuid): JsonResponse
    {
        [$prostor, $rozpocet] = $this->rozpocetKZapisu($request);
        $data = $request->validate(['castka' => ['required', 'numeric', 'not_in:0', 'between:-100000000,100000000']]);

        $cil = BudgetGoal::where('budget_id', $rozpocet->id)->where('uuid', $uuid)->firstOrFail();
        $nove = round((float) $cil->saved_amount + (float) $data['castka'], 2);

        if ($nove < 0) {
            return $this->chyba('Vybrat víc, než je v „'.$cil->name.'“ uloženo, nejde.');
        }

        $cil->update(['saved_amount' => $nove]);

        return $this->hotovo($prostor, ((float) $data['castka'] > 0 ? 'Vloženo do' : 'Vybráno z').' „'.$cil->name.'“');
    }

    /**
     * Vyrovnání mezi dvojicí k dnešku.
     *
     * `budget_settlements` neruší platby — říká „k tomuhle dni srovnáno".
     * Aplikace peníze neposílá; zapisuje, že se vyrovnalo.
     */
    public function vyrovnat(Request $request): JsonResponse
    {
        $prostor = $this->prostor($request);
        $rozpocet = $this->aktualniRozpocet($prostor);

        if ($rozpocet === null) {
            return $this->chyba('Vyrovnání se zapisuje k rozpočtu — nejdřív ho založte v Rozpočtech.');
        }

        $data = $request->validate([
            'castka' => ['required', 'numeric', 'gt:0', 'max:100000000'],
            'od' => ['required', 'string', 'max:120'],
            'komu' => ['required', 'string', 'max:120', 'different:od'],
        ]);

        $clenove = $prostor->members()->get(['users.id', 'users.name']);
        $najdi = fn (string $jmeno) => $clenove->first(fn ($u) => mb_strtolower(trim($u->name)) === mb_strtolower(trim($jmeno))
            || mb_strtolower(Str::before(trim($u->name), ' ')) === mb_strtolower(trim($jmeno)));

        $od = $najdi($data['od']);
        $komu = $najdi($data['komu']);

        if ($od === null || $komu === null) {
            return $this->chyba('Vyrovnání je jen mezi vámi dvěma.');
        }

        BudgetSettlement::create([
            'budget_id' => $rozpocet->id,
            'currency' => $rozpocet->currency ?: 'CZK',
            'settled_through' => Cas::dnes()->toDateString(),
            'amount' => round((float) $data['castka'], 2),
            'from_user_id' => $od->id,
            'to_user_id' => $komu->id,
            'created_by' => $request->user()->id,
        ]);

        AuditLog::record('finance.settle', null, ['castka' => (float) $data['castka']]);

        return $this->hotovo($prostor, 'Vyrovnání zapsáno k dnešku · platby se od teď počítají znovu');
    }

    // ——— pomocné ———

    private function prostor(Request $request): GallerySpace
    {
        return GallerySpace::findOrFail($this->parId($request));
    }

    /** @return array{0: GallerySpace, 1: Transaction} */
    private function transakce(Request $request, string $uuid): array
    {
        $prostor = $this->prostor($request);
        $t = Transaction::where('gallery_space_id', $prostor->id)->where('uuid', $uuid)->firstOrFail();

        return [$prostor, $t];
    }

    private function kategorie(GallerySpace $prostor, string $nazev): FinanceCategory
    {
        $k = FinanceCategory::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->where('name', trim($nazev))
            ->first();

        if ($k === null) {
            throw ValidationException::withMessages(['kategorie' => 'Kategorie „'.$nazev.'“ v Rozpočtu není.']);
        }

        return $k;
    }

    private function aktualniRozpocet(GallerySpace $prostor): ?Budget
    {
        // Stejný výběr jako poskytovatel obsahu — obrazovka musí zapisovat tam, co ukazuje.
        return Budget::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->orderByRaw('CASE WHEN starts_on <= ? AND (ends_on IS NULL OR ends_on >= ?) THEN 0 ELSE 1 END', [now(), now()])
            ->orderByDesc('starts_on')
            ->first();
    }

    /** @return array{0: GallerySpace, 1: Budget} */
    private function rozpocetKZapisu(Request $request): array
    {
        $prostor = $this->prostor($request);
        $rozpocet = $this->aktualniRozpocet($prostor);

        abort_if($rozpocet === null, 422, 'Rozpočet zatím není — založte ho v Rozpočtech.');
        abort_unless(FinanceAccess::smiUpravit('budget', $rozpocet->id, $rozpocet->owner_user_id, $request->user()->id), 403,
            'Do tohohle rozpočtu se smíte dívat, ale ne v něm měnit.');

        return [$prostor, $rozpocet];
    }

    private function log(GallerySpace $prostor, Budget $rozpocet, string $akce, ?int $kategorie, ?float $z, ?float $na, Request $request): void
    {
        DB::table('finance_plan_log')->insert([
            'gallery_space_id' => $prostor->id,
            'budget_id' => $rozpocet->id,
            'finance_category_id' => $kategorie,
            'action' => $akce,
            'amount_from' => $z,
            'amount_to' => $na,
            'currency' => $rozpocet->currency,
            'user_id' => $request->user()->id,
            'user_name' => $request->user()->name,
            'created_at' => now(),
        ]);
    }

    private function hotovo(GallerySpace $prostor, string $zprava, int $kod = 200): JsonResponse
    {
        return response()->json(['ok' => true, 'zprava' => $zprava] + $this->obsahPoAkci($this->obsah, $prostor), $kod);
    }

    private function chyba(string $zprava): JsonResponse
    {
        return response()->json(['ok' => false, 'zprava' => $zprava], 422);
    }
}
