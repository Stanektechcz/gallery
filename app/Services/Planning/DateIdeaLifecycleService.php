<?php

namespace App\Services\Planning;

use App\Models\CalendarEvent;
use App\Models\CoupleDateIdea;
use App\Models\CoupleDateIdeaReaction;
use App\Models\User;
use Illuminate\Support\Str;

class DateIdeaLifecycleService
{
    public function modelForEvent(CalendarEvent $event): ?CoupleDateIdea
    {
        return $this->findForEvent($event);
    }

    public function forEvent(CalendarEvent $event, ?User $viewer = null): ?array
    {
        $idea = $this->findForEvent($event);
        if (! $idea) return null;

        $idea->loadMissing(['reactions.user:id,name', 'event:id,uuid,status,starts_at']);
        $participantCount = max(1, $event->participants()->count());
        $ratedCount = $idea->reactions->whereNotNull('rating')->count();
        $average = $ratedCount ? round((float) $idea->reactions->whereNotNull('rating')->avg('rating'), 1) : null;

        return [
            'uuid' => $idea->uuid,
            'title' => $idea->title,
            'summary' => $idea->summary,
            'theme' => $idea->theme,
            'status' => $idea->status,
            'estimated_cost' => (float) $idea->estimated_cost,
            'currency' => $idea->currency,
            'estimated_minutes' => (int) $idea->estimated_minutes,
            'novelty_percent' => (int) $idea->novelty_percent,
            'travel_scope' => $idea->travel_scope,
            'transport_mode' => $idea->transport_mode,
            'destination' => $idea->destination,
            'parameters' => $idea->parameters,
            'plan' => $idea->plan,
            'reactions' => $idea->reactions->map(fn (CoupleDateIdeaReaction $reaction) => [
                'user_id' => $reaction->user_id,
                'user_name' => $reaction->user?->name,
                'reaction' => $reaction->reaction,
                'rating' => $reaction->rating,
                'note' => $reaction->note,
                'is_mine' => $viewer ? (int) $reaction->user_id === (int) $viewer->id : false,
            ])->values(),
            'my_reaction' => $viewer ? $idea->reactions->firstWhere('user_id', $viewer->id)?->reaction : null,
            'my_rating' => $viewer ? $idea->reactions->firstWhere('user_id', $viewer->id)?->rating : null,
            'my_note' => $viewer ? $idea->reactions->firstWhere('user_id', $viewer->id)?->note : null,
            'feedback' => [
                'rated_count' => $ratedCount,
                'participant_count' => $participantCount,
                'average_rating' => $average,
                'complete' => $ratedCount >= $participantCount,
            ],
        ];
    }

    public function recordReaction(CoupleDateIdea $idea, User $user, array $data): CoupleDateIdeaReaction
    {
        $reaction = CoupleDateIdeaReaction::updateOrCreate(
            ['date_idea_id' => $idea->id, 'user_id' => $user->id],
            $data,
        );
        $this->refreshStatus($idea->fresh(), array_key_exists('rating', $data) && $data['rating'] !== null);
        return $reaction;
    }

    public function recordEventReflection(CalendarEvent $event, User $user, array $reflection): void
    {
        $idea = $this->findForEvent($event);
        if (! $idea) return;

        $rating = isset($reflection['rating']) ? (int) $reflection['rating'] : null;
        $existing = $idea->reactions()->where('user_id', $user->id)->first();
        $reaction = $rating !== null
            ? ($rating >= 4 ? 'love' : ($rating === 3 ? 'maybe' : 'pass'))
            : ($existing?->reaction ?: 'maybe');
        $note = collect([$reflection['highlight'] ?? null, $reflection['next_time'] ?? null])->filter()->implode(' · Příště: ');

        $this->recordReaction($idea, $user, [
            'reaction' => $reaction,
            'rating' => $rating ?? $existing?->rating,
            'note' => $note !== '' ? Str::limit($note, 500, '') : $existing?->note,
        ]);
    }

    public function completeEvent(CalendarEvent $event): void
    {
        $idea = $this->findForEvent($event);
        if ($idea && $idea->status !== 'completed') $idea->update(['status' => 'completed']);
    }

    public function refreshStatus(CoupleDateIdea $idea, bool $hasNewRating = false): void
    {
        $idea->loadMissing('event');
        $eventIsPast = $idea->event && $idea->event->starts_at->lte(now());
        if ($idea->status === 'completed' || ($eventIsPast && ($hasNewRating || $idea->event->status === 'completed'))) {
            if ($idea->status !== 'completed') $idea->update(['status' => 'completed']);
            return;
        }
        if ($idea->calendar_event_id) {
            if ($idea->status !== 'planned') $idea->update(['status' => 'planned']);
            return;
        }

        $reactions = $idea->reactions()->pluck('reaction');
        $memberCount = $idea->space()->firstOrFail()->members()->count();
        $status = $reactions->contains('love') ? 'saved'
            : ($memberCount > 0 && $reactions->count() >= $memberCount && $reactions->every(fn ($value) => $value === 'pass') ? 'dismissed' : 'generated');
        if ($idea->status !== $status) $idea->update(['status' => $status]);
    }

    private function findForEvent(CalendarEvent $event): ?CoupleDateIdea
    {
        $metadata = $event->metadata ?? [];
        return CoupleDateIdea::query()
            ->where('gallery_space_id', $event->gallery_space_id)
            ->where(function ($query) use ($event, $metadata) {
                $query->where('calendar_event_id', $event->id);
                if (! empty($metadata['date_idea_uuid'])) $query->orWhere('uuid', $metadata['date_idea_uuid']);
            })->first();
    }
}
