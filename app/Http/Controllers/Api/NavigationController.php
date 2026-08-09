<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserNavigationItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Reading and writing one person's arrangement of the navigation.
 *
 * The whole arrangement is saved at once rather than item by item. Dragging a menu about
 * produces a dozen changes that only make sense together — saving them one at a time
 * would leave a half-reordered menu behind any failure.
 */
class NavigationController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        if (! Schema::hasTable('user_navigation_items')) {
            return response()->json(['items' => [], 'customised' => false]);
        }

        $items = UserNavigationItem::where('user_id', $request->user()->id)
            ->orderBy('position')->get();

        return response()->json([
            // Empty means "use the built-in navigation", which is the common case.
            'customised' => $items->isNotEmpty(),
            'items' => $items->map(fn (UserNavigationItem $row) => [
                'uuid' => $row->uuid,
                'href' => $row->href,
                'label' => $row->label,
                'icon' => $row->icon,
                'parent' => $row->parent_id
                    ? $items->firstWhere('id', $row->parent_id)?->uuid
                    : null,
                'position' => $row->position,
                'hidden' => (bool) $row->is_hidden,
                'group' => (bool) $row->is_group,
            ])->values(),
        ]);
    }

    /**
     * Replaces the arrangement wholesale.
     *
     * Parents are referenced by their position in the submitted list rather than by an id
     * the client cannot know for rows that do not exist yet, so a new heading and the
     * items nested under it arrive in one request.
     */
    public function update(Request $request): JsonResponse
    {
        abort_unless(Schema::hasTable('user_navigation_items'), 503, 'Pro úpravy menu dokončete databázové migrace.');
        abort_if($request->user()->read_only_mode, 403, 'V režimu pouze pro čtení nelze upravovat menu.');

        $data = $request->validate([
            'items' => 'present|array|max:200',
            'items.*.href' => 'nullable|string|max:190',
            'items.*.label' => 'nullable|string|max:120',
            'items.*.icon' => 'nullable|string|max:16',
            // Index into this same array; null for a top-level item.
            'items.*.parent' => 'nullable|integer|min:0',
            'items.*.hidden' => 'nullable|boolean',
            'items.*.group' => 'nullable|boolean',
        ]);

        $user = $request->user();

        DB::transaction(function () use ($user, $data) {
            UserNavigationItem::where('user_id', $user->id)->delete();

            // Two passes: rows first, then parents, because a parent may appear after its
            // child in the submitted order and neither has an id until it is written.
            $created = [];

            foreach ($data['items'] as $position => $item) {
                $created[$position] = UserNavigationItem::create([
                    'uuid' => (string) Str::uuid(),
                    'user_id' => $user->id,
                    'href' => $item['href'] ?? null,
                    'label' => $item['label'] ?? null,
                    'icon' => $item['icon'] ?? null,
                    'position' => $position,
                    'is_hidden' => (bool) ($item['hidden'] ?? false),
                    'is_group' => (bool) ($item['group'] ?? false),
                ]);
            }

            foreach ($data['items'] as $position => $item) {
                $parent = $item['parent'] ?? null;
                // A row cannot be its own parent, and nesting stops at one level.
                if ($parent === null || $parent === $position || ! isset($created[$parent])) continue;
                if ($created[$parent]->parent_id !== null) continue;

                $created[$position]->forceFill(['parent_id' => $created[$parent]->id])->save();
            }
        });

        return $this->show($request);
    }

    /** Back to the built-in navigation: the rows go, the items do not. */
    public function reset(Request $request): JsonResponse
    {
        abort_unless(Schema::hasTable('user_navigation_items'), 503, 'Pro úpravy menu dokončete databázové migrace.');

        UserNavigationItem::where('user_id', $request->user()->id)->delete();

        return response()->json(['customised' => false, 'items' => []]);
    }
}
