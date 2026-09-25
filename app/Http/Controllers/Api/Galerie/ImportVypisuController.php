<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Api\Galerie\Concerns\UrcujePar;
use App\Http\Controllers\Api\Galerie\Concerns\VraciObsah;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BankImport;
use App\Models\BankTransaction;
use App\Models\GallerySpace;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\Banking\RevolutStatementImportService;
use App\Services\Finance\ZarazeniPodlePopisu;
use App\Services\Obsah\Finance;
use App\Support\SpaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Výpis z banky do knihy plateb.
 *
 * Obrazovka Transakcí slibovala „Import z Revolutu ústí sem", ale import
 * výpisu zapisoval jen do bankovního modulu (`bank_transactions`), který
 * Transakce, rozpočet ani vyrovnání nečtou. U účtu se pak dalo jen „stahování
 * z banky zatím neumíme".
 *
 * Výpis (CSV, XLS, XLSX z Revolutu a podobných bank) přečte a odduplikuje
 * `RevolutStatementImportService`; co z něj nově přibylo, se zapíše na
 * vybraný účet jako platby bez kategorie — zařadit je jde hned v Transakcích
 * (záložka Nezařazené, importované mají vlastní záložku).
 *
 * Tentýž řádek se do knihy dostane jen jednou: klíč platby je odvozený
 * z bankovního pohybu, takže opakované nahrání téhož výpisu (nebo výpisu,
 * který se s předchozím překrývá) nic nezdvojí.
 */
class ImportVypisuController extends Controller
{
    use UrcujePar;
    use VraciObsah;

    /** Jmenný prostor pro klíče plateb z výpisu — stálý, ať klíč vyjde vždy stejně. */
    private const PROSTOR_KLICU = 'b0d9a4a2-6f5e-4c1e-9a55-3f0f6d2b7c11';

