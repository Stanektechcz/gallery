<?php

namespace App\Services\Travel;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TravelJournalStoryService
{
    /** Keep one generated journal block in the existing recap album privacy-safe and idempotent. */
    public function syncEntry(int $tripId, int $entryId): void
    {
        if (! Schema::hasColumn('albums', 'trip_id') || ! Schema::hasTable('album_story_blocks')) return;
        $albumId = DB::table('albums')->where('trip_id', $tripId)->whereNull('deleted_at')->value('id');
        if (! $albumId) return;

        $existing = DB::table('album_story_blocks')->where('album_id', $albumId)->get(['id', 'content'])->first(function ($block) use ($entryId) {
            $content = json_decode($block->content ?: '{}', true) ?: [];
            return ($content['source'] ?? null) === 'travel_journal' && (int) ($content['source_journal_entry_id'] ?? 0) === $entryId;
        });
        $query = DB::table('travel_journal_entries as entry')->join('users', 'users.id', '=', 'entry.user_id')
            ->where('entry.id', $entryId)->where('entry.trip_id', $tripId);
        if (Schema::hasTable('travel_journal_recordings')) {
            $query->leftJoin('travel_journal_recordings as recording', 'recording.journal_entry_id', '=', 'entry.id');
            $entry = $query->first(['entry.*', 'users.name as user_name', 'recording.uuid as recording_uuid', 'recording.duration_ms as recording_duration_ms', 'recording.mime_type as recording_mime_type']);
        } else {
            $entry = $query->first(['entry.*', 'users.name as user_name']);
        }
        $eligible = $entry && $entry->visibility === 'shared' && (bool) $entry->is_story_worthy && in_array($entry->type, ['note', 'voice', 'location'], true);
        if (! $eligible) {
            if ($existing) DB::table('album_story_blocks')->where('id', $existing->id)->delete();
            return;
        }

        [$type, $content] = $this->block($tripId, $entry);
        if ($existing) {
            DB::table('album_story_blocks')->where('id', $existing->id)->update(['type' => $type, 'content' => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_at' => now()]);
            return;
        }
        DB::table('album_story_blocks')->insert([
            'album_id' => $albumId, 'created_by' => $entry->user_id, 'type' => $type,
            'content' => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'sort_order' => ((int) DB::table('album_story_blocks')->where('album_id', $albumId)->max('sort_order')) + 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{string,array<string,mixed>} */
    private function block(int $tripId, object $entry): array
    {
        $source = ['source' => 'travel_journal', 'source_journal_entry_id' => (int) $entry->id, 'recorded_at' => $entry->recorded_at, 'mood' => $entry->mood];
        if ($entry->type === 'location' && $entry->latitude !== null && $entry->longitude !== null) {
            return ['map', $source + ['latitude' => (float) $entry->latitude, 'longitude' => (float) $entry->longitude, 'zoom' => 14, 'label' => $entry->content ?: 'Místo z cestovního deníku']];
        }
        $content = $source + ['quote' => $entry->content, 'author' => $entry->user_name];
        if ($entry->type === 'voice' && ! empty($entry->recording_uuid)) {
            $content += ['audio_url' => "/api/v1/trips/{$tripId}/journal/{$entry->id}/recording", 'audio_duration_ms' => (int) ($entry->recording_duration_ms ?? 0), 'audio_mime_type' => $entry->recording_mime_type ?? null];
        }
        return ['quote', $content];
    }
}
