<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Api\Galerie\Concerns\UrcujePar;
use App\Http\Controllers\Api\Galerie\Concerns\VraciObsah;
use App\Http\Controllers\Controller;
use App\Models\GallerySpace;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\Obsah\Finance;
use App\Support\SpaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ruční platba z rychlého zápisu.
 *
 * „Zapsat platbu" v telefonu i „+ Transakce" v počítači přidaly řádek jen do
 * stavu obrazovky — s datem „30. 8." napevno a s hláškou „zapsáno". Rozpočet,
 * vyrovnání i přehled se ale počítají z knihy, takže platba nikde nebyla
 * a po zavření okna zmizela i z obrazovky.
 *
 * Zapisuje se do hlavního aktivního účtu dvojice, s dnešním datem a bez
 * kategorie — zařadit ji jde hned potom v Transakcích. Plný formulář (jiný
 * účet, rozdělení, cesta) má modul Rozpočet.
 */
class RucniPlatbaController extends Controller
{
    use UrcujePar;
    use VraciObsah;

    public function __construct(private readonly Finance $obsah) {}

    public function __invoke(Request $request): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));

        $data = $request->validate([
            'popis' => ['required', 'string', 'max:200'],
            'castka' => ['required', 'numeric', 'gt:0', 'max:100000000'],
            'prijem' => ['sometimes', 'boolean'],
            // Zápis z fronty offline se může odeslat dvakrát — stejný klíč, jedna platba.
            'klic' => ['nullable', 'string', 'max:64'],
        ]);

        if (! empty($data['klic'])) {
            $uz = Transaction::withoutGlobalScope(SpaceContext::SCOPE)
                ->where('gallery_space_id', $prostor->id)
                ->where('client_key', $data['klic'])
                ->first();

            if ($uz) {
                return $this->odpoved($prostor, 'Platba už je zapsaná', $uz);
            }
        }

        $ucet = Wallet::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        if ($ucet === null) {
            return response()->json([
                'ok' => false,
                'zprava' => 'Nejdřív založte účet v Rozpočtu — bez něj není kam platbu zapsat.',
            ], 422);
        }

        $prijem = (bool) ($data['prijem'] ?? false);
        $castka = round((float) $data['castka'], 2);

        $platba = Transaction::create([
            'gallery_space_id' => $prostor->id,
            'type' => $prijem ? 'income' : 'expense',
            'occurred_at' => CarbonImmutable::now()->toDateString(),
            'wallet_from_id' => $prijem ? null : $ucet->id,
            'wallet_to_id' => $prijem ? $ucet->id : null,
            'amount_from' => $prijem ? null : $castka,
            'currency_from' => $prijem ? null : $ucet->currency,
            'amount_to' => $prijem ? $castka : null,
            'currency_to' => $prijem ? $ucet->currency : null,
            'description' => trim($data['popis']),
            'client_key' => $data['klic'] ?? null,
            'state' => 'approved',
            'created_by' => $request->user()->id,
        ]);

        return $this->odpoved($prostor, ($prijem ? 'Příjem' : 'Platba').' zapsána na účet '.$ucet->name.' — zařaďte ji do kategorie', $platba, 201);
    }

    private function odpoved(GallerySpace $prostor, string $zprava, Transaction $platba, int $kod = 200): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'zprava' => $zprava,
            'platba' => $platba->uuid,
        ] + $this->obsahPoAkci($this->obsah, $prostor), $kod);
    }
}