    public function __construct(
        private readonly RevolutStatementImportService $vypisy,
        private readonly Finance $obsah,
        private readonly ZarazeniPodlePopisu $zarazeni,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));

        $data = $request->validate([
            'vypis' => ['required', 'file', 'max:20480', 'mimes:csv,txt,xls,xlsx'],
            'ucet' => ['nullable', 'uuid'],
        ], [
            'vypis.required' => 'Vyberte soubor s výpisem.',
            'vypis.mimes' => 'Výpis nahrajte jako CSV, XLS nebo XLSX.',
            'vypis.max' => 'Výpis je větší než 20 MB — stáhněte z banky kratší období.',
        ]);

        $ucty = Wallet::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->where('is_active', true);

        $ucet = ! empty($data['ucet'])
            ? (clone $ucty)->where('uuid', $data['ucet'])->first()
            : (clone $ucty)->orderBy('sort_order')->orderBy('id')->first();

        if ($ucet === null) {
            return response()->json([
                'ok' => false,
                'zprava' => empty($data['ucet'])
                    ? 'Nejdřív založte účet — bez něj není kam platby z výpisu zapsat.'
                    : 'Takový účet tu není.',
            ], 422);
        }

        // Přečte, odduplikuje a uloží do bankovního modulu; chybu ve výpisu
        // vrací jako 422 se srozumitelnou větou.
        $idPohybu = null;
        $vysledek = $this->vypisy->import($prostor, $request->user(), $request->file('vypis'), $idPohybu);

        $import = BankImport::where('gallery_space_id', $prostor->id)
            ->where('uuid', $vysledek['import']['uuid'] ?? '')
            ->firstOrFail();

        $pohyby = $this->pohyby($import, $idPohybu);

        $mena = strtoupper((string) ($ucet->currency ?: 'CZK'));
        // Čekající řádky výpis vůbec neuložil (`rows_pending`); čekající pohyb
        // známý odjinud (synchronizace s bankou) se přičte ve smyčce.
        $pocty = ['zapsano' => 0, 'uz' => 0, 'mena' => 0, 'zarazeno' => 0,
            'ceka' => (int) ($vysledek['import']['rows_pending'] ?? 0), 'zruseno' => 0];
        $jineMeny = [];
        $podleDriv = $this->zarazeni->mapa($prostor);

        DB::transaction(function () use ($pohyby, $prostor, $ucet, $mena, $request, $podleDriv, &$pocty, &$jineMeny) {
            foreach ($pohyby as $pohyb) {
                // Do knihy jen zaúčtované. Zamítnutá nebo vrácená platba peníze
                // neodnesla; čekající se zaúčtuje jako jiný bankovní pohyb (jiné
                // id → jiný klíč platby níž), takže by se výdaj zapsal dvakrát.
                if ($pohyb->status !== 'booked') {
                    $pohyb->status === 'pending' ? $pocty['ceka']++ : $pocty['zruseno']++;

                    continue;
                }

                $castka = round(abs((float) $pohyb->amount), 2);

                if ($castka <= 0) {
                    continue;
                }

                // Kniha počítá v měně účtu; eurová platba zapsaná jako koruny
                // by rozpočet i zůstatek rozbila.
                if (strtoupper((string) $pohyb->currency) !== $mena) {
                    $pocty['mena']++;
                    $jineMeny[strtoupper((string) $pohyb->currency)] = true;

                    continue;
                }

                $klic = Uuid::uuid5(self::PROSTOR_KLICU, 'banka:'.$pohyb->id)->toString();

                // I smazaná platba se počítá jako „už v knize". Smazání je
                // rozhodnutí dvojice (třeba „tenhle převod sem nepatří") a další
                // výpis se stejným řádkem ho nemá potichu vrátit. Klíč je navíc
                // na bankovní pohyb, ne na účet — platba zapsaná na jiný účet
                // téže měny se tak nezapíše podruhé; přesunout jde v Transakcích.
                $uz = Transaction::withTrashed()->withoutGlobalScope(SpaceContext::SCOPE)
                    ->where('gallery_space_id', $prostor->id)
                    ->where('client_key', $klic)
                    ->exists();

                if ($uz) {
                    $pocty['uz']++;

                    continue;
                }

                $prijem = (float) $pohyb->amount > 0;
                $poplatek = round(abs((float) $pohyb->fee_amount), 2);
                $popis = mb_substr((string) ($pohyb->description ?: 'Platba z výpisu'), 0, 200);

                // Stejný obchodník jako u dřív zařazené platby → stejná kategorie.
                // Převod mezi vlastními účty se nezařazuje, do rozpočtu nepatří.
                $kategorie = $pohyb->is_internal_transfer ? null : ($podleDriv[$this->zarazeni->klic($popis)] ?? null);

                if ($kategorie !== null) {
                    $pocty['zarazeno']++;
                }

                Transaction::create([
                    'gallery_space_id' => $prostor->id,
                    'client_key' => $klic,
                    'type' => $prijem ? 'income' : 'expense',
                    // Datum z výpisu je den na hodinách banky — bez převodu pásma.
                    'occurred_at' => CarbonImmutable::parse($pohyb->booked_at)->toDateString(),
                    'wallet_from_id' => $prijem ? null : $ucet->id,
                    'wallet_to_id' => $prijem ? $ucet->id : null,
                    'amount_from' => $prijem ? null : $castka,
                    'currency_from' => $prijem ? null : $mena,
                    'amount_to' => $prijem ? $castka : null,
                    'currency_to' => $prijem ? $mena : null,
                    'fee_amount' => $poplatek,
                    'fee_currency' => $poplatek > 0 ? $mena : null,
                    'fee_included' => false,
                    'description' => $popis,
                    'category_id' => $kategorie,
                    // Podle `provider` je platba v záložce Importované.
                    'provider' => 'Výpis z banky',
                    // Převod mezi vlastními účty není útrata ani příjem.
                    'excluded_from_budget' => (bool) $pohyb->is_internal_transfer,
                    'exclusion_reason' => $pohyb->is_internal_transfer ? 'Převod mezi vlastními účty (z výpisu)' : null,
                    'state' => 'approved',
                    'created_by' => $request->user()->id,
                ]);

                $pocty['zapsano']++;
            }
        });

        AuditLog::record('finance.statement.ledger', null, [
            'space_id' => $prostor->id, 'import_uuid' => $import->uuid, 'wallet' => $ucet->uuid,
            'written' => $pocty['zapsano'], 'already' => $pocty['uz'], 'other_currency' => $pocty['mena'],
            'categorized' => $pocty['zarazeno'], 'pending' => $pocty['ceka'], 'cancelled' => $pocty['zruseno'],
        ]);

        return response()->json([
            'ok' => true,
            'zprava' => $this->zprava($pocty, $ucet->name, array_keys($jineMeny), (int) ($vysledek['import']['rows_failed'] ?? 0)),
            'zapsano' => $pocty['zapsano'],
            'zarazeno' => $pocty['zarazeno'],
            // Kolik z nových plateb zbývá zařadit — obrazovka podle toho otevře Nezařazené.
            'nezarazeno' => $pocty['zapsano'] - $pocty['zarazeno'],
            'uz' => $pocty['uz'],
            'jinaMena' => $pocty['mena'],
        ] + $this->obsahPoAkci($this->obsah, $prostor), $pocty['zapsano'] ? 201 : 200);
    }

    /**
     * Bankovní pohyby, které do knihy posoudit: každý řádek výpisu.
     *
     * Dřív jen pohyby, které tenhle import v bankovním modulu nově založil.
     * Řádek, který modul už znal (duplikát), zůstal připsaný k dřívějšímu
     * importu — a když ho kniha tehdy vynechala (jiná měna účtu), do knihy
     * se už nedostal nikdy. Kniha má vlastní odduplikování podle klíče
     * z pohybu, takže posoudit znovu i známé řádky nic nezdvojí.
     *
     * Soubor, který už byl celý zpracovaný, služba nečte znovu (`$id` je
     * `null`) — pak zbývají pohyby, které založil ten dřívější import.
     *
     * @param  list<int>|null  $id
     * @return Collection<int, BankTransaction>
     */
    private function pohyby(BankImport $import, ?array $id): Collection
    {
        if ($id === null) {
            return BankTransaction::where('bank_import_id', $import->id)
                ->orderBy('booked_at')->orderBy('id')->get();
        }

        // Po dávkách — dlouhý výpis má tisíce řádků a SQLite i MySQL mají
        // strop počtu parametrů v jednom dotazu.
        return collect($id)->chunk(500)
            ->flatMap(fn ($davka) => BankTransaction::whereIn('id', $davka->all())->get())
            ->sortBy([['booked_at', 'asc'], ['id', 'asc']])
            ->values();
    }

    /**
     * Věta pro toast — co se stalo, ne jen „hotovo".
     *
     * @param  array{zapsano: int, uz: int, mena: int, zarazeno: int, ceka: int, zruseno: int}  $pocty
     * @param  list<string>  $meny
     */
    private function zprava(array $pocty, string $ucet, array $meny, int $chybne): string
    {
        $casti = [];

        $zbyva = $pocty['zapsano'] - $pocty['zarazeno'];

        $casti[] = ! $pocty['zapsano']
            ? 'Nic nového — všechno z výpisu už v knize je'
            : $this->pocet($pocty['zapsano'], 'platba zapsána', 'platby zapsány', 'plateb zapsáno').' na účet '.$ucet
                .($pocty['zarazeno'] ? ', '.$this->pocet($pocty['zarazeno'], 'zařazena', 'zařazeny', 'zařazeno').' podle dřívějších plateb' : '')
                .($zbyva ? ' — '.($pocty['zarazeno'] ? 'zbylé ' : '').'zařaďte v Transakcích' : '');

        if ($pocty['uz'] && $pocty['zapsano']) {
            $casti[] = $this->pocet($pocty['uz'], 'už byla zapsaná', 'už byly zapsané', 'už bylo zapsaných');
        }

        if ($pocty['mena']) {
            $casti[] = $this->pocet($pocty['mena'], 'platba', 'platby', 'plateb').' v '.implode(', ', $meny).' vynechány (účet je v jiné měně)';
        }

        // Čekající a zamítnuté nejsou chyby výpisu — jen se (zatím) nezapisují.
        if ($pocty['ceka']) {
            $casti[] = $this->pocet($pocty['ceka'],
                'platba čeká na zaúčtování — do knihy přijde s dalším výpisem',
                'platby čekají na zaúčtování — do knihy přijdou s dalším výpisem',
                'plateb čeká na zaúčtování — do knihy přijdou s dalším výpisem');
        }

        if ($pocty['zruseno']) {
            $casti[] = $this->pocet($pocty['zruseno'], 'zamítnutá nebo vrácená platba vynechána', 'zamítnuté nebo vrácené platby vynechány', 'zamítnutých nebo vrácených plateb vynecháno');
        }

        if ($chybne) {
            $casti[] = $this->pocet($chybne, 'řádek nešel přečíst', 'řádky nešly přečíst', 'řádků nešlo přečíst');
        }

        return implode(' · ', $casti);
    }

    private function pocet(int $n, string $jeden, string $dva, string $pet): string
    {
        return $n.' '.($n === 1 ? $jeden : ($n >= 2 && $n <= 4 ? $dva : $pet));
    }
}
