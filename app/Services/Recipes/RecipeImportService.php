<?php

namespace App\Services\Recipes;

use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Deterministic importer for public Recipe JSON-LD. It returns a draft only,
 * so imported values are always reviewed in the recipe editor before saving.
 */
class RecipeImportService
{
    public function import(string $url): array
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (($parts['scheme'] ?? '') !== 'https' || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw ValidationException::withMessages(['url' => 'Použijte prosím veřejný odkaz začínající https://.']);
        }

        $this->assertPublicHost($host);

        try {
            $response = Http::timeout(8)->connectTimeout(4)->withoutRedirecting()
                ->withHeaders(['Accept' => 'text/html,application/xhtml+xml', 'User-Agent' => 'MakiGallery Recipe Importer/1.0'])
                ->get($url);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['url' => 'Zdrojový recept se nepodařilo bezpečně načíst. Zkuste odkaz otevřít v prohlížeči a vložit jej znovu.']);
        }

        if (! $response->successful()) {
            throw ValidationException::withMessages(['url' => "Zdrojový web vrátil chybu {$response->status()}. Přesměrované nebo nepřístupné odkazy nelze importovat."]);
        }

        $html = $response->body();
        if (strlen($html) > 1_500_000) {
            throw ValidationException::withMessages(['url' => 'Stránka s receptem je příliš velká pro bezpečný import.']);
        }

        $recipe = $this->firstRecipe($html);
        if (! $recipe) {
            throw ValidationException::withMessages(['url' => 'Na stránce se nenašla čitelná data receptu. Zkuste jiný odkaz nebo vytvořte recept ručně.']);
        }

        $ingredients = collect($recipe['recipeIngredient'] ?? [])->filter('is_string')->map(fn (string $item) => [
            'section' => '', 'name' => $this->text($item, 180), 'quantity' => null, 'unit' => '', 'quantity_note' => '',
            'is_scalable' => true, 'is_optional' => false, 'is_pantry' => false, 'preparation' => '', 'substitutes' => '',
        ])->filter(fn (array $item) => $item['name'] !== '')->take(200)->values()->all();
        $steps = collect($this->instructions($recipe['recipeInstructions'] ?? []))->map(fn (string $item, int $index) => [
            'title' => 'Krok '.($index + 1), 'instruction' => $this->text($item, 20_000), 'timer_seconds' => null,
            'temperature' => null, 'temperature_unit' => 'C', 'equipment' => '', 'tip' => '',
        ])->filter(fn (array $item) => $item['instruction'] !== '')->take(100)->values()->all();

        if ($ingredients === [] || $steps === []) {
            throw ValidationException::withMessages(['url' => 'Zdroj neobsahuje dostatek surovin nebo postupu pro bezpečné vytvoření konceptu.']);
        }

        $duration = $this->duration((string) ($recipe['totalTime'] ?? ''));
        $prep = $this->duration((string) ($recipe['prepTime'] ?? ''));
        $cook = $this->duration((string) ($recipe['cookTime'] ?? ''));
        $total = $duration ?? (($prep ?? 0) + ($cook ?? 0));
        $description = $this->text((string) ($recipe['description'] ?? ''), 20_000);
        $title = $this->text((string) ($recipe['name'] ?? ''), 180);
        if ($title === '') {
            throw ValidationException::withMessages(['url' => 'Zdrojový recept nemá čitelný název.']);
        }

        return [
            'title' => $title, 'summary' => $this->text($description, 2_000), 'description' => $description,
            'category' => $this->category((string) ($recipe['recipeCategory'] ?? '')), 'cuisine' => $this->text((string) ($recipe['recipeCuisine'] ?? ''), 80),
            'difficulty' => 'medium', 'status' => 'draft', 'base_servings' => $this->servings($recipe['recipeYield'] ?? null),
            'prep_minutes' => $prep ?? 0, 'cook_minutes' => $cook ?? max(0, $total - ($prep ?? 0)), 'rest_minutes' => 0,
            'currency' => 'CZK', 'dietary_tags' => [], 'occasion_tags' => [], 'equipment' => [],
            'source_name' => preg_replace('/^www\./', '', $host), 'source_url' => $url, 'tips' => '', 'storage_notes' => '', 'reheating_notes' => '',
            'is_favorite' => false, 'ingredients' => $ingredients, 'steps' => $steps,
            'import_notice' => 'Recept byl načten jako koncept. Zkontrolujte zejména množství surovin, porce a časy před uložením.',
        ];
    }

    private function assertPublicHost(string $host): void
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (! $this->isPublicIp($host)) throw ValidationException::withMessages(['url' => 'Odkazy do lokální nebo neveřejné sítě nelze importovat.']);
            return;
        }
        $records = dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        $ips = collect($records)->map(fn (array $record) => $record['ip'] ?? $record['ipv6'] ?? null)->filter()->all();
        if ($ips === [] || collect($ips)->contains(fn (string $ip) => ! $this->isPublicIp($ip))) {
            throw ValidationException::withMessages(['url' => 'Odkaz nevede na ověřitelný veřejný web.']);
        }
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private function firstRecipe(string $html): ?array
    {
        preg_match_all('/<script\b[^>]*type\s*=\s*(["\'])application\/ld\+json\1[^>]*>(.*?)<\/script>/is', $html, $matches);
        foreach ($matches[2] ?? [] as $json) {
            $decoded = json_decode(trim(preg_replace('/^<!--|-->$/', '', $json)), true);
            foreach ($this->recipesIn($decoded) as $recipe) return $recipe;
        }
        return null;
    }

    private function recipesIn(mixed $value): array
    {
        if (! is_array($value)) return [];
        if (array_is_list($value)) return array_merge(...array_map(fn ($item) => $this->recipesIn($item), $value));
        $recipes = $this->hasRecipeType($value['@type'] ?? null) ? [$value] : [];
        foreach ($value as $key => $item) if ($key !== '@type') $recipes = array_merge($recipes, $this->recipesIn($item));
        return $recipes;
    }

    private function hasRecipeType(mixed $type): bool
    {
        return collect(is_array($type) ? $type : [$type])->contains(fn ($item) => is_string($item) && strtolower($item) === 'recipe');
    }

    private function instructions(mixed $value): array
    {
        if (is_string($value)) return [$value];
        if (! is_array($value)) return [];
        if (array_is_list($value)) return array_merge(...array_map(fn ($item) => $this->instructions($item), $value));
        return $this->instructions($value['text'] ?? $value['name'] ?? $value['itemListElement'] ?? []);
    }

    private function duration(string $value): ?int
    {
        if (! preg_match('/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?)?$/i', $value, $matches)) return null;
        return ((int) ($matches[1] ?? 0) * 1440) + ((int) ($matches[2] ?? 0) * 60) + (int) ($matches[3] ?? 0);
    }

    private function servings(mixed $value): float
    {
        if (preg_match('/\d+(?:[.,]\d+)?/', is_array($value) ? implode(' ', $value) : (string) $value, $matches)) return max(0.25, min(1000, (float) str_replace(',', '.', $matches[0])));
        return 2;
    }

    private function category(string $value): string
    {
        $value = strtolower($value);
        return match (true) {
            str_contains($value, 'soup') || str_contains($value, 'pol') => 'soup', str_contains($value, 'dessert') || str_contains($value, 'cake') || str_contains($value, 'mou') => 'dessert',
            str_contains($value, 'breakfast') || str_contains($value, 'sníd') => 'breakfast', str_contains($value, 'drink') || str_contains($value, 'nápoj') => 'drink',
            str_contains($value, 'salad') || str_contains($value, 'salát') => 'salad', str_contains($value, 'sauce') || str_contains($value, 'omá') => 'sauce', default => 'main_course',
        };
    }

    private function text(string $value, int $limit): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: ''), 0, $limit);
    }
}