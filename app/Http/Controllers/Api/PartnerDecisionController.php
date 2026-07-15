<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CoupleDateIdea;
use App\Models\EntertainmentTitle;
use App\Models\EntertainmentVote;
use App\Models\GallerySpace;
use App\Services\Planning\DateIdeaLifecycleService;
use App\Services\Planning\PartnerDecisionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PartnerDecisionController extends Controller
{
    public function __construct(
        private readonly PartnerDecisionService $decisions,
        private readonly DateIdeaLifecycleService $dateIdeas,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['gallery_space_id' => 'nullable|integer', 'limit' => 'nullable|integer|between:1,30']);
        $space = $this->space($request, isset($data['gallery_space_id']) ? (int) $data['gallery_space_id'] : null);

        return response()->json($this->decisions->snapshot($space, $request->user(), (int) ($data['limit'] ?? 12)));
    }

    public function respond(Request $request, string $type, string $key): JsonResponse
    {
        abort_if($request->user()->read_only_mode, 403, 'V režimu pouze pro čtení nelze společná rozhodnutí měnit.');
        abort_unless(in_array($type, PartnerDecisionService::TYPES, true), 404);
        $data = $request->validate([
            'gallery_space_id' => 'required|integer',
            'response' => 'required|string|max:64',
        ]);
        $space = $this->space($request, (int) $data['gallery_space_id']);

        match ($type) {
            'date_idea' => $this->respondToDateIdea($request, $space, $key, $data['response']),
            'entertainment_title' => $this->respondToEntertainment($request, $space, $key, $data['response']),
            'viewing_date' => $this->respondToViewingDate($request, $space, $key, $data['response']),
            'poll' => $this->respondToPoll($request, $space, $key, $data['response']),
        };

        return response()->json($this->decisions->snapshot($space, $request->user(), 20));
    }

    private function respondToDateIdea(Request $request, GallerySpace $space, string $key, string $response): void
    {
        abort_unless(in_array($response, ['love', 'maybe', 'pass'], true), 422, 'Neplatná reakce na randíčko.');
        $idea = CoupleDateIdea::where('uuid', $key)->where('gallery_space_id', $space->id)
            ->whereIn('status', ['generated', 'saved'])->firstOrFail();
        $this->dateIdeas->recordReaction($idea, $request->user(), ['reaction' => $response]);
    }

    private function respondToEntertainment(Request $request, GallerySpace $space, string $key, string $response): void
    {
        $interest = ['love' => 5, 'maybe' => 3, 'pass' => 1][$response] ?? null;
        abort_unless($interest, 422, 'Neplatná reakce na film nebo seriál.');
        $title = EntertainmentTitle::where('uuid', $key)->where('gallery_space_id', $space->id)
            ->whereIn('status', ['proposed', 'shortlisted'])->firstOrFail();
        EntertainmentVote::updateOrCreate(
            ['entertainment_title_id' => $title->id, 'user_id' => $request->user()->id],
            ['interest' => $interest, 'cinema_preferred' => false],
        );
    }

    private function respondToViewingDate(Request $request, GallerySpace $space, string $key, string $response): void
    {
        abort_unless(in_array($response, ['yes', 'maybe', 'no'], true), 422, 'Neplatná odpověď na navržený termín.');
        $proposal = DB::table('viewing_date_proposals as proposal')
            ->join('entertainment_titles as title', 'title.id', '=', 'proposal.entertainment_title_id')
            ->where('proposal.uuid', $key)->where('title.gallery_space_id', $space->id)
            ->where('proposal.status', 'proposed')->where('proposal.starts_at', '>', now())
            ->select('proposal.id')->firstOrFail();
        DB::table('viewing_proposal_votes')->updateOrInsert(
            ['viewing_date_proposal_id' => $proposal->id, 'user_id' => $request->user()->id],
            ['response' => $response, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    private function respondToPoll(Request $request, GallerySpace $space, string $key, string $response): void
    {
        abort_unless(ctype_digit($response), 422, 'Vyberte jednu z nabízených možností.');
        $poll = DB::table('decision_polls')->where('uuid', $key)->where('gallery_space_id', $space->id)->where('status', 'open')->firstOrFail();
        abort_if($poll->closes_at && Carbon::parse($poll->closes_at)->isPast(), 422, 'Hlasování již skončilo.');
        $option = DB::table('decision_poll_options')->where('id', (int) $response)->where('poll_id', $poll->id)->firstOrFail();
        DB::transaction(function () use ($request, $poll, $option): void {
            $optionIds = DB::table('decision_poll_options')->where('poll_id', $poll->id)->pluck('id');
            DB::table('decision_poll_votes')->where('user_id', $request->user()->id)->whereIn('poll_option_id', $optionIds)->delete();
            DB::table('decision_poll_votes')->insert([
                'poll_option_id' => $option->id, 'user_id' => $request->user()->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    private function space(Request $request, ?int $id): GallerySpace
    {
        $query = GallerySpace::query()->whereHas('members', fn ($members) => $members->whereKey($request->user()->id));
        if ($id) return $query->findOrFail($id);
        return $query->orderByDesc('is_default')->orderBy('id')->firstOrFail();
    }
}
