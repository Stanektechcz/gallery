<?php

namespace App\Services\Automation;

use App\Models\AutomationRule;
use App\Models\GallerySpace;
use App\Models\JournalEntry;
use App\Models\SharedTodo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Runs the rules people wrote: when this happens, and these things hold, do that.
 *
 * Three deliberate limits, each because the alternative is worse:
 *
 *   A rule never triggers another rule. Chaining is the feature that turns one mistake
 *   into a loop that fills somebody's list overnight, and nothing anyone has asked for
 *   needs it.
 *
 *   A failing action is logged and the next rule still runs. One bad rule must not stop
 *   the others, and it must never take down the thing that fired it — an automation that
 *   breaks uploading a photo is worse than an automation that quietly does not run.
 *
 *   Triggers and actions are a fixed catalogue, not free text. Anything a rule can reach
 *   is something written here on purpose.
 */
class AutomationEngine
{
    /** Guards against a rule triggering itself through the thing it just created. */
    private bool $running = false;

    /** Schema::hasTable is a query; a create-heavy import would otherwise repeat it. */
    private ?bool $tableExists = null;

    public const TRIGGERS = [
        'event.created' => [
            'label' => 'Přidána událost do kalendáře',
            'fields' => ['title' => 'Název', 'location' => 'Místo', 'days_ahead' => 'Za kolik dní'],
        ],
        'media.uploaded' => [
            'label' => 'Nahrána fotka nebo video',
            'fields' => ['filename' => 'Název souboru', 'media_type' => 'Typ (photo/video)'],
        ],
        'todo.completed' => [
            'label' => 'Dokončen úkol',
            'fields' => ['title' => 'Název úkolu'],
        ],
    ];

    public const ACTIONS = [
        'todo.create' => [
            'label' => 'Vytvořit úkol',
            'config' => ['title' => 'Název úkolu', 'due_in_days' => 'Termín za dní'],
        ],
        'journal.entry' => [
            'label' => 'Zapsat do deníku',
            'config' => ['title' => 'Nadpis', 'body' => 'Text'],
        ],
    ];

    /**
     * Everything a rule may compare with. Kept small on purpose: an operator nobody can
     * explain in four words is an operator nobody will use correctly.
     */
    public const OPERATORS = ['contains', 'equals', 'not_contains', 'greater_than', 'less_than'];

    /**
     * @param array<string, mixed> $payload the trigger's fields, per TRIGGERS
     */
    public function fire(string $trigger, GallerySpace $space, array $payload): int
    {
        if ($this->running) return 0;
        if (! isset(self::TRIGGERS[$trigger])) return 0;
        $this->tableExists ??= Schema::hasTable('automation_rules');
        if (! $this->tableExists) return 0;

        $rules = AutomationRule::where('gallery_space_id', $space->id)
            ->where('trigger', $trigger)
            ->where('is_enabled', true)
            ->get();

        if ($rules->isEmpty()) return 0;

        $this->running = true;
        $ran = 0;

        try {
            foreach ($rules as $rule) {
                if (! $this->matches($rule, $payload)) continue;

                try {
                    $this->perform($rule, $space, $payload);
                    $rule->forceFill([
                        'last_run_at' => now(),
                        'run_count' => $rule->run_count + 1,
                    ])->save();
                    $ran++;
                } catch (\Throwable $e) {
                    // Logged, not thrown: the upload that fired this must still succeed.
                    Log::warning('Automatizace selhala', [
                        'rule' => $rule->uuid, 'action' => $rule->action, 'error' => $e->getMessage(),
                    ]);
                }
            }
        } finally {
            $this->running = false;
        }

        return $ran;
    }

    /** Every condition must hold. A rule with none always matches — that is what "always" means. */
    private function matches(AutomationRule $rule, array $payload): bool
    {
        foreach ((array) $rule->conditions as $condition) {
            $field = $condition['field'] ?? null;
            $operator = $condition['operator'] ?? 'contains';
            $expected = $condition['value'] ?? '';
            $actual = $payload[$field] ?? null;

            if ($field === null) continue;
            if (! $this->compare($operator, $actual, $expected)) return false;
        }

        return true;
    }

    private function compare(string $operator, mixed $actual, mixed $expected): bool
    {
        // Absent is not empty: a rule asking whether a title contains something must not
        // match an event that has no title at all.
        if ($actual === null) return false;

        return match ($operator) {
            'equals' => mb_strtolower((string) $actual) === mb_strtolower((string) $expected),
            'contains' => Str::contains(mb_strtolower((string) $actual), mb_strtolower((string) $expected)),
            'not_contains' => ! Str::contains(mb_strtolower((string) $actual), mb_strtolower((string) $expected)),
            'greater_than' => is_numeric($actual) && is_numeric($expected) && $actual > $expected,
            'less_than' => is_numeric($actual) && is_numeric($expected) && $actual < $expected,
            default => false,
        };
    }

    private function perform(AutomationRule $rule, GallerySpace $space, array $payload): void
    {
        $config = (array) $rule->action_config;

        match ($rule->action) {
            'todo.create' => $this->createTodo($rule, $space, $config, $payload),
            'journal.entry' => $this->createJournalEntry($rule, $space, $config, $payload),
            default => throw new \RuntimeException('Neznámá akce ' . $rule->action),
        };
    }

    private function createTodo(AutomationRule $rule, GallerySpace $space, array $config, array $payload): void
    {
        SharedTodo::create([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $space->id,
            'created_by' => $rule->created_by,
            'title' => $this->fill($config['title'] ?? 'Úkol z automatizace', $payload),
            'status' => 'open',
            'due_at' => isset($config['due_in_days']) && $config['due_in_days'] !== ''
                ? now()->addDays((int) $config['due_in_days'])
                : null,
            // So somebody looking at a task they did not write can tell where it came from.
            'created_from' => 'automation',
            'source_reference' => $rule->uuid,
        ]);
    }

    private function createJournalEntry(AutomationRule $rule, GallerySpace $space, array $config, array $payload): void
    {
        JournalEntry::create([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $space->id,
            'created_by' => $rule->created_by,
            'title' => $this->fill($config['title'] ?? 'Zápisek z automatizace', $payload),
            'body' => $this->fill($config['body'] ?? '', $payload),
            'entry_date' => now()->toDateString(),
            'visibility' => 'private',
        ]);
    }

    /**
     * Substitutes {title} and friends from the trigger.
     *
     * Only the trigger's own fields, and only as plain text — this is a template for a
     * task title, not an expression language, and the moment it becomes one it becomes
     * something that has to be made safe.
     */
    private function fill(string $template, array $payload): string
    {
        foreach ($payload as $key => $value) {
            if (is_scalar($value)) {
                $template = str_replace('{' . $key . '}', (string) $value, $template);
            }
        }

        return Str::limit(trim($template), 200, '');
    }
}
