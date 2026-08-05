<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Album;
use App\Models\CalendarEvent;
use App\Models\EntertainmentTitle;
use App\Models\MediaItem;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use App\Models\SharedTodo;
use App\Services\Banking\SharedExpenseWriteService;
use App\Services\Entertainment\EntertainmentMetadataService;
use App\Services\Planning\CalendarEventCreationService;
use App\Services\Planning\CalendarEventTripService;
use App\Services\Planning\GiftIdeaService;
use App\Services\Planning\LifeEventService;
use App\Services\Taxonomy\UniversalTagService;
use App\Services\Planning\RelationshipMilestoneService;
use App\Services\Planning\TravelInboxService;
use App\Services\Planning\SharedTodoService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class WorkspaceAssistantController extends Controller
{
    private const ACTION_KEYS = ['activities', 'titles', 'recipe', 'expense', 'trip', 'gift', 'milestone', 'todo', 'itinerary'];

    /**
     * Column widths from 2026_07_15_090000_create_recipe_system.php. Tests run on SQLite,
     * which ignores VARCHAR limits, so an over-long value only shows up on MySQL as a
     * "Data too long for column" error — a 500 for the user. Truncating to the real widths
     * is what keeps a long title or section from breaking the whole save.
     */
    private const RECIPE_TITLE_MAX = 180;        // recipes.title
    private const INGREDIENT_NAME_MAX = 180;     // recipe_ingredients.name
    private const INGREDIENT_SECTION_MAX = 100;  // recipe_ingredients.section
    private const INGREDIENT_UNIT_MAX = 32;      // recipe_ingredients.unit
    private const STEP_TITLE_MAX = 180;          // recipe_steps.title

    public function __construct(
        private readonly CalendarEventCreationService $calendarEvents,
        private readonly CalendarEventTripService $tripService,
        private readonly SharedExpenseWriteService $expenses,
        private readonly SharedTodoService $todoService,
        private readonly GiftIdeaService $gifts,
        private readonly RelationshipMilestoneService $milestones,
        private readonly TravelInboxService $travelInbox,
        private readonly LifeEventService $lifeEvents,
        private readonly UniversalTagService $universalTags,
        private readonly EntertainmentMetadataService $titleMetadata,
    ) {}

    public function preview(Request $request)
    {
        $data = $request->validate(['message' => 'required|string|min:2|max:20000']);
        $plan = $this->plan($data['message']);
        $plan['titles'] = $this->withDatabaseMatches($plan['titles']);

        return response()->json($plan);
    }

    /**
     * Parse a recipe pasted as ordinary prose: a title line, a "Suroviny" section of
     * bullets (optionally grouped by sub-headings) and a "Postup" section of numbered
     * steps that each run over several lines. The older single-line
     * "suroviny: a, b, c" form is handled by the caller and stays supported.
     *
     * @return array{title:string, ingredients:list<string>, steps:list<string>, ingredient_rows:list<array<string,mixed>>, step_rows:list<array<string,mixed>>}
     */
    private function recipeBlocks(string $message): array
    {
        $empty = ['title' => '', 'ingredients' => [], 'steps' => [], 'ingredient_rows' => [], 'step_rows' => []];
        $lines = preg_split('/\R/u', $message) ?: [];
        if (count($lines) < 3) return $empty;

        // Recipes are usually pasted from somewhere that formats them, so headings arrive
        // as "**Suroviny**", "## Postup" or "__Suroviny__". Strip that before matching,
        // otherwise the whole recipe silently parses as nothing.
        $plain = static fn (string $line) => trim(preg_replace('/^[#>\s]*|[*_~`]+/u', '', $line) ?? '');

        $isIngredientsHeading = static fn (string $line) => (bool) preg_match('/^(?:ingredience|suroviny)\s*:?$/ui', $plain($line));
        $isStepsHeading = static fn (string $line) => (bool) preg_match('/^(?:přesný\s+postup|presny\s+postup|postup|kroky|příprava\s+krok|instrukce)\s*:?$/ui', $plain($line));

        $ingredientsAt = null;
        $stepsAt = null;
        foreach ($lines as $index => $line) {
            if ($ingredientsAt === null && $isIngredientsHeading($line)) $ingredientsAt = $index;
            if ($stepsAt === null && $isStepsHeading($line)) $stepsAt = $index;
        }
        if ($ingredientsAt === null && $stepsAt === null) return $empty;

        $title = '';
        foreach ($lines as $line) {
            $candidate = $plain($line);
            // A leading slash command is not part of the recipe's name.
            $candidate = trim(preg_replace('/^\/[\p{L}]+\s*/u', '', $candidate) ?? '');
            if ($candidate !== '' && ! $isIngredientsHeading($line) && ! $isStepsHeading($line)) {
                $title = mb_substr($candidate, 0, self::RECIPE_TITLE_MAX);
                break;
            }
        }

        $ingredientEnd = $stepsAt !== null && ($ingredientsAt === null || $stepsAt > $ingredientsAt) ? $stepsAt : count($lines);
        $ingredientRows = $ingredientsAt !== null
            ? $this->parseIngredientLines(array_slice($lines, $ingredientsAt + 1, $ingredientEnd - $ingredientsAt - 1))
            : [];
        $stepRows = $stepsAt !== null ? $this->parseStepLines(array_slice($lines, $stepsAt + 1)) : [];

        return [
            'title' => $title,
            'ingredients' => array_map(static fn (array $row) => $row['label'], $ingredientRows),
            'steps' => array_map(static fn (array $row) => trim($row['title'] . ($row['instruction'] !== '' ? ' — ' . $row['instruction'] : '')), $stepRows),
            'ingredient_rows' => $ingredientRows,
            'step_rows' => $stepRows,
        ];
    }

    /** @return list<array{label:string,section:?string,name:string,quantity:?float,unit:?string,optional:bool}> */
    private function parseIngredientLines(array $lines): array
    {
        $rows = [];
        $section = null;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            // A line without a bullet inside the ingredients block is a group heading.
            if (! preg_match('/^\s*(?:[*\-–•·]|\d+[.)])\s+(.*)$/u', $line, $bullet)) {
                $section = mb_substr(rtrim($line, ":"), 0, self::INGREDIENT_SECTION_MAX);
                continue;
            }
            $label = trim($bullet[1]);
            if ($label === '') continue;
            $optional = (bool) preg_match('/voliteln|nepovinn/ui', $label);
            $quantity = null;
            $unit = null;
            $name = $label;
            // "600 g polohrubé mouky", "3–5 g citronové kůry", "2 žloutky"
            if (preg_match('/^(?:přibližně\s+|cca\s+|asi\s+)?(\d+(?:[.,]\d+)?)(?:\s*[-–]\s*\d+(?:[.,]\d+)?)?\s*([\p{L}]{1,12})?\s+(.*)$/u', $label, $parts)) {
                $quantity = $this->numberFrom($parts[1]);
                $unit = trim($parts[2] ?? '') !== '' ? mb_substr($parts[2], 0, self::INGREDIENT_UNIT_MAX) : null;
                $name = trim($parts[3]);
            }
            $rows[] = ['label' => mb_substr($label, 0, self::INGREDIENT_NAME_MAX), 'section' => $section, 'name' => mb_substr($name !== '' ? $name : $label, 0, self::INGREDIENT_NAME_MAX), 'quantity' => $quantity, 'unit' => $unit, 'optional' => $optional];
            if (count($rows) >= 120) break;
        }

        return $rows;
    }

    /** @return list<array{title:string,instruction:string}> */
    private function parseStepLines(array $lines): array
    {
        $rows = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') continue;
            if (preg_match('/^(\d{1,2})[.)]\s*(.*)$/u', $trimmed, $numbered)) {
                $rows[] = ['title' => mb_substr(trim($numbered[2]), 0, self::STEP_TITLE_MAX), 'instruction' => ''];
                continue;
            }
            if ($rows === []) continue;   // prose before the first numbered step is a lead-in
            $last = count($rows) - 1;
            // Bullets inside a step are part of its instruction.
            $text = preg_replace('/^\s*[*\-–•·]\s+/u', '• ', $trimmed);
            $rows[$last]['instruction'] = trim($rows[$last]['instruction'] === '' ? $text : $rows[$last]['instruction'] . "\n" . $text);
            if (count($rows) >= 60) break;
        }

        // A step whose title is empty but which has body text still needs a usable name.
        foreach ($rows as $index => $row) {
            if ($row['title'] === '' && $row['instruction'] !== '') {
                $rows[$index]['title'] = mb_substr(trim(strtok($row['instruction'], "\n")), 0, self::STEP_TITLE_MAX);
            }
        }

        return array_values(array_filter($rows, static fn (array $row) => $row['title'] !== '' || $row['instruction'] !== ''));
    }

    /**
     * Decide which movie-database entry backs each detected title and fetch its details.
     * An explicit choice wins; otherwise the top search hit is used automatically. A choice
     * of 'manual' (null external_id) keeps the plain name, as does an unconfigured or
     * unreachable database — the title is still saved either way.
     *
     * @param  list<array{title:string,type:string}>  $titles
     * @param  list<array{title:string,external_id?:?string,media_type?:string}>  $choices
     * @return list<array{title:string,type:string,external_id:?string,details:array<string,mixed>}>
     */
    private function resolveTitles(array $titles, array $choices): array
    {
        $chosen = collect($choices)->keyBy(fn (array $choice) => mb_strtolower(trim($choice['title'])));
        $configured = $this->titleMetadata->configured();

        return array_map(function (array $title) use ($chosen, $configured) {
            $type = $title['type'];
            $key = mb_strtolower(trim($title['title']));
            $choice = $chosen->get($key);
            $externalId = null;

            if ($choice) {
                $externalId = $choice['external_id'] ?? null;   // explicit pick, possibly 'manual' => null
                $type = $choice['media_type'] ?? $type;
            } elseif ($configured) {
                try {
                    $best = collect($this->titleMetadata->search($title['title'], $type === 'series' ? 'tv' : 'movie'))->first();
                    $externalId = $best['external_id'] ?? null;
                    $type = $best['media_type'] ?? $type;
                } catch (\Throwable $exception) {
                    report($exception);
                }
            }

            $details = [];
            if (filled($externalId) && $configured) {
                try {
                    $details = collect($this->titleMetadata->details($type === 'series' ? 'tv' : 'movie', (int) $externalId))
                        ->except('community_rating')->all();
                } catch (\Throwable $exception) {
                    report($exception);
                    $externalId = null;   // fall back to a plain entry rather than losing the title
                }
            }

            return ['title' => $title['title'], 'type' => $type, 'external_id' => filled($externalId) ? (string) $externalId : null, 'details' => $details];
        }, $titles);
    }

    /**
     * Offer movie-database candidates for each detected title so the chat can fill in
     * poster, year and runtime instead of storing a bare name. The first candidate is
     * the automatic pick; the user may choose another in the preview.
     *
     * @param  list<array{title:string,type:string}>  $titles
     * @return list<array<string,mixed>>
     */
    private function withDatabaseMatches(array $titles): array
    {
        if ($titles === [] || ! $this->titleMetadata->configured()) {
            return array_map(fn (array $title) => $title + ['candidates' => []], $titles);
        }

        return array_map(function (array $title) {
            try {
                $matches = $this->titleMetadata->search($title['title'], $title['type'] === 'series' ? 'tv' : 'movie');
            } catch (\Throwable $exception) {
                report($exception);
                $matches = [];
            }

            return $title + ['candidates' => collect($matches)->take(5)->map(fn (array $item) => [
                'external_id' => $item['external_id'] ?? null,
                'title' => $item['title'] ?? null,
                'media_type' => $item['media_type'] ?? $title['type'],
                'release_year' => $item['release_year'] ?? null,
                'poster_url' => $item['poster_url'] ?? null,
                'overview' => $item['overview'] ?? null,
            ])->values()->all()];
        }, $titles);
    }

    public function apply(Request $request)
    {
        $data = $request->validate([
            'message' => 'required|string|min:2|max:20000', 'request_id' => 'nullable|uuid',
            'selected_actions' => 'nullable|array|max:9', 'selected_actions.*' => 'string',
            'media_uuids' => 'nullable|array|max:100', 'media_uuids.*' => 'uuid',
            // Which movie-database entry the user picked for each detected title.
            // 'manual' keeps the plain name; omitting a title falls back to the automatic pick.
            'title_choices' => 'nullable|array|max:20',
            'title_choices.*.title' => 'required|string|max:255',
            'title_choices.*.external_id' => 'nullable|string|max:64',
            'title_choices.*.media_type' => 'nullable|in:movie,series',
        ]);
        $user = $request->user();
        $space = $user->gallerySpaces()->orderByDesc('is_default')->firstOrFail();
        if (!empty($data['request_id']) && Schema::hasTable('assistant_action_receipts')) {
            $receipt = DB::table('assistant_action_receipts')
                ->where('request_id', $data['request_id'])
                ->where('gallery_space_id', $space->id)
                ->where('user_id', $user->id)
                ->first(['response']);
            if ($receipt) return response()->json(json_decode($receipt->response, true));
        }
        $plan = $this->plan($data['message']);
        $selectedActions = $data['selected_actions'] ?? self::ACTION_KEYS;
        abort_if(array_diff($selectedActions, self::ACTION_KEYS), 422, 'Náhled obsahuje neplatný typ zápisu.');
        $plan = $this->onlySelectedActions($plan, array_values(array_unique($selectedActions)));
        $mediaUuids = array_values(array_unique($data['media_uuids'] ?? []));

        abort_if($plan['search'], 422, 'Vyhledávání se otevírá přímo bez ukládání.');
        abort_unless($this->hasActions($plan) || $mediaUuids, 422, 'Nerozpoznal jsem položku, kterou lze uložit.');

        // Resolved outside the transaction: this may call the movie database over HTTP.
        $resolvedTitles = $this->resolveTitles($plan['titles'], $data['title_choices'] ?? []);

        $created = [];
        $lifeEvents = $this->lifeEvents;
        $calendarEvents = $this->calendarEvents;
        $tripService = $this->tripService;
        $expenses = $this->expenses;
        $todoService = $this->todoService;
        $gifts = $this->gifts;
        $milestones = $this->milestones;
        $travelInbox = $this->travelInbox;
        $universalTags = $this->universalTags;

        DB::transaction(function () use ($plan, $data, $mediaUuids, $user, $space, &$created, $lifeEvents, $calendarEvents, $tripService, $expenses, $todoService, $gifts, $milestones, $travelInbox, $universalTags, $resolvedTitles) {
            $activityEventId = null;
            foreach ($resolvedTitles as $title) {
                $lookup = $title['external_id']
                    ? ['gallery_space_id' => $space->id, 'external_source' => 'tmdb', 'external_id' => $title['external_id']]
                    : ['gallery_space_id' => $space->id, 'external_source' => 'manual', 'external_id' => null, 'title' => $title['title']];
                $entertainmentTitle = EntertainmentTitle::firstOrCreate(
                    $lookup,
                    $title['details'] + ['added_by' => $user->id, 'media_type' => $title['type'], 'title' => $title['title'], 'status' => 'proposed', 'priority' => 'normal']
                );
                // An entry added earlier as a bare name gets its details filled in now.
                if (! $entertainmentTitle->wasRecentlyCreated && $title['details'] && blank($entertainmentTitle->external_id)) {
                    $entertainmentTitle->fill($title['details'])->save();
                }
                if ($entertainmentTitle->wasRecentlyCreated) {
                    if (Schema::hasColumn('entertainment_titles', 'created_from')) {
                        $entertainmentTitle->update(['created_from' => 'assistant', 'source_reference' => $data['request_id'] ?? null]);
                    }
                    $lifeEvents->record($space->id, $user->id, 'watchlist.proposed', $title['title'], 'assistant', EntertainmentTitle::class, $entertainmentTitle->id, now('Europe/Prague'), ['media_type' => $title['type']]);
                }
                $universalTags->assignNames($space, $user, 'entertainment', (int) $entertainmentTitle->id, $plan['tags']);
                $created[] = ($title['type'] === 'series' ? 'Seriál: ' : 'Film: ') . $title['title'];
            }

            if ($plan['recipe']) {
                $recipe = Recipe::firstOrCreate(
                    ['gallery_space_id' => $space->id, 'title' => $plan['recipe']],
                    ['created_by' => $user->id, 'updated_by' => $user->id, 'summary' => $data['message'], 'source_name' => 'Chat', 'category' => 'main_course', 'difficulty' => 'medium', 'status' => 'published', 'base_servings' => 2]
                );
                $recipe->fill(array_filter([
                    'base_servings' => $plan['recipe_details']['servings'],
                    'prep_minutes' => $plan['recipe_details']['prep_minutes'],
                    'cook_minutes' => $plan['recipe_details']['cook_minutes'],
                    'source_url' => $plan['recipe_details']['source_url'],
                    'tips' => $plan['recipe_details']['notes'],
                ], static fn ($value) => $value !== null && $value !== ''));
                $recipe->updated_by = $user->id;
                $recipe->save();
                // A structured paste keeps its groups, amounts and step titles; the plain
                // single-line form still lands as a bare name.
                $ingredientRows = $plan['recipe_details']['ingredient_rows'] ?? [];
                if ($ingredientRows) {
                    foreach ($ingredientRows as $index => $row) {
                        RecipeIngredient::firstOrCreate(
                            ['recipe_id' => $recipe->id, 'name' => $row['name']],
                            ['section' => $row['section'], 'quantity' => $row['quantity'], 'unit' => $row['unit'],
                             'is_optional' => $row['optional'], 'sort_order' => $index, 'is_scalable' => $row['quantity'] !== null]
                        );
                    }
                } else {
                    foreach ($plan['recipe_details']['ingredients'] as $index => $ingredient) {
                        RecipeIngredient::firstOrCreate(
                            ['recipe_id' => $recipe->id, 'name' => $ingredient],
                            ['sort_order' => $index, 'is_scalable' => true]
                        );
                    }
                }

                $stepRows = $plan['recipe_details']['step_rows'] ?? [];
                if ($stepRows) {
                    foreach ($stepRows as $index => $row) {
                        RecipeStep::firstOrCreate(
                            ['recipe_id' => $recipe->id, 'sort_order' => $index],
                            ['title' => $row['title'], 'instruction' => $row['instruction'] !== '' ? $row['instruction'] : $row['title']]
                        );
                    }
                } else {
                    foreach ($plan['recipe_details']['steps'] as $index => $step) {
                        RecipeStep::firstOrCreate(
                            ['recipe_id' => $recipe->id, 'instruction' => $step],
                            ['sort_order' => $index]
                        );
                    }
                }
                $universalTags->assignNames($space, $user, 'recipe', (int) $recipe->id, $plan['tags']);
                $created[] = 'Recept: ' . $plan['recipe'] . (($plan['recipe_details']['ingredients'] || $plan['recipe_details']['steps']) ? ' včetně surovin a postupu' : '');
            }

            if ($plan['activities']) {
                $at = Carbon::parse($plan['activity_date'], 'Europe/Prague')->setTime(18, 0);
                $event = $calendarEvents->create($space, $user, [
                    'gallery_space_id' => $space->id,
                    'created_by' => $user->id,
                    'title' => implode(' · ', $plan['activities']),
                    'description' => $data['message'],
                    'type' => 'outing',
                    'status' => 'completed',
                    'starts_at' => $at,
                    'ends_at' => $at->copy()->addHours(count($plan['activities'])),
                    'timezone' => 'Europe/Prague',
                    'metadata' => ['source' => 'assistant', 'activities' => $plan['activities'], 'expense' => $plan['expense']],
                ]);
                $activityEventId = $event->id;
                $universalTags->assignNames($space, $user, 'calendar_event', (int) $event->id, $plan['tags']);
                $created[] = 'Hotová aktivita: ' . $event->title;
            }

            $tripId = null;
            if ($plan['trip']) {
                $event = $calendarEvents->create($space, $user, [
                    'gallery_space_id' => $space->id,
                    'created_by' => $user->id,
                    'title' => $plan['trip']['name'],
                    'description' => $plan['trip']['notes'],
                    'type' => 'trip',
                    'status' => 'planned',
                    'starts_at' => Carbon::parse($plan['trip']['start_date'], 'Europe/Prague')->startOfDay(),
                    'ends_at' => Carbon::parse($plan['trip']['end_date'], 'Europe/Prague')->endOfDay(),
                    'all_day' => true,
                    'timezone' => 'Europe/Prague',
                    'metadata' => ['source' => 'assistant'],
                ]);

                // The same service backs calendar-created trips, so the chat now
                // creates trip days and preparation tasks consistently as well.
                [$trip] = $tripService->createFromEvent($event, $user->id);
                $tripId = $trip->id;
                $universalTags->assignNames($space, $user, 'calendar_event', (int) $event->id, $plan['tags']);
                $universalTags->assignNames($space, $user, 'trip', (int) $tripId, $plan['tags']);


                foreach ($plan['trip']['waypoints'] as $index => $waypoint) {
                    DB::table('trip_waypoints')->insert([
                        'trip_id' => $tripId,
                        'place_name' => $waypoint,
                        'sort_order' => $index,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                $created[] = 'Cesta, kalendář a přípravy: ' . $plan['trip']['name'];
            }
            if ($plan['expense']) {
                $expense = $expenses->create($space, $user, [
                    'paid_by_user_id' => $user->id,
                    'calendar_event_id' => $activityEventId,
                    'trip_id' => $tripId,
                    'title' => $plan['activities'] ? implode(' · ', $plan['activities']) : 'Výdaj z chatu',
                    'category' => $plan['activities'] && in_array('Nákup', $plan['activities'], true) ? 'food' : 'other',
                    'amount' => $plan['expense']['amount'],
                    'currency' => $plan['expense']['currency'],
                    'occurred_at' => Carbon::parse($plan['activity_date'], 'Europe/Prague')->setTime(18, 0),
                    'split_mode' => 'equal',
                    'metadata' => ['message' => $data['message']],
                ], 'assistant', $data['request_id'] ?? null);
                $expenseId = (int) $expense->id;
                $lifeEvents->record($space->id, $user->id, 'finance.expense.recorded', $plan['activities'] ? implode(' · ', $plan['activities']) : 'Výdaj z chatu', 'assistant', 'shared_expense', $expenseId, Carbon::parse($plan['activity_date'], 'Europe/Prague'), ['amount' => $plan['expense']['amount'], 'currency' => $plan['expense']['currency'], 'trip_id' => $tripId]);
                $universalTags->assignNames($space, $user, 'expense', $expenseId, $plan['tags']);
                $created[] = 'Výdaj do společného rozpočtu: ' . number_format((float) $plan['expense']['amount'], 2, ',', ' ') . ' ' . $plan['expense']['currency'];
            }


            if ($plan['gift']) {
                $gift = $gifts->create($space->id, $user->id, $plan['gift'] + ['currency' => 'CZK', 'reminder_days' => [30, 14, 7], 'source_reference' => $data['request_id'] ?? null], 'assistant');
                $universalTags->assignNames($space, $user, 'gift', (int) $gift->id, $plan['tags']);
                $created[] = 'Dárek: ' . $plan['gift']['title'];
            }

            if ($plan['milestone']) {
                $milestone = $milestones->create($space->id, $user->id, $plan['milestone'] + ['icon' => '❤️', 'visibility' => 'shared', 'remind_annually' => true, 'source_reference' => $data['request_id'] ?? null], 'assistant');
                $universalTags->assignNames($space, $user, 'milestone', (int) $milestone->id, $plan['tags']);
                $created[] = 'Výročí: ' . $plan['milestone']['title'];
            }
            if ($plan['todo']) {
                $titles = $plan['todo']['items'] ?? [$plan['todo']['title']];
                foreach (array_values(array_unique(array_filter($titles))) as $title) {
                    $todo = $todoService->create($space, $user, [
                        'title' => $title,
                        'priority' => $plan['todo']['priority'],
                        'due_at' => $plan['todo']['due_at'],
                        'metadata' => ['kind' => $plan['todo']['kind']],
                    ], source: 'assistant', sourceReference: $data['request_id'] ?? null);
                    $universalTags->assignNames($space, $user, 'todo', (int) $todo->id, $plan['tags']);
                    $created[] = ($plan['todo']['kind'] === 'shopping' ? 'Nákup: ' : 'Společný úkol: ') . $title;
                }
            }

            if ($mediaUuids) {
                $media = MediaItem::where('gallery_space_id', $space->id)->whereIn('uuid', $mediaUuids)->whereNull('trashed_at')->get()->keyBy('uuid');
                abort_unless($media->count() === count($mediaUuids), 422, 'Některé přiložené fotografie už nejsou dostupné v tomto prostoru.');
                $orderedMedia = collect($mediaUuids)->map(fn ($uuid) => $media->get($uuid));
                $event = $activityEventId ? CalendarEvent::find($activityEventId) : ($tripId ? CalendarEvent::where('gallery_space_id', $space->id)->where('trip_id', $tripId)->latest('id')->first() : null);
                $date = $event?->starts_at ? Carbon::parse($event->starts_at) : Carbon::parse($plan['activity_date'], 'Europe/Prague');
                $title = $event ? 'Fotky · ' . $event->title : 'Fotky z chatu · ' . $date->format('d. m. Y');
                $album = app(\App\Services\AlbumService::class)->createEventAlbum($space, [
                    'trip_id' => $tripId, 'title' => $title, 'slug' => Str::slug($title . '-' . Str::random(6)),
                    'description' => 'Fotografie přiložené k zápisu z Maki pomocníka.', 'cover_media_id' => $orderedMedia->first()->id,
                    'event_date_start' => $date->toDateString(), 'event_date_end' => $date->toDateString(),
                    'story_mode' => true, 'event_mode' => true, 'event_start_at' => $event?->starts_at ?: $date,
                    'event_end_at' => $event?->ends_at ?: $date, 'event_place_name' => $event?->place_name,
                    'album_type' => 'physical', 'icon' => '✨', 'color' => '#8b5cf6',
                ], $user, $orderedMedia);
                MediaItem::whereIn('id', $orderedMedia->pluck('id'))->whereNull('primary_album_id')->update(['primary_album_id' => $album->id]);
                $album->update(['media_count' => $orderedMedia->count(), 'total_size_bytes' => (int) $orderedMedia->sum('size_bytes')]);
                if ($event && Schema::hasColumn('calendar_events', 'album_id')) $event->update(['album_id' => $album->id]);
                $lifeEvents->record($space->id, $user->id, 'album.chat.created', $album->title, 'assistant', Album::class, $album->id, $date, ['media_count' => $orderedMedia->count(), 'calendar_event_id' => $event?->id, 'trip_id' => $tripId]);
                $universalTags->assignNames($space, $user, 'album', (int) $album->id, $plan['tags']);
                $created[] = 'Album z přiložených fotek: ' . $album->title;
            }

            if ($plan['itinerary']) {
                $resolvedTripId = $tripId ?: DB::table('trips')
                    ->where('gallery_space_id', $space->id)
                    ->where('name', $plan['itinerary']['trip_name'])
                    ->orderByDesc('start_date')
                    ->value('id');

                $travelInbox->create($space->id, $user->id, [
                    'trip_id' => $resolvedTripId,
                    'title' => 'Itinerář: ' . $plan['itinerary']['trip_name'],
                    'notes' => implode("\n", $plan['itinerary']['items']),
                    'kind' => 'itinerary',
                    'state' => 'inbox',
                    'metadata' => ['items' => $plan['itinerary']['items']],
                    'source_reference' => $data['request_id'] ?? null,
                ], 'assistant');
                $created[] = 'Itinerář: ' . $plan['itinerary']['trip_name'];
            }
        });

        $response = ['created' => $created, 'plan' => $plan];
        if (!empty($data['request_id']) && Schema::hasTable('assistant_action_receipts')) {
            DB::table('assistant_action_receipts')->insertOrIgnore([
                'request_id' => $data['request_id'],
                'gallery_space_id' => $space->id,
                'user_id' => $user->id,
                'response' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        AuditLog::record('assistant.apply', null, [
            'gallery_space_id' => $space->id,
            'created' => $created,
            'message_length' => mb_strlen($data['message']),
            'source' => 'workspace_assistant',
            'selected_actions' => $selectedActions,
        ]);

        return response()->json($response, 201);
    }

    private function onlySelectedActions(array $plan, array $selectedActions): array
    {
        $selected = array_fill_keys($selectedActions, true);
        foreach (['activities', 'titles'] as $key) {
            if (! isset($selected[$key])) $plan[$key] = [];
        }
        foreach (['recipe', 'expense', 'trip', 'gift', 'milestone', 'todo', 'itinerary'] as $key) {
            if (! isset($selected[$key])) $plan[$key] = null;
        }

        return $plan;
    }
    private function hasActions(array $plan): bool
    {
        return (bool) ($plan['activities'] || $plan['titles'] || $plan['recipe'] || $plan['expense'] || $plan['trip'] || $plan['gift'] || $plan['milestone'] || $plan['todo'] || $plan['itinerary']);
    }

    private function plan(string $message): array
    {
        preg_match_all('/(?:^|\\s)#([\\p{L}\\p{N}][\\p{L}\\p{N}_-]{0,79})/u', $message, $tagMatches);
        $tags = collect($tagMatches[1] ?? [])->map(fn (string $tag) => trim($tag))->unique(fn (string $tag) => mb_strtolower($tag))->take(12)->values()->all();
        $message = trim((string) preg_replace('/(?:^|\\s)#[\\p{L}\\p{N}][\\p{L}\\p{N}_-]{0,79}/u', ' ', $message));
        $lower = mb_strtolower($message);
        $dates = $this->datesFrom($message);
        $command = $this->command($message);
        $activityDate = $this->activityDateFrom($message, $dates);
        $activities = [];

        foreach (['káva' => 'Káva', 'bazén' => 'Bazén', 'nakoup' => 'Nákup', 'kino' => 'Kino', 'procház' => 'Procházka', 'večeř' => 'Večeře'] as $needle => $label) {
            if (str_contains($lower, $needle)) $activities[] = $label;
        }

        preg_match('/(?:utratili|zaplatili|za)\s*([0-9 .]+(?:,[0-9]{1,2})?)\s*(?:kč|czk)/ui', $message, $amount);
        $expense = isset($amount[1]) ? ['amount' => $this->numberFrom($amount[1]), 'currency' => 'CZK'] : null;
        preg_match_all('/(?:filmy?|seriály?)\s*:\s*([^\n.]+)/ui', $message, $lists, PREG_SET_ORDER);
        $titles = [];
        foreach ($lists as $list) {
            $type = str_starts_with(mb_strtolower($list[0]), 'seri') ? 'series' : 'movie';
            foreach (preg_split('/[,;]| a /u', $list[1]) as $title) {
                $title = trim($title);
                if ($title) $titles[] = ['title' => $title, 'type' => $type];
            }
        }
        if (in_array($command['name'], ['/film', '/filmy', '/seriál', '/serial', '/seriály', '/serialy'], true) && $command['body']) {
            $titles[] = ['title' => $this->firstLine($command['body']), 'type' => in_array($command['name'], ['/seriál', '/serial', '/seriály', '/serialy'], true) ? 'series' : 'movie'];
        }

        preg_match('/recept\s*:\s*([^\n]+)/ui', $message, $recipeMatch);
        $recipe = in_array($command['name'], ['/recept', '/recepty'], true) ? trim($command['body']) : trim($recipeMatch[1] ?? '');
        $blocks = $this->recipeBlocks($message);
        // A pasted recipe carries its own headings, so the first line is the title.
        if ($recipe === '' && $blocks['ingredients'] && $blocks['steps']) $recipe = $blocks['title'];
        // A '/recept' command with the whole text pasted after it: keep only the first line as the name.
        if ($recipe !== '' && str_contains($recipe, "\n")) $recipe = trim(strtok($recipe, "\n"));
        // recipes.title is varchar(180); an over-long name would be a 500 on MySQL.
        if ($recipe !== '') $recipe = mb_substr($recipe, 0, self::RECIPE_TITLE_MAX);

        preg_match('/(?:ingredience|suroviny)\s*:\s*([^\n]+)/ui', $message, $ingredientsMatch);
        preg_match('/(?:postup|kroky)\s*:\s*([^\n]+)/ui', $message, $stepsMatch);
        $recipeDetails = [
            'ingredients' => $blocks['ingredients'] ?: array_values(array_filter(array_map('trim', preg_split('/[,;]/u', $ingredientsMatch[1] ?? '')))),
            'steps' => $blocks['steps'] ?: array_values(array_filter(array_map('trim', preg_split('/[;]|→|->/u', $stepsMatch[1] ?? '')))),
            'ingredient_rows' => $blocks['ingredient_rows'],
            'step_rows' => $blocks['step_rows'],
            'servings' => null,
            'prep_minutes' => null,
            'cook_minutes' => null,
            'source_url' => null,
            'notes' => null,
        ];
        if (preg_match('/(?:porce|porcí|osoby)\s*:?\s*(\d+(?:[.,]\d+)?)/ui', $message, $servingsMatch)) $recipeDetails['servings'] = $this->numberFrom($servingsMatch[1]);
        if (preg_match('/(?:příprava|priprava|prep)\s*:?\s*(\d+)\s*(?:min|minut)?/ui', $message, $prepMatch)) $recipeDetails['prep_minutes'] = (int) $prepMatch[1];
        if (preg_match('/(?:vaření|vareni|pečení|peceni|cook)\s*:?\s*(\d+)\s*(?:min|minut)?/ui', $message, $cookMatch)) $recipeDetails['cook_minutes'] = (int) $cookMatch[1];
        if (preg_match('/https?:\/\/[^\s]+/ui', $message, $urlMatch)) $recipeDetails['source_url'] = rtrim($urlMatch[0], '.,;');
        if (preg_match('/(?:poznámka|poznamka|tip)\s*:\s*([^\n]+)/ui', $message, $notesMatch)) $recipeDetails['notes'] = trim($notesMatch[1]);

        $trip = null;
        if (in_array($command['name'], ['/cesta', '/cesty'], true)) {
            $parts = $this->parts($command['body']);
            $trip = $this->tripFromParts($parts, $dates);
        } elseif (preg_match('/(?:cesta|výlet|dovolená)\s+(?:do\s+)?(.+)/ui', $message, $tripMatch) && count($dates) >= 2) {
            $trip = $this->tripFromParts([trim(preg_replace('/\b\d{1,2}\.\d{1,2}\.(?:\d{4})?\b|\b\d{4}-\d{2}-\d{2}\b/u', '', $tripMatch[1]))], $dates);
        }

        $gift = null;
        if (in_array($command['name'], ['/dárek', '/darek'], true)) {
            $parts = $this->parts($command['body']);
            if ($parts[0] ?? null) {
                $gift = ['title' => $parts[0], 'occasion' => $parts[1] ?? null, 'due_date' => $this->dateFrom($parts[2] ?? null), 'budget' => $this->numberFrom($parts[3] ?? null)];
            }
        } elseif (preg_match('/(?:dárek|darek)\s*:\s*([^\n]+)/ui', $message, $giftMatch)) {
            $parts = $this->parts($giftMatch[1]);
            if ($parts[0] ?? null) {
                $gift = ['title' => $parts[0], 'occasion' => $parts[1] ?? null, 'due_date' => $this->dateFrom($parts[2] ?? null) ?: ($dates[0] ?? null), 'budget' => $this->numberFrom($parts[3] ?? null)];
            }
        }

        $milestone = null;
        if (in_array($command['name'], ['/výročí', '/vyroci'], true)) {
            $parts = $this->parts($command['body']);
            $occurredOn = $this->dateFrom($parts[1] ?? null);
            if (($parts[0] ?? null) && $occurredOn) {
                $milestone = ['title' => $parts[0], 'occurred_on' => $occurredOn, 'description' => $parts[2] ?? null];
            }
        } elseif (preg_match('/(?:výročí|vyroci)\s*:\s*([^\n]+)/ui', $message, $milestoneMatch)) {
            $parts = $this->parts($milestoneMatch[1]);
            $occurredOn = $this->dateFrom($parts[1] ?? null) ?: ($dates[0] ?? null);
            if (($parts[0] ?? null) && $occurredOn) {
                $milestone = ['title' => $parts[0], 'occurred_on' => $occurredOn, 'description' => $parts[2] ?? null];
            }
        }

        $todo = null;
        if (in_array($command['name'], ['/úkol', '/ukol', '/nákup', '/nakup'], true) && $command['body']) {
            $parts = $this->parts($command['body']);
            $kind = in_array($command['name'], ['/nákup', '/nakup'], true) ? 'shopping' : 'todo';
            $items = $this->todoItems($parts[0] ?? '', $kind);
            if ($items) $todo = [
                'title' => $items[0],
                'items' => $items,
                'due_at' => $this->dateFrom($parts[1] ?? null),
                'priority' => in_array($parts[2] ?? null, ['low', 'normal', 'high'], true) ? $parts[2] : 'normal',
                'kind' => $kind,
            ];
        } elseif (preg_match('/(?:úkol|ukol|nákup|nakup)\s*:\s*([^\n]+)/ui', $message, $todoMatch)) {
            $parts = $this->parts($todoMatch[1]);
            if ($parts[0] ?? null) {
                $kind = str_contains(mb_strtolower($message), 'nákup') || str_contains(mb_strtolower($message), 'nakup') ? 'shopping' : 'todo';
                $items = $this->todoItems($parts[0], $kind);
                if ($items) $todo = [
                    'title' => $items[0],
                    'items' => $items,
                    'due_at' => $this->dateFrom($parts[1] ?? null) ?: ($dates[0] ?? null),
                    'priority' => in_array($parts[2] ?? null, ['low', 'normal', 'high'], true) ? $parts[2] : 'normal',
                    'kind' => $kind,
                ];
            }
        }

        $itinerary = null;
        if (in_array($command['name'], ['/itinerář', '/itinerar'], true)) {
            $parts = $this->parts($command['body']);
            $items = isset($parts[1]) ? array_values(array_filter(array_map('trim', preg_split('/[,;]|→|->/u', $parts[1])))) : [];
            if (($parts[0] ?? null) && $items) $itinerary = ['trip_name' => $parts[0], 'items' => $items];
        } elseif (preg_match('/(?:itinerář|itinerar)\s*(?:pro)?\s*([^:]+):\s*(.+)/ui', $message, $itineraryMatch)) {
            $items = array_values(array_filter(array_map('trim', preg_split('/[,;]|→|->/u', $itineraryMatch[2]))));
            if ($items) $itinerary = ['trip_name' => trim($itineraryMatch[1]), 'items' => $items];
        }

        $clarification = null;
        if (($activities || $expense) && ! $activityDate) {
            $clarification = [
                'kind' => 'occurrence_date',
                'question' => 'Kdy se tato aktivita nebo výdaj stal? Napište prosím například „dnes“, „včera“ nebo datum 3. 8. 2026.',
            ];
            $activities = [];
            $expense = null;
        }

        $milestoneRequested = in_array($command['name'], ['/výročí', '/vyroci'], true) || preg_match('/(?:výročí|vyroci)\s*:/ui', $message) === 1;
        if (! $clarification && $milestoneRequested && ! $milestone) {
            $clarification = [
                'kind' => 'milestone_date',
                'question' => 'Pro výročí potřebuji název i datum. Napište například /výročí První rande | 2020-08-17 | naše kavárna.',
            ];
        }
        if (! $clarification && in_array($command['name'], ['/cesta', '/cesty'], true) && ! $trip) {
            $clarification = [
                'kind' => 'trip_dates',
                'question' => 'Pro cestu potřebuji název, začátek a konec ve správném pořadí. Napište například /cesta Vídeň | 2026-08-10 | 2026-08-14.',
            ];
        }
        if (! $clarification && in_array($command['name'], ['/itinerář', '/itinerar'], true) && ! $itinerary) {
            $clarification = [
                'kind' => 'itinerary_items',
                'question' => 'Pro itinerář potřebuji název cesty a alespoň jeden bod. Napište například /itinerář Vídeň | snídaně, muzeum, večeře.',
            ];
        }

        $warnings = [];
        if (in_array($command['name'], ['/cesta', '/cesty'], true) && ! $trip) $warnings[] = 'Pro cestu doplňte dva termíny: /cesta Název | 2026-08-10 | 2026-08-14 | místo 1, místo 2';
        if (in_array($command['name'], ['/itinerář', '/itinerar'], true) && ! $itinerary) $warnings[] = 'Pro itinerář napište: /itinerář Název cesty | bod 1, bod 2, bod 3';

        return [
            'date' => $activityDate ?: now('Europe/Prague')->toDateString(),
            'activity_date' => $activityDate ?: now('Europe/Prague')->toDateString(),
            'activities' => array_values(array_unique($activities)),
            'titles' => array_values(array_unique($titles, SORT_REGULAR)),
            'recipe' => $recipe,
            'recipe_details' => $recipeDetails,
            'expense' => $expense,
            'trip' => $trip,
            'gift' => $gift,
            'milestone' => $milestone,
            'todo' => $todo,
            'itinerary' => $itinerary,
            'tags' => $tags,
            'search' => $command['name'] === '/hledat' ? $command['body'] : null,
            'warnings' => $warnings,
            'clarification' => $clarification,
        ];
    }

    private function todoItems(string $title, string $kind): array
    {
        $items = $kind === 'shopping'
            ? preg_split('/[,;]/u', $title)
            : [$title];

        return array_values(array_slice(array_unique(array_filter(array_map('trim', $items))), 0, 30));
    }
    /**
     * The `s` modifier matters: without it `.` stops at the first newline and `$` cannot
     * match, so a command followed by pasted multi-line text was not recognised as a
     * command at all. `body` therefore carries everything after the command, newlines
     * included; callers that need a single line take the first one themselves.
     */
    private function command(string $message): array
    {
        if (preg_match('/^\s*(\/[\p{L}]+)\s*(.*)$/su', $message, $match)) {
            return ['name' => mb_strtolower($match[1]), 'body' => trim($match[2])];
        }
        return ['name' => null, 'body' => null];
    }

    /** First line of a command body, for commands that name a single thing. */
    private function firstLine(?string $value): string
    {
        return trim(strtok((string) $value, "\n") ?: '');
    }

    private function parts(?string $value): array
    {
        return array_values(array_map('trim', explode('|', (string) $value)));
    }

    private function tripFromParts(array $parts, array $dates): ?array
    {
        $start = $this->dateFrom($parts[1] ?? null) ?: ($dates[0] ?? null);
        $end = $this->dateFrom($parts[2] ?? null) ?: ($dates[1] ?? null);
        if (! ($parts[0] ?? null) || ! $start || ! $end) return null;
        if (Carbon::parse($end, 'Europe/Prague')->lt(Carbon::parse($start, 'Europe/Prague'))) return null;
        $waypoints = isset($parts[3]) ? array_values(array_filter(array_map('trim', preg_split('/[,;]|→|->/u', $parts[3])))) : [];
        return ['name' => $parts[0], 'start_date' => $start, 'end_date' => $end, 'notes' => $parts[4] ?? null, 'waypoints' => $waypoints];
    }

    private function activityDateFrom(string $value, array $dates): ?string
    {
        $lower = mb_strtolower($value);
        $today = now('Europe/Prague')->startOfDay();
        if (str_contains($lower, 'předevčírem') || str_contains($lower, 'predevcirem')) return $today->copy()->subDays(2)->toDateString();
        if (str_contains($lower, 'včera') || str_contains($lower, 'vcera')) return $today->copy()->subDay()->toDateString();
        if (str_contains($lower, 'dnes')) return $today->toDateString();

        if (preg_match('/\b(\d{1,2})\.(\d{1,2})\.(\d{4})?\b/u', $value, $match)) {
            try {
                return Carbon::create((int) ($match[3] ?: $today->year), (int) $match[2], (int) $match[1], 0, 0, 0, 'Europe/Prague')->toDateString();
            } catch (\Throwable) {
                return null;
            }
        }

        return $dates[0] ?? null;
    }

    private function datesFrom(string $value): array
    {
        preg_match_all('/\b(?:\d{4}-\d{2}-\d{2}|\d{1,2}\.\d{1,2}\.(?:\d{4})?)\b/u', $value, $matches);
        return array_values(array_filter(array_map(fn ($date) => $this->dateFrom($date), $matches[0])));
    }

    private function dateFrom(?string $value): ?string
    {
        if (! $value) return null;
        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return Carbon::createFromFormat('Y-m-d', $value)->toDateString();
            if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})?$/', trim($value), $match)) {
                $year = $match[3] ?: now('Europe/Prague')->year;
                $date = Carbon::create($year, (int) $match[2], (int) $match[1], 0, 0, 0, 'Europe/Prague');
                if (! $match[3] && $date->lt(now('Europe/Prague')->startOfDay())) $date->addYear();
                return $date->toDateString();
            }
        } catch (\Throwable) {
            return null;
        }
        return null;
    }

    private function numberFrom(?string $value): ?float
    {
        if (! $value) return null;
        $number = (float) str_replace([' ', '. ', ','], ['', '', '.'], trim($value));
        return $number > 0 ? $number : null;
    }
}