<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AutomationRule;
use App\Models\GallerySpace;
use App\Services\Automation\AutomationEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The rules a space has written for itself.
 *
 * Separate from the built-in automations on purpose. Those are switches on maintenance the
 * app performs for everybody; these are authored, and can be wrong in ways a switch cannot.
 */
class AutomationRuleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $space = $this->space($request);

        return response()->json([
            'rules' => AutomationRule::where('gallery_space_id', $space->id)
                ->orderByDesc('id')->get()
                ->map(fn (AutomationRule $rule) => $this->payload($rule))->values(),
            // The catalogue travels with the list so the form can be built from it and
            // cannot offer a trigger the engine does not know.
            'triggers' => AutomationEngine::TRIGGERS,
            'actions' => AutomationEngine::ACTIONS,
            'operators' => AutomationEngine::OPERATORS,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $space = $this->space($request);
        $data = $this->validated($request);

        $rule = AutomationRule::create($data + [
            'gallery_space_id' => $space->id,
            'created_by' => $request->user()->id,
        ]);

        return response()->json($this->payload($rule), 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $rule = $this->find($request, $uuid);
        $rule->update($this->validated($request));

        return response()->json($this->payload($rule->fresh()));
    }

    /**
     * Deletes the rule, not what it made.
     *
     * Tasks and diary entries an automation created belong to the people who have been
     * living with them; removing the rule that produced them is not a request to erase
     * a fortnight of their list.
     */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $this->find($request, $uuid)->delete();

        return response()->json(['deleted' => true]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:120',
            'trigger' => ['required', Rule::in(array_keys(AutomationEngine::TRIGGERS))],
            'action' => ['required', Rule::in(array_keys(AutomationEngine::ACTIONS))],
            'is_enabled' => 'boolean',
            'conditions' => 'nullable|array|max:10',
            'conditions.*.field' => 'required|string|max:40',
            'conditions.*.operator' => ['required', Rule::in(AutomationEngine::OPERATORS)],
            'conditions.*.value' => 'nullable|string|max:200',
            'action_config' => 'nullable|array',
            'action_config.*' => 'nullable|string|max:500',
        ]);
    }

    private function find(Request $request, string $uuid): AutomationRule
    {
        $space = $this->space($request);

        return AutomationRule::where('gallery_space_id', $space->id)->where('uuid', $uuid)->firstOrFail();
    }

    private function space(Request $request): GallerySpace
    {
        $space = $request->user()->gallerySpaces()->first();
        abort_unless($space, 404, 'Prostor nebyl nalezen.');

        return $space;
    }

    private function payload(AutomationRule $rule): array
    {
        return [
            'uuid' => $rule->uuid,
            'name' => $rule->name,
            'trigger' => $rule->trigger,
            'action' => $rule->action,
            'conditions' => $rule->conditions ?? [],
            'action_config' => $rule->action_config ?? [],
            'is_enabled' => $rule->is_enabled,
            'run_count' => $rule->run_count,
            'last_run_at' => $rule->last_run_at?->toIso8601String(),
        ];
    }
}
