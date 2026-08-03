<?php

namespace App\Services\Planning;

use App\Models\GallerySpace;

class AutomationRegistryService
{
    public const AUTO_COMPLETE_ELAPSED = 'auto_complete_elapsed_events';
    public const PLANNING_FOLLOWUPS = 'planning_followups';
    public const RELATIONSHIP_MILESTONES = 'relationship_milestones';

    public function definition(string $key): array
    {
        $definitions = [
            self::AUTO_COMPLETE_ELAPSED => ['key' => self::AUTO_COMPLETE_ELAPSED, 'title' => 'Automatické dokončení minulých plánů', 'description' => 'Po termínu uzavře nevyplněné společné akce a jejich nedokončené přípravy, aby se nezobrazovaly v aktuálním týdnu.', 'schedule' => 'Každý den v 00:05', 'condition' => 'Akce skončila a nebyla ručně ponechána otevřená.'],
            self::PLANNING_FOLLOWUPS => ['key' => self::PLANNING_FOLLOWUPS, 'title' => 'Připomínky nedokončeného plánování', 'description' => 'Upozorní na úkoly po termínu, úkoly splatné během následujících 24 hodin a blížící se dárky. Stejnou položku znovu neodesílá dříve než za den.', 'schedule' => 'Každou hodinu', 'condition' => 'Existuje otevřený úkol s termínem nebo dárek s nastaveným dnem připomínky.'],
            self::RELATIONSHIP_MILESTONES => ['key' => self::RELATIONSHIP_MILESTONES, 'title' => 'Připomínky výročí a narozenin', 'description' => 'Připomene nastavená výročí a narozeniny podle jejich individuálních předstihů. Soukromé položky zůstávají viditelné jen svému autorovi.', 'schedule' => 'Každý den v 09:00', 'condition' => 'Dnes odpovídá některému z nastavených dnů před výročním datem.'],
        ];

        abort_unless(isset($definitions[$key]), 404);
        return $definitions[$key];
    }

    public function items(GallerySpace $space): array
    {
        return array_map(fn (string $key) => $this->item($space, $key), array_keys($this->definitions()));
    }

    public function item(GallerySpace $space, string $key = self::AUTO_COMPLETE_ELAPSED): array
    {
        $definition = $this->definition($key);
        $state = (array) (($space->settings ?? [])['automation_registry'][$key] ?? []);
        return $definition + ['enabled' => array_key_exists('enabled', $state) ? (bool) $state['enabled'] : true, 'last_run_at' => $state['last_run_at'] ?? null];
    }

    public function enabled(GallerySpace $space, string $key = self::AUTO_COMPLETE_ELAPSED): bool
    {
        $this->definition($key);
        $state = (array) (($space->settings ?? [])['automation_registry'][$key] ?? []);
        return !array_key_exists('enabled', $state) || (bool) $state['enabled'];
    }

    public function setEnabled(GallerySpace $space, string $key, bool $enabled): void
    {
        $this->definition($key);
        $settings = $space->settings ?? [];
        $settings['automation_registry'][$key] = array_merge((array) ($settings['automation_registry'][$key] ?? []), ['enabled' => $enabled]);
        $space->update(['settings' => $settings]);
    }

    public function markRan(GallerySpace $space, string $key = self::AUTO_COMPLETE_ELAPSED): void
    {
        $this->definition($key);
        $settings = $space->settings ?? [];
        $settings['automation_registry'][$key] = array_merge((array) ($settings['automation_registry'][$key] ?? []), ['last_run_at' => now('Europe/Prague')->toIso8601String()]);
        $space->update(['settings' => $settings]);
    }

    private function definitions(): array
    {
        return [self::AUTO_COMPLETE_ELAPSED, self::PLANNING_FOLLOWUPS, self::RELATIONSHIP_MILESTONES];
    }
}
