<?php

namespace Tests\Feature;

use App\Services\Chat\MentionSearchService;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class MentionRoutesTest extends TestCase
{
    /**
     * Every mention must lead somewhere that exists.
     *
     * This is here because it did not: calendar mentions pointed at /calendar/{uuid}
     * while the page lives at /calendar/events/{uuid}, so every plan someone mentioned
     * answered 404. A destination table that is written by hand needs something that
     * checks it against the router, or it drifts again the next time a route moves.
     */
    public function test_every_mention_destination_is_a_real_route(): void
    {
        $registered = collect(Route::getRoutes())
            ->map(fn ($route) => preg_replace('/\{[^}]+\}/', '{x}', $route->uri()))
            ->unique();

        foreach (MentionSearchService::ROUTES as $type => $pattern) {
            $uri = ltrim(str_replace('%s', '{x}', $pattern), '/');

            $this->assertTrue(
                $registered->contains($uri),
                "Zmínka typu '{$type}' míří na '{$pattern}', což není registrovaná routa.",
            );
        }
    }

    public function test_urls_are_built_from_the_table(): void
    {
        $this->assertSame('/calendar/events/abc', MentionSearchService::url('event', 'abc'));
        $this->assertSame('/trips/7/plan', MentionSearchService::url('trip', '7'));
        // A type with no placeholder ignores the id rather than appending it.
        $this->assertSame('/denik', MentionSearchService::url('journal', 'anything'));
        // An unknown type must not produce a broken link.
        $this->assertSame('/', MentionSearchService::url('vymysleny', '1'));
    }
}
