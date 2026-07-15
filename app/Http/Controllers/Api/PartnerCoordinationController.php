<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GallerySpace;
use App\Models\SharedTodo;
use App\Services\Planning\PartnerCoordinationService;
use App\Services\Planning\SharedTodoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PartnerCoordinationController extends Controller
{
    public function __construct(
        private readonly PartnerCoordinationService $coordination,
        private readonly SharedTodoService $todos,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'gallery_space_id' => 'nullable|integer',
            'limit' => 'nullable|integer|between:1,50',
        ]);
        $space = $this->space($request, isset($data['gallery_space_id']) ? (int) $data['gallery_space_id'] : null);

        return response()->json($this->coordination->snapshot($space, $request->user(), (int) ($data['limit'] ?? 12)));
    }

    public function updateAction(Request $request, string $type, string $key): JsonResponse
    {
        $this->write($request);
        abort_unless(in_array($type, PartnerCoordinationService::TYPES, true), 404);
        $data = $request->validate([
            'gallery_space_id' => 'required|integer',
            'completed' => 'sometimes|boolean',
            'assigned_to' => 'sometimes|nullable|integer',
            'snoozed_until' => 'sometimes|nullable|date|after:now',
        ]);
        $space = $this->space($request, (int) $data['gallery_space_id']);
        if (array_key_exists('assigned_to', $data) && $data['assigned_to'] !== null) {
            abort_unless($space->members()->whereKey($data['assigned_to'])->exists(), 422, 'Krok lze přiřadit pouze členovi společného prostoru.');
        }

        $this->updateSource($request, $space, $type, $key, $data);
        if (array_key_exists('snoozed_until', $data)) {
            abort_unless(Schema::hasTable('coordination_action_states'), 503, 'Pro odkládání kroků dokončete databázové migrace.');
            $lookup = ['gallery_space_id' => $space->id, 'user_id' => $request->user()->id, 'source_type' => $type, 'source_key' => $key];
            if ($data['snoozed_until'] === null) {
                DB::table('coordination_action_states')->where($lookup)->delete();
            } else {
                $existing = DB::table('coordination_action_states')->where($lookup)->first();
                DB::table('coordination_action_states')->updateOrInsert($lookup, [
                    'snoozed_until' => Carbon::parse($data['snoozed_until']),
                    'created_at' => $existing?->created_at ?? now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return response()->json($this->coordination->snapshot($space->fresh(), $request->user(), 20));
    }

    public function checkIn(Request $request): JsonResponse
    {
        $this->write($request);
        abort_unless(Schema::hasTable('partner_check_ins'), 503, 'Pro partnerský check-in dokončete databázové migrace.');
        $data = $request->validate([
            'gallery_space_id' => 'required|integer',
            'check_in_on' => 'nullable|date',
            'mood' => ['nullable', Rule::in(['joyful', 'calm', 'tired', 'stressed', 'excited', 'low'])],
            'energy' => 'nullable|integer|between:1,5',
            'capacity' => ['required', Rule::in(['unavailable', 'light', 'normal', 'high'])],
            'focus' => 'nullable|string|max:255',
            'note' => 'nullable|string|max:2000',
            'is_shared' => 'nullable|boolean',
        ]);
        $space = $this->space($request, (int) $data['gallery_space_id']);
        $day = Carbon::parse($data['check_in_on'] ?? now())->toDateString();
        abort_unless(Carbon::parse($day)->betweenIncluded(now()->subDays(7)->startOfDay(), now()->addDays(7)->endOfDay()), 422, 'Check-in lze uložit nejvýše týden zpětně nebo dopředu.');
        $lookup = ['gallery_space_id' => $space->id, 'user_id' => $request->user()->id, 'check_in_on' => $day];
        $existing = DB::table('partner_check_ins')->where($lookup)->first();
        DB::table('partner_check_ins')->updateOrInsert($lookup, [
            'uuid' => $existing?->uuid ?? (string) Str::uuid(),
            'mood' => $data['mood'] ?? null,
            'energy' => $data['energy'] ?? null,
            'capacity' => $data['capacity'],
            'focus' => $data['focus'] ?? null,
            'note' => $data['note'] ?? null,
            'is_shared' => $data['is_shared'] ?? true,
            'created_at' => $existing?->created_at ?? now(),
            'updated_at' => now(),
        ]);

        return response()->json($this->coordination->snapshot($space->fresh(), $request->user(), 20));
    }

    private function updateSource(Request $request, GallerySpace $space, string $type, string $key, array $data): void
    {
        $updates = ['updated_at' => now()];
        if (array_key_exists('assigned_to', $data)) $updates['assigned_to'] = $data['assigned_to'];
        if ($type === 'shared_todo') {
            $todo = SharedTodo::where('uuid', $key)->where('gallery_space_id', $space->id)->firstOrFail();
            if (array_key_exists('completed', $data)) $this->todos->complete($todo, $request->user(), (bool) $data['completed']);
            if (array_key_exists('assigned_to', $data)) $todo->update(['assigned_to' => $data['assigned_to']]);
            return;
        }
        if ($type === 'event_task') {
            $task = DB::table('event_tasks as task')->join('calendar_events as event', 'event.id', '=', 'task.event_id')
                ->where('task.id', $key)->where('event.gallery_space_id', $space->id)
                ->where(function ($visible) use ($request) {
                    $visible->where('event.is_private', false)->orWhere('event.created_by', $request->user()->id)
                        ->orWhereExists(fn ($participants) => $participants->selectRaw('1')->from('event_participants as participant')
                            ->whereColumn('participant.event_id', 'event.id')->where('participant.user_id', $request->user()->id));
                })->select('task.id')->first();
            abort_unless($task, 404);
            if (array_key_exists('completed', $data)) $updates['completed_at'] = $data['completed'] ? now() : null;
            DB::table('event_tasks')->where('id', $task->id)->update($updates);
            return;
        }
        if ($type === 'packing_item') {
            $item = DB::table('trip_packing_items as item')->join('trips as trip', 'trip.id', '=', 'item.trip_id')
                ->where('item.id', $key)->where('trip.gallery_space_id', $space->id)->select('item.id')->first();
            abort_unless($item, 404);
            if (array_key_exists('completed', $data)) {
                $updates['is_packed'] = $data['completed'];
                $updates['packed_at'] = $data['completed'] ? now() : null;
                if (Schema::hasColumn('trip_packing_items', 'packed_by')) $updates['packed_by'] = $data['completed'] ? $request->user()->id : null;
            }
            DB::table('trip_packing_items')->where('id', $item->id)->update($updates);
            return;
        }
        if ($type === 'planning_item') {
            $item = DB::table('travel_inbox_items')->where('uuid', $key)->where('gallery_space_id', $space->id)->first();
            abort_unless($item, 404);
            if (array_key_exists('completed', $data)) $updates['state'] = $data['completed'] ? 'archived' : 'inbox';
            DB::table('travel_inbox_items')->where('id', $item->id)->update($updates);
            return;
        }
        if ($type === 'trip_document') {
            $document = DB::table('trip_document_checks as document')->join('trips as trip', 'trip.id', '=', 'document.trip_id')
                ->where('document.id', $key)->where('trip.gallery_space_id', $space->id)->select('document.id')->first();
            abort_unless($document, 404);
            if (array_key_exists('completed', $data)) $updates['status'] = $data['completed'] ? 'ready' : 'required';
            DB::table('trip_document_checks')->where('id', $document->id)->update($updates);
            return;
        }
        $gift = DB::table('gift_ideas')->where('uuid', $key)->where('gallery_space_id', $space->id)->first();
        abort_unless($gift, 404);
        if (array_key_exists('completed', $data)) $updates['status'] = $data['completed'] ? 'purchased' : 'idea';
        DB::table('gift_ideas')->where('id', $gift->id)->update($updates);
    }

    private function space(Request $request, ?int $id): GallerySpace
    {
        $query = GallerySpace::query()->whereHas('members', fn ($members) => $members->whereKey($request->user()->id));
        if ($id) return $query->findOrFail($id);
        return $query->orderByDesc('is_default')->orderBy('id')->firstOrFail();
    }

    private function write(Request $request): void
    {
        abort_if($request->user()->read_only_mode, 403, 'V režimu pouze pro čtení nelze partnerský plán měnit.');
    }
}
