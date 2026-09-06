<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Api\Galerie\Concerns\UrcujePar;
use App\Http\Controllers\Api\Galerie\Concerns\VraciObsah;
use App\Http\Controllers\Controller;
use App\Models\GallerySpace;
use App\Services\Obsah\Pribeh;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Objednávka tisku — a její postup.
 *
 * „Kniha odeslána do tisku" hlásilo tlačítko u fotoknihy. Nikam se nic
 * neodeslalo a nikde nevznikl záznam: tabulka `print_orders` se v celé
 * aplikaci **jen četla**, takže objednávka mohla vzniknout leda ručně
 * v databázi a stav zásilky se nikdy nepohnul.
 *
 * Aplikace **nemluví s tiskárnou**. Neumí to a nepředstírá to: zapíše, že si
 * dvojice knihu objednala, a stav posouvá ten, komu přijde e-mail od tiskárny.
 * Je to evidence vlastní objednávky, ne sledování zásilky.
 */
class TiskController extends Controller
{
    use UrcujePar;
    use VraciObsah;

    /** Kroky zásilky. Pořadí je index `step` v databázi. */
    public const KROKY = ['Přijato', 'V tisku', 'Expedováno', 'Doručeno'];

    public function __construct(private readonly Pribeh $obsah) {}

    /** Nová objednávka — vzniká ve stavu „Přijato". */
    public function store(Request $request): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));

        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'kind' => ['nullable', 'string', 'max:40'],
            'price' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        DB::table('print_orders')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $prostor->id,
            'title' => trim($data['title']),
            'kind' => $data['kind'] ?? 'kniha',
            'price' => (int) ($data['price'] ?? 0),
            'currency' => 'CZK',
            'step' => 0,
            /*
             * Termín je **odhad**, a je tak i označený.
             *
             * Deset pracovních dní je běžná lhůta fotoknihy, ale aplikace ji
             * od tiskárny nemá. `due_estimated` je proto zapnuté a obrazovka
             * píše „odhad 20. 9." místo data, které by vypadalo jako slib.
             */
            'due_on' => CarbonImmutable::now()->addWeekdays(10)->toDateString(),
            'due_estimated' => true,
            'note' => $this->text($data['note'] ?? null),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'ok' => true,
            'zprava' => 'Objednávka zapsaná. Aplikace ji u tiskárny nezadává — až přijde potvrzení, posuňte stav.',
        ] + $this->obsahPoAkci($this->obsah, $prostor));
    }

    /** Posun na další krok — dělá ho člověk, ne tiskárna. */
    public function step(Request $request): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));

        $data = $request->validate([
            'id' => ['required', 'string', 'max:64'],
            'step' => ['required', 'integer', 'min:0', 'max:'.(count(self::KROKY) - 1)],
            'tracking' => ['nullable', 'string', 'max:80'],
        ]);

        $objednavka = DB::table('print_orders')
            ->where('gallery_space_id', $prostor->id)
            ->where('uuid', $data['id'])
            ->first();

        if ($objednavka === null) {
            throw ValidationException::withMessages(['id' => 'Takovou objednávku aplikace nezná.']);
        }

        DB::table('print_orders')->where('id', $objednavka->id)->update(array_filter([
            'step' => (int) $data['step'],
            'tracking' => $this->text($data['tracking'] ?? null) ?? $objednavka->tracking,
            // Doručené už termín neodhaduje — je doma.
            'due_estimated' => (int) $data['step'] < count(self::KROKY) - 1,
            'updated_at' => now(),
        ], fn ($v) => $v !== null));

        return response()->json([
            'ok' => true,
            'zprava' => 'Stav objednávky: '.self::KROKY[(int) $data['step']],
        ] + $this->obsahPoAkci($this->obsah, $prostor));
    }

    private function text(?string $hodnota): ?string
    {
        $text = trim((string) $hodnota);

        return $text === '' ? null : $text;
    }
}
