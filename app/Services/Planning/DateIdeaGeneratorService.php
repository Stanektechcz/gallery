<?php

namespace App\Services\Planning;

use App\Models\CalendarEvent;
use App\Models\CoupleDateIdea;
use App\Models\GallerySpace;
use App\Models\Place;
use App\Models\User;
use App\Services\Integrations\FreeTravelDataService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class DateIdeaGeneratorService
{
    private const DURATION_MINUTES = [
        'quick' => 90, 'evening' => 180, 'half_day' => 300,
        'full_day' => 540, 'weekend' => 1800,
    ];

    private const SCOPE_RADII_KM = [
        'home' => 0, 'nearby' => 5, 'city' => 20, 'day_trip' => 120, 'weekend' => 500,
    ];

    public function __construct(private readonly FreeTravelDataService $travelData) {}

    /** @return Collection<int, CoupleDateIdea> */
    public function generate(GallerySpace $space, User $creator, array $parameters, int $count = 4): Collection
    {
        $parameters = $this->normalise($parameters);
        $forecast = $this->forecast($parameters);
        $places = $this->eligiblePlaces($space, $parameters);
        $preference = $this->preferenceProfile($space);
        $ideas = collect();

        for ($attempt = 0; $attempt < 90 && $ideas->count() < $count; $attempt++) {
            $idea = $this->compose($space, $creator, $parameters, $places, $preference, $forecast, $attempt);
            if (! $idea) continue;

            try {
                $ideas->push(CoupleDateIdea::create($idea));
            } catch (QueryException $exception) {
                if (! in_array((string) $exception->getCode(), ['23000', '23505'], true)) throw $exception;
            }
        }

        return $ideas;
    }

    private function normalise(array $parameters): array
    {
        $destination = collect($parameters['destination'] ?? [])->only([
            'location_name', 'latitude', 'longitude', 'location_country', 'location_country_code',
        ])->all();

        return [
            'destination' => $destination,
            'theme' => $parameters['theme'] ?? 'surprise',
            'budget_max' => isset($parameters['budget_max']) ? (float) $parameters['budget_max'] : 1200.0,
            'currency' => strtoupper($parameters['currency'] ?? 'CZK'),
            'travel_scope' => $parameters['travel_scope'] ?? 'city',
            'transport_mode' => $parameters['transport_mode'] ?? 'transit',
            'duration' => $parameters['duration'] ?? 'evening',
            'time_of_day' => $parameters['time_of_day'] ?? 'evening',
            'preferred_date' => $parameters['preferred_date'] ?? null,
            'setting' => $parameters['setting'] ?? 'any',
            'energy' => $parameters['energy'] ?? 'medium',
            'food' => $parameters['food'] ?? 'any',
            'surprise_level' => (int) ($parameters['surprise_level'] ?? 2),
            'accessible_only' => (bool) ($parameters['accessible_only'] ?? false),
            'weather_aware' => (bool) ($parameters['weather_aware'] ?? true),
            'new_places_only' => (bool) ($parameters['new_places_only'] ?? false),
        ];
    }

    private function compose(
        GallerySpace $space,
        User $creator,
        array $parameters,
        Collection $places,
        array $preference,
        ?array $forecast,
        int $attempt,
    ): ?array {
        $theme = $parameters['theme'] === 'surprise'
            ? collect(['romantic', 'food', 'nature', 'culture', 'creative', 'adventure', 'relax', 'low_cost'])->random()
            : $parameters['theme'];

        $rainExpected = (bool) ($forecast['rain_expected'] ?? false);
        $setting = $rainExpected && $parameters['setting'] === 'any' ? 'indoor' : $parameters['setting'];
        $catalog = collect($this->catalog())
            ->filter(fn (array $item) => $this->matches($item, $theme, $setting, $parameters['energy'], $parameters['food'], $parameters['travel_scope']));

        $openers = $catalog->where('stage', 'start')->values();
        $cores = $catalog->where('stage', 'main')->values();
        $closers = $catalog->where('stage', 'finish')->values();
        if ($openers->isEmpty() || $cores->isEmpty() || $closers->isEmpty()) return null;

        $seed = random_int(0, PHP_INT_MAX) + $attempt;
        $opener = $openers[$seed % $openers->count()];
        $core = $cores[intdiv($seed, 7) % $cores->count()];
        $closer = $closers[intdiv($seed, 13) % $closers->count()];
        $twists = collect($this->twists())->filter(fn (array $twist) => in_array($theme, $twist['themes'], true) || in_array('any', $twist['themes'], true))->values();
        $twist = $twists[intdiv($seed, 17) % $twists->count()];

        // Every third candidate remains independent of saved places. This keeps
        // the generator useful even when all curated venues exceed the budget.
        $anchor = $places->isEmpty() || $attempt % 3 === 0 ? null : $places[$seed % $places->count()];
        $blocks = collect([$opener, $core, $closer])->map(fn (array $block) => $this->block($block))->values();
        if ($anchor) $blocks->splice(1, 0, [$this->placeBlock($anchor)]);
        $foodBlock = $this->requestedFoodBlock($parameters['food'], $parameters['travel_scope'], $setting);
        if ($foodBlock && ! $blocks->contains('key', $foodBlock['key'])) $blocks->splice(-1, 0, [$foodBlock]);
        $blocks->push($this->block($twist));

        $transportCost = $this->transportCost($parameters['travel_scope'], $parameters['transport_mode']);
        $estimatedCost = round($blocks->sum('estimated_cost') + $transportCost, 2);
        if ($estimatedCost > $parameters['budget_max']) return null;

        $baseMinutes = self::DURATION_MINUTES[$parameters['duration']];
        $contentMinutes = (int) $blocks->sum('minutes');
        $estimatedMinutes = max(60, min($baseMinutes, $contentMinutes + $this->travelMinutes($parameters['travel_scope'])));
        if ($parameters['duration'] === 'weekend') $estimatedMinutes = max(1440, $baseMinutes);
        $start = $this->suggestStart($space, $parameters, $estimatedMinutes);
        $destination = $parameters['destination'];
        $destinationName = $destination['location_name'] ?? ($anchor?->city ?: $anchor?->name ?: 'podle vaší polohy');
        $key = hash('sha256', json_encode([
            Str::lower(Str::ascii($destinationName)), $theme, $blocks->pluck('key')->all(),
            $anchor?->id, $parameters['travel_scope'], $parameters['transport_mode'],
        ], JSON_UNESCAPED_UNICODE));

        $lovedTheme = in_array($theme, $preference['loved_themes'], true);
        $reasons = collect([
            'neopakovaná kombinace ve vaší společné historii',
            $estimatedCost === 0 ? 'program bez placeného vstupu' : "odhad pro dva do {$parameters['budget_max']} {$parameters['currency']}",
            $start ? 'termín bez kolize ve společném kalendáři' : null,
            $anchor ? "využívá vaše uložené místo {$anchor->name}" : null,
            $lovedTheme ? 'navazuje na typ randíček, který jste označili srdcem' : null,
            $rainExpected ? 'program je upraven podle předpovědi deště' : null,
        ])->filter()->values();

        $title = $this->title($theme, $destinationName, $core['title'], $parameters['surprise_level']);
        $rainBackup = $setting === 'indoor'
            ? 'Program už je sestavený převážně uvnitř.'
            : 'Při dešti přesuňte hlavní část do kavárny, galerie nebo společného vaření a zachovejte závěrečný rituál.';
        $tripRecommended = in_array($parameters['travel_scope'], ['day_trip', 'weekend'], true)
            || in_array($parameters['duration'], ['full_day', 'weekend'], true);

        return [
            'gallery_space_id' => $space->id,
            'created_by' => $creator->id,
            'generation_key' => $key,
            'title' => $title,
            'summary' => $this->summary($theme, $destinationName, $parameters, $blocks),
            'theme' => $theme,
            'status' => 'generated',
            'travel_scope' => $parameters['travel_scope'],
            'transport_mode' => $parameters['transport_mode'],
            'estimated_cost' => $estimatedCost,
            'currency' => $parameters['currency'],
            'estimated_minutes' => $estimatedMinutes,
            'novelty_percent' => $anchor ? ($parameters['new_places_only'] ? 92 : 82) : 97,
            'suggested_starts_at' => $start,
            'destination' => $destination,
            'parameters' => $parameters,
            'plan' => [
                'blocks' => $blocks->all(),
                'reasons' => $reasons->all(),
                'budget' => [
                    'activities' => round($blocks->sum('estimated_cost'), 2),
                    'transport' => $transportCost,
                    'total' => $estimatedCost,
                    'limit' => $parameters['budget_max'],
                    'currency' => $parameters['currency'],
                    'is_estimate' => true,
                ],
                'weather' => $forecast,
                'rain_backup' => $rainBackup,
                'preparation_tasks' => $this->preparationTasks($parameters, $tripRecommended),
                'memory_prompt' => $this->memoryPrompt($theme),
                'is_trip_recommended' => $tripRecommended,
                'route' => [
                    'scope' => $parameters['travel_scope'],
                    'mode' => $parameters['transport_mode'],
                    'radius_km' => self::SCOPE_RADII_KM[$parameters['travel_scope']],
                    'estimated_travel_minutes' => $this->travelMinutes($parameters['travel_scope']),
                ],
            ],
        ];
    }

    private function catalog(): array
    {
        return [
            ['key'=>'question_walk','stage'=>'start','title'=>'Procházka se třemi otázkami','description'=>'Každý připraví jednu lehkou, jednu snovou a jednu upřímnou otázku. Telefony zůstanou v kapse.','icon'=>'💬','minutes'=>35,'cost'=>0,'themes'=>['romantic','nature','relax','low_cost'],'settings'=>['outdoor','any'],'energy'=>['low','medium'],'food'=>false,'scopes'=>['nearby','city','day_trip','weekend']],
            ['key'=>'photo_mission','stage'=>'start','title'=>'Fotografická mise ve dvou','description'=>'Vyfoťte pět detailů na společné téma; snímky se po akci mohou připojit ke vzpomínce.','icon'=>'📷','minutes'=>40,'cost'=>0,'themes'=>['creative','culture','nature','adventure','low_cost'],'settings'=>['outdoor','indoor','any'],'energy'=>['low','medium','high'],'food'=>false,'scopes'=>['nearby','city','day_trip','weekend']],
            ['key'=>'mystery_envelope','stage'=>'start','title'=>'Tajemná obálka','description'=>'Jeden vybere první zastávku a druhý se ji dozví až po cestě.','icon'=>'💌','minutes'=>25,'cost'=>0,'themes'=>['romantic','adventure','surprise'],'settings'=>['any','outdoor','indoor'],'energy'=>['low','medium','high'],'food'=>false,'scopes'=>['home','nearby','city','day_trip','weekend']],
            ['key'=>'sunrise_start','stage'=>'start','title'=>'Východ slunce a teplý nápoj','description'=>'Začněte den na klidném vyhlídkovém místě a každý řekněte jedno přání pro další měsíc.','icon'=>'🌅','minutes'=>60,'cost'=>100,'themes'=>['romantic','nature','adventure'],'settings'=>['outdoor','any'],'energy'=>['medium','high'],'food'=>true,'scopes'=>['nearby','city','day_trip','weekend']],
            ['key'=>'home_tasting','stage'=>'start','title'=>'Slepá domácí ochutnávka','description'=>'Každý vybere dvě drobnosti, druhý se zavřenýma očima hádá chuť a původ.','icon'=>'🫖','minutes'=>35,'cost'=>180,'themes'=>['food','creative','relax','low_cost'],'settings'=>['indoor','any'],'energy'=>['low','medium'],'food'=>true,'scopes'=>['home','nearby','city']],
            ['key'=>'architecture_hunt','stage'=>'main','title'=>'Lov nečekaných detailů','description'=>'Najděte deset barev, symbolů nebo architektonických detailů a na konci zvolte společného vítěze.','icon'=>'🔎','minutes'=>90,'cost'=>0,'themes'=>['culture','creative','adventure','low_cost'],'settings'=>['outdoor','indoor','any'],'energy'=>['medium','high'],'food'=>false,'scopes'=>['nearby','city','day_trip','weekend']],
            ['key'=>'picnic_story','stage'=>'main','title'=>'Piknik s příběhem místa','description'=>'Připravte jednoduché občerstvení a zjistěte jednu zajímavost o místě, kterou druhý nezná.','icon'=>'🧺','minutes'=>120,'cost'=>350,'themes'=>['romantic','food','nature','relax'],'settings'=>['outdoor','any'],'energy'=>['low','medium'],'food'=>true,'scopes'=>['nearby','city','day_trip','weekend']],
            ['key'=>'culture_choice','stage'=>'main','title'=>'Kulturní volba naslepo','description'=>'Vyberte malou galerii, muzeum nebo výstavu a každý označte dílo, které nejlépe vystihuje toho druhého.','icon'=>'🖼️','minutes'=>120,'cost'=>500,'themes'=>['culture','creative','romantic'],'settings'=>['indoor','any'],'energy'=>['low','medium'],'food'=>false,'scopes'=>['city','day_trip','weekend']],
            ['key'=>'taste_route','stage'=>'main','title'=>'Ochutnávková trasa 3× napůl','description'=>'Na třech zastávkách si vždy objednejte jednu věc napůl; body dejte chuti, obsluze i atmosféře.','icon'=>'🍜','minutes'=>150,'cost'=>900,'themes'=>['food','adventure'],'settings'=>['indoor','any'],'energy'=>['medium'],'food'=>true,'scopes'=>['city','day_trip','weekend']],
            ['key'=>'creative_duel','stage'=>'main','title'=>'Kreativní duel','description'=>'Za omezený čas vytvořte každý drobný dárek nebo obrázek pro druhého z dostupných materiálů.','icon'=>'🎨','minutes'=>100,'cost'=>300,'themes'=>['creative','romantic','low_cost'],'settings'=>['indoor','any'],'energy'=>['low','medium'],'food'=>false,'scopes'=>['home','nearby','city','day_trip']],
            ['key'=>'geocache_duo','stage'=>'main','title'=>'Společný geocaching','description'=>'Najděte dvě schránky nebo vymyslete vlastní trasu podle souřadnic a indicií.','icon'=>'🧭','minutes'=>150,'cost'=>0,'themes'=>['adventure','nature','low_cost'],'settings'=>['outdoor','any'],'energy'=>['medium','high'],'food'=>false,'scopes'=>['nearby','city','day_trip','weekend']],
            ['key'=>'no_phone_nature','stage'=>'main','title'=>'Hodina bez signálu','description'=>'Vyberte klidnou trasu, vypněte oznámení a střídejte deset minut ticha s deseti minutami rozhovoru.','icon'=>'🌿','minutes'=>120,'cost'=>0,'themes'=>['nature','relax','romantic','low_cost'],'settings'=>['outdoor','any'],'energy'=>['low','medium'],'food'=>false,'scopes'=>['nearby','city','day_trip','weekend']],
            ['key'=>'home_recipe','stage'=>'main','title'=>'Recept, který jste ještě nevařili','description'=>'Vyberte recept ze společné kuchařky, rozdělte role a výsledek uložte jako nové společné vaření.','icon'=>'👩‍🍳','minutes'=>140,'cost'=>450,'themes'=>['food','creative','relax'],'settings'=>['indoor','any'],'energy'=>['low','medium'],'food'=>true,'scopes'=>['home','nearby','city']],
            ['key'=>'wellness_home','stage'=>'main','title'=>'Domácí wellness bez obrazovek','description'=>'Hudba, teplá koupel nohou, masáž rukou a připravený oblíbený nápoj.','icon'=>'🕯️','minutes'=>110,'cost'=>250,'themes'=>['relax','romantic','low_cost'],'settings'=>['indoor','any'],'energy'=>['low'],'food'=>false,'scopes'=>['home','nearby']],
            ['key'=>'train_random_stop','stage'=>'main','title'=>'Vlakem o jednu zastávku dál','description'=>'Vyberte dostupnou relaci, vystupte na místě, kde jste spolu nebyli, a projděte okruh bez pevného scénáře.','icon'=>'🚆','minutes'=>240,'cost'=>600,'themes'=>['adventure','nature','culture'],'settings'=>['outdoor','any'],'energy'=>['medium','high'],'food'=>false,'scopes'=>['day_trip','weekend']],
            ['key'=>'dessert_rating','stage'=>'finish','title'=>'Dezert a společné hodnocení','description'=>'Objednejte jednu sladkou tečku napůl a uložte si hodnocení podniku i poznámku pro příště.','icon'=>'🍰','minutes'=>45,'cost'=>300,'themes'=>['food','romantic','relax'],'settings'=>['indoor','any'],'energy'=>['low','medium'],'food'=>true,'scopes'=>['nearby','city','day_trip','weekend']],
            ['key'=>'sunset_choice','stage'=>'finish','title'=>'Západ slunce a jedna volba','description'=>'Každý řekne nejlepší moment dne a navrhne jednu věc pro příští společný plán.','icon'=>'🌇','minutes'=>45,'cost'=>0,'themes'=>['romantic','nature','relax','low_cost'],'settings'=>['outdoor','any'],'energy'=>['low','medium'],'food'=>false,'scopes'=>['nearby','city','day_trip','weekend']],
            ['key'=>'time_capsule','stage'=>'finish','title'=>'Vzkaz do časové kapsle','description'=>'Napište krátký společný vzkaz, který vám aplikace připomene za půl roku.','icon'=>'⏳','minutes'=>25,'cost'=>0,'themes'=>['romantic','creative','relax','low_cost'],'settings'=>['indoor','outdoor','any'],'energy'=>['low','medium'],'food'=>false,'scopes'=>['home','nearby','city','day_trip','weekend']],
            ['key'=>'memory_pick','stage'=>'finish','title'=>'Jedna fotka, jedna vzpomínka','description'=>'Vyberte fotku dne, doplňte společnou větu a připojte ji k události v galerii.','icon'=>'💞','minutes'=>25,'cost'=>0,'themes'=>['romantic','creative','culture','nature','adventure','relax','low_cost','food'],'settings'=>['indoor','outdoor','any'],'energy'=>['low','medium','high'],'food'=>false,'scopes'=>['home','nearby','city','day_trip','weekend']],
            ['key'=>'playlist_close','stage'=>'finish','title'=>'Píseň dne','description'=>'Každý vybere jednu skladbu; vznikne malý soundtrack k dnešní vzpomínce.','icon'=>'🎵','minutes'=>20,'cost'=>0,'themes'=>['romantic','creative','relax','low_cost'],'settings'=>['indoor','outdoor','any'],'energy'=>['low','medium','high'],'food'=>false,'scopes'=>['home','nearby','city','day_trip','weekend']],
        ];
    }

    private function twists(): array
    {
        return [
            ['key'=>'twist_100','stage'=>'twist','title'=>'Limit 100 Kč','description'=>'Každý smí během jedné části utratit nejvýše 100 Kč pro radost toho druhého.','icon'=>'🪙','minutes'=>20,'cost'=>200,'themes'=>['low_cost','food','adventure','any']],
            ['key'=>'twist_color','stage'=>'twist','title'=>'Barva dne','description'=>'Vylosujte barvu a hledejte ji v jídle, detailech i společné fotografii.','icon'=>'🎨','minutes'=>15,'cost'=>0,'themes'=>['creative','culture','nature','any']],
            ['key'=>'twist_firsts','stage'=>'twist','title'=>'Tři poprvé','description'=>'Najděte tři malé věci, které jste spolu ještě neudělali.','icon'=>'✨','minutes'=>20,'cost'=>0,'themes'=>['adventure','romantic','any']],
            ['key'=>'twist_partner','stage'=>'twist','title'=>'Střídání vedení','description'=>'První polovinu vede jeden, druhou druhý; bez opravování detailů.','icon'=>'🤝','minutes'=>10,'cost'=>0,'themes'=>['romantic','relax','any']],
            ['key'=>'twist_rating','stage'=>'twist','title'=>'Soukromá porota','description'=>'Na konci oba tajně ohodnoťte atmosféru, nápad a chuť zopakovat den.','icon'=>'⭐','minutes'=>15,'cost'=>0,'themes'=>['food','culture','creative','any']],
            ['key'=>'twist_postcard','stage'=>'twist','title'=>'Pohlednice budoucímu já','description'=>'Napište dvě věty o tom, co si z dneška chcete pamatovat za rok.','icon'=>'💌','minutes'=>20,'cost'=>40,'themes'=>['romantic','relax','creative','any']],
        ];
    }

    private function matches(array $item, string $theme, string $setting, string $energy, string $food, string $scope): bool
    {
        $themeMatch = in_array($theme, $item['themes'], true) || ($theme === 'food' && $item['food']);
        $settingMatch = $setting === 'any' || in_array($setting, $item['settings'], true) || in_array('any', $item['settings'], true);
        $energyMatch = in_array($energy, $item['energy'], true);
        $foodMatch = $food === 'any' || ($food === 'none' ? ! $item['food'] : true);
        return $themeMatch && $settingMatch && $energyMatch && $foodMatch && in_array($scope, $item['scopes'], true);
    }

    private function block(array $item): array
    {
        return collect($item)->only(['key','stage','title','description','icon','minutes'])->all()
            + ['estimated_cost' => (float) $item['cost']];
    }

    private function placeBlock(Place $place): array
    {
        $cost = [0, 250, 600, 1200, 2200][min(4, max(0, (int) ($place->price_level ?? 0)))];
        return [
            'key' => 'place_'.$place->id, 'stage' => 'place', 'title' => $place->name,
            'description' => $place->next_time_note ?: $place->description ?: 'Vaše uložené místo propojené s plánem a budoucí vzpomínkou.',
            'icon' => '📍', 'minutes' => (int) ($place->estimated_visit_minutes ?: 90), 'estimated_cost' => (float) $cost,
            'place_id' => $place->id, 'latitude' => $place->latitude, 'longitude' => $place->longitude,
        ];
    }

    private function requestedFoodBlock(string $food, string $scope, string $setting): ?array
    {
        if (in_array($food, ['any', 'none'], true)) return null;
        $item = match ($food) {
            'cafe' => collect($this->catalog())->firstWhere('key', $scope === 'home' ? 'home_tasting' : 'dessert_rating'),
            'dinner' => collect($this->catalog())->firstWhere('key', in_array($scope, ['home','nearby'], true) ? 'home_recipe' : 'taste_route'),
            'picnic' => $setting === 'indoor'
                ? ['key'=>'indoor_picnic','stage'=>'food','title'=>'Piknik na dece uvnitř','description'=>'Připravte jednoduché občerstvení, deku a hudbu; počasí program neovlivní.','icon'=>'🧺','minutes'=>75,'cost'=>350]
                : collect($this->catalog())->firstWhere('key', 'picnic_story'),
            default => null,
        };
        return $item ? $this->block($item) : null;
    }

    private function eligiblePlaces(GallerySpace $space, array $parameters): Collection
    {
        $query = Place::query()->where('gallery_space_id', $space->id);
        if ($parameters['accessible_only']) $query->where('is_accessible', true);
        if ($parameters['new_places_only']) {
            $query->whereDoesntHave('reviews', fn ($review) => $review->where('status', 'published'));
        }

        $places = $query->limit(300)->get();
        $lat = $parameters['destination']['latitude'] ?? null;
        $lng = $parameters['destination']['longitude'] ?? null;
        $radius = self::SCOPE_RADII_KM[$parameters['travel_scope']];
        if ($lat !== null && $lng !== null && $radius > 0) {
            $places = $places->filter(fn (Place $place) => ! $place->latitude || ! $place->longitude || $this->distanceKm((float) $lat, (float) $lng, $place->latitude, $place->longitude) <= $radius);
        }

        return $places->sortByDesc(fn (Place $place) => ((float) ($place->personal_rating ?? 0) * 10) + ($place->is_photogenic ? 3 : 0))->values();
    }

    private function preferenceProfile(GallerySpace $space): array
    {
        $loved = CoupleDateIdea::query()->where('couple_date_ideas.gallery_space_id', $space->id)
            ->join('couple_date_idea_reactions as reaction', 'reaction.date_idea_id', '=', 'couple_date_ideas.id')
            ->where('reaction.reaction', 'love')->pluck('couple_date_ideas.theme')->countBy()->sortDesc();
        return ['loved_themes' => $loved->keys()->take(3)->values()->all()];
    }

    private function forecast(array $parameters): ?array
    {
        $date = $parameters['preferred_date'];
        $lat = $parameters['destination']['latitude'] ?? null;
        $lng = $parameters['destination']['longitude'] ?? null;
        if (! $parameters['weather_aware'] || ! $date || $lat === null || $lng === null) return null;
        $day = Carbon::parse($date);
        if ($day->isBefore(now()->startOfDay()) || $day->isAfter(now()->addDays(15)->endOfDay())) return null;

        try {
            $raw = Cache::remember('date-forecast:'.round((float) $lat, 2).':'.round((float) $lng, 2).':'.$day->toDateString(), now()->addHour(),
                fn () => $this->travelData->weather((float) $lat, (float) $lng, $day->toDateString()));
            $daily = $raw['daily'] ?? [];
            $precipitation = (int) ($daily['precipitation_probability_max'][0] ?? 0);
            return [
                'date' => $day->toDateString(),
                'temperature_min' => $daily['temperature_2m_min'][0] ?? null,
                'temperature_max' => $daily['temperature_2m_max'][0] ?? null,
                'precipitation_probability' => $precipitation,
                'weather_code' => $daily['weather_code'][0] ?? null,
                'rain_expected' => $precipitation >= 45,
                'source' => 'Open-Meteo',
            ];
        } catch (Throwable) {
            return ['unavailable' => true, 'rain_expected' => false, 'source' => 'Open-Meteo'];
        }
    }

    private function suggestStart(GallerySpace $space, array $parameters, int $minutes): Carbon
    {
        $base = $parameters['preferred_date'] ? Carbon::parse($parameters['preferred_date'])->startOfDay() : now()->addDay()->startOfDay();
        if ($base->isPast()) $base = now()->addDay()->startOfDay();
        $hour = match ($parameters['time_of_day']) { 'morning' => 9, 'afternoon' => 14, 'evening' => 18, default => 16 };

        for ($offset = 0; $offset < 35; $offset++) {
            $day = $base->copy()->addDays($offset);
            if (! $parameters['preferred_date'] && ! in_array($day->dayOfWeek, [Carbon::FRIDAY, Carbon::SATURDAY, Carbon::SUNDAY], true)) continue;
            $start = $day->copy()->setTime($hour, 0);
            if ($start->lte(now())) continue;
            $end = $start->copy()->addMinutes($minutes);
            $busy = CalendarEvent::query()->where('gallery_space_id', $space->id)->whereNotIn('status', ['cancelled'])
                ->where('starts_at', '<', $end)
                ->where(fn ($query) => $query->whereNull('ends_at')->where('starts_at', '>=', $start)->orWhere('ends_at', '>', $start))
                ->exists();
            if (! $busy) return $start;
            if ($parameters['preferred_date']) break;
        }

        return $base->copy()->addWeek()->setTime($hour, 0);
    }

    private function title(string $theme, string $destination, string $core, int $surprise): string
    {
        $prefix = match ($theme) {
            'romantic' => 'Rande jen pro vás', 'food' => 'Chuťové dobrodružství', 'nature' => 'Únik do přírody',
            'culture' => 'Kulturní objev', 'creative' => 'Tvořivé rande', 'adventure' => 'Malé dobrodružství',
            'relax' => 'Pomalý čas ve dvou', 'low_cost' => 'Low-cost rande', default => 'Překvapení ve dvou',
        };
        return $surprise >= 3 ? "{$prefix}: tajný plán" : "{$prefix} · {$core} v {$destination}";
    }

    private function summary(string $theme, string $destination, array $parameters, Collection $blocks): string
    {
        $scope = match ($parameters['travel_scope']) { 'home'=>'doma', 'nearby'=>'v blízkém okolí', 'city'=>'po městě', 'day_trip'=>'jako jednodenní výlet', 'weekend'=>'jako víkendovou cestu' };
        return 'Originální program '.$scope.' v lokalitě '.$destination.': '.$blocks->pluck('title')->join(' → ').'.';
    }

    private function preparationTasks(array $parameters, bool $trip): array
    {
        return collect([
            $trip ? 'Ověřit spojení a čas návratu' : null,
            $parameters['budget_max'] > 0 ? 'Potvrdit společný limit rozpočtu' : null,
            in_array($parameters['setting'], ['outdoor','any'], true) ? 'Zkontrolovat počasí a připravit mokrou variantu' : null,
            $parameters['food'] !== 'none' ? 'Ověřit otevírací dobu nebo rezervaci jídla' : null,
            'Nabít telefon, ale během hlavní části vypnout oznámení',
        ])->filter()->values()->all();
    }

    private function memoryPrompt(string $theme): string
    {
        return match ($theme) {
            'food' => 'Vyfoťte nejlepší chod a uložte, co byste příště ochutnali jinak.',
            'adventure' => 'Uložte jeden nečekaný moment, který byste předem nenaplánovali.',
            'romantic' => 'Doplňte společnou větu: Dnes jsem na tobě nejvíc ocenil/a…',
            default => 'Vyberte jedinou fotografii dne a napište k ní jednu společnou větu.',
        };
    }

    private function transportCost(string $scope, string $mode): float
    {
        if (in_array($mode, ['walk','bike'], true) || $scope === 'home') return 0;
        return (float) ([
            'nearby' => ['transit'=>80,'car'=>120,'train'=>120],
            'city' => ['transit'=>120,'car'=>240,'train'=>220],
            'day_trip' => ['transit'=>500,'car'=>800,'train'=>700],
            'weekend' => ['transit'=>1200,'car'=>1800,'train'=>1600],
        ][$scope][$mode] ?? 0);
    }

    private function travelMinutes(string $scope): int
    {
        return ['home'=>0,'nearby'=>20,'city'=>50,'day_trip'=>150,'weekend'=>240][$scope];
    }

    private function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371;
        $dLat = deg2rad($lat2 - $lat1); $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
