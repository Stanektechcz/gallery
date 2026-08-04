<?php

namespace App\Models\Concerns;

use App\Support\SpaceContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Confines every query to the gallery spaces the signed-in user belongs to.
 *
 * Scoping used to be the caller's job on all 123 tenant tables, and one forgotten
 * `whereIn` was a cross-tenant leak (CommentController loaded any MediaItem by uuid).
 * The scope is the floor; existing explicit `whereIn` calls stay as a second layer.
 *
 * The scope deliberately does nothing when there is no authenticated user — console
 * commands, queued jobs and seeders must still see everything. Operator-wide screens
 * opt out with `Model::withoutGlobalScope(SpaceContext::SCOPE)`.
 */
trait BelongsToGallerySpace
{
    protected static function bootBelongsToGallerySpace(): void
    {
        static::addGlobalScope(SpaceContext::SCOPE, function (Builder $query): void {
            $ids = SpaceContext::currentSpaceIds();
            if ($ids === null) return;   // no authenticated user: leave the query alone

            $query->whereIn($query->getModel()->getTable() . '.gallery_space_id', $ids);
        });
    }
}
