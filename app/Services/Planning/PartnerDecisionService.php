<?php

namespace App\Services\Planning;

use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PartnerDecisionService
{
    public const TYPES = ['date_idea', 'entertainment_title', 'viewing_date', 'poll'];

    public function snapshot(GallerySpace $space, User $viewer, int $limit = 12): array
    {
        $available = [
            'date_ideas' => Schema::hasTable('couple_date_ideas') && Schema::hasTable('couple_date_idea_reactions'),
            'watchlist' => Schema::hasTable('entertainment_titles') && Schema::hasTable('entertainment_votes'),
            'viewing_dates' => Schema::hasTable('viewing_date_proposals') && Schema::hasTable('viewing_proposal_votes'),
            'polls' => Schema::hasTable('decision_polls') && Schema::hasTable('decision_poll_options') && Schema::hasTable('decision_poll_votes'),
        ];

        $items = collect()
            ->concat($available['viewing_dates'] ? $this->viewingDates($space, $viewer) : [])
            ->concat($available['polls'] ? $this->polls($space, $viewer) : [])
            ->concat($available['date_ideas'] ? $this->dateIdeas($space, $viewer) : [])
            ->concat($available['watchlist'] ? $this->entertainment($space, $viewer) : [])
            ->sort(function (array $left, array $right): int {
                $priority = ($left['_priority'] ?? 9) <=> ($right['_priority'] ?? 9);
                if ($priority !== 0) return $priority;
                return ($left['_sort_at'] ?? PHP_INT_MAX) <=> ($right['_sort_at'] ?? PHP_INT_MAX);
            })->values();

        $visible = $items->take(max(1, min(30, $limit)))
            ->map(fn (array $item) => collect($item)->except(['_priority', '_sort_at'])->all())->values();

        return [
            'space' => ['id' => $space->id, 'name' => $space->name],
            'items' => $visible,
            'summary' => [
                'total' => $items->count(),
                'date_ideas' => $items->where('type', 'date_idea')->count(),
                'watchlist' => $items->whereIn('type', ['entertainment_title', 'viewing_date'])->count(),
                'polls' => $items->where('type', 'poll')->count(),
            ],
            'available_sources' => $available,
            'partially_available' => collect($available)->contains(false),
        ];
    }

    private function dateIdeas(GallerySpace $space, User $viewer): Collection
    {
        return DB::table('couple_date_ideas as idea')
            ->leftJoin('couple_date_idea_reactions as mine', function ($join) use ($viewer) {
                $join->on('mine.date_idea_id', '=', 'idea.id')->where('mine.user_id', '=', $viewer->id);
            })
            ->where('idea.gallery_space_id', $space->id)->whereIn('idea.status', ['generated', 'saved'])
            ->whereNull('idea.calendar_event_id')->whereNull('mine.id')->latest('idea.created_at')->limit(20)
            ->get(['idea.uuid', 'idea.title', 'idea.summary', 'idea.theme', 'idea.estimated_cost', 'idea.currency', 'idea.estimated_minutes', 'idea.suggested_starts_at', 'idea.created_at'])
            ->map(fn ($idea) => [
                'key' => 'date-idea-' . $idea->uuid, 'type' => 'date_idea', 'source_key' => $idea->uuid,
                'title' => $idea->title, 'description' => $idea->summary,
                'context' => 'Randíčko · ' . number_format((float) $idea->estimated_cost, 0, ',', ' ') . ' ' . $idea->currency . ' · ' . (int) $idea->estimated_minutes . ' min',
                'due_at' => $idea->suggested_starts_at, 'href' => '/date-ideas', 'accent' => 'pink',
                'options' => [
                    ['value' => 'love', 'label' => '❤️ Chci', 'tone' => 'positive'],
                    ['value' => 'maybe', 'label' => 'Možná', 'tone' => 'neutral'],
                    ['value' => 'pass', 'label' => 'Teď ne', 'tone' => 'negative'],
                ],
                '_priority' => $idea->suggested_starts_at ? 2 : 3,
                '_sort_at' => $idea->suggested_starts_at ? strtotime($idea->suggested_starts_at) : strtotime($idea->created_at),
            ]);
    }

    private function entertainment(GallerySpace $space, User $viewer): Collection
    {
        return DB::table('entertainment_titles as title')
            ->leftJoin('entertainment_votes as mine', function ($join) use ($viewer) {
                $join->on('mine.entertainment_title_id', '=', 'title.id')->where('mine.user_id', '=', $viewer->id);
            })
            ->where('title.gallery_space_id', $space->id)->whereIn('title.status', ['proposed', 'shortlisted'])
            ->whereNull('mine.id')->latest('title.created_at')->limit(20)
            ->get(['title.uuid', 'title.title', 'title.media_type', 'title.overview', 'title.runtime_minutes', 'title.release_year', 'title.poster_url', 'title.created_at'])
            ->map(fn ($title) => [
                'key' => 'entertainment-' . $title->uuid, 'type' => 'entertainment_title', 'source_key' => $title->uuid,
                'title' => $title->title, 'description' => $title->overview,
                'context' => ($title->media_type === 'series' ? 'Seriál' : 'Film')
                    . ($title->release_year ? ' · ' . $title->release_year : '')
                    . ($title->runtime_minutes ? ' · ' . $title->runtime_minutes . ' min' : ''),
                'cover_url' => $title->poster_url, 'href' => '/watchlist', 'accent' => 'violet',
                'options' => [
                    ['value' => 'love', 'label' => 'Chci vidět', 'tone' => 'positive'],
                    ['value' => 'maybe', 'label' => 'Možná', 'tone' => 'neutral'],
                    ['value' => 'pass', 'label' => 'Přeskočit', 'tone' => 'negative'],
                ],
                '_priority' => 4, '_sort_at' => strtotime($title->created_at),
            ]);
    }

    private function viewingDates(GallerySpace $space, User $viewer): Collection
    {
        return DB::table('viewing_date_proposals as proposal')
            ->join('entertainment_titles as title', 'title.id', '=', 'proposal.entertainment_title_id')
            ->leftJoin('viewing_proposal_votes as mine', function ($join) use ($viewer) {
                $join->on('mine.viewing_date_proposal_id', '=', 'proposal.id')->where('mine.user_id', '=', $viewer->id);
            })
            ->where('title.gallery_space_id', $space->id)->where('proposal.status', 'proposed')
            ->where('proposal.starts_at', '>', now())->whereNull('mine.id')->orderBy('proposal.starts_at')->limit(20)
            ->get(['proposal.uuid', 'proposal.starts_at', 'proposal.venue', 'proposal.place_name', 'proposal.note', 'title.title'])
            ->map(fn ($proposal) => [
                'key' => 'viewing-date-' . $proposal->uuid, 'type' => 'viewing_date', 'source_key' => $proposal->uuid,
                'title' => 'Termín pro „' . $proposal->title . '“', 'description' => $proposal->note,
                'context' => $proposal->venue === 'cinema' ? 'Kino · ' . ($proposal->place_name ?: 'Cinema City') : 'Filmový večer doma',
                'due_at' => $proposal->starts_at, 'href' => '/watchlist', 'accent' => 'violet',
                'options' => [
                    ['value' => 'yes', 'label' => 'Termín mi sedí', 'tone' => 'positive'],
                    ['value' => 'maybe', 'label' => 'Možná', 'tone' => 'neutral'],
                    ['value' => 'no', 'label' => 'Nemohu', 'tone' => 'negative'],
                ],
                '_priority' => 0, '_sort_at' => strtotime($proposal->starts_at),
            ]);
    }

    private function polls(GallerySpace $space, User $viewer): Collection
    {
        $polls = DB::table('decision_polls as poll')
            ->where('poll.gallery_space_id', $space->id)->where('poll.status', 'open')
            ->where(fn ($query) => $query->whereNull('poll.closes_at')->orWhere('poll.closes_at', '>', now()))
            ->whereNotExists(function ($query) use ($viewer) {
                $query->selectRaw('1')->from('decision_poll_votes as mine')
                    ->join('decision_poll_options as voted_option', 'voted_option.id', '=', 'mine.poll_option_id')
                    ->whereColumn('voted_option.poll_id', 'poll.id')->where('mine.user_id', $viewer->id);
            })->orderByRaw('CASE WHEN poll.closes_at IS NULL THEN 1 ELSE 0 END')->orderBy('poll.closes_at')->latest('poll.created_at')->limit(20)
            ->get(['poll.id', 'poll.uuid', 'poll.question', 'poll.closes_at', 'poll.created_at']);
        $options = DB::table('decision_poll_options')->whereIn('poll_id', $polls->pluck('id'))
            ->orderBy('sort_order')->get(['id', 'poll_id', 'title'])->groupBy('poll_id');

        return $polls->map(fn ($poll) => [
            'key' => 'poll-' . $poll->uuid, 'type' => 'poll', 'source_key' => $poll->uuid,
            'title' => $poll->question, 'context' => 'Společné hlasování', 'due_at' => $poll->closes_at,
            'href' => '/planning', 'accent' => 'teal',
            'options' => $options->get($poll->id, collect())->map(fn ($option) => [
                'value' => (string) $option->id, 'label' => $option->title, 'tone' => 'choice',
            ])->values()->all(),
            '_priority' => 1, '_sort_at' => $poll->closes_at ? strtotime($poll->closes_at) : strtotime($poll->created_at),
        ]);
    }
}
