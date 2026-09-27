<?php

namespace Tests\Feature;

use App\Services\Chat\MentionSearchService;
use App\Support\TrasyPrototypu;
use Tests\TestCase;

class MentionRoutesTest extends TestCase
{
    /**
     * Every mention must lead somewhere that exists.
     *
     * This is here because it did not: calendar mentions pointed at /calendar/{uuid}
     * while the page lived at /calendar/events/{uuid}, so every plan someone mentioned
     * answered 404. Od 27. 9. 2026 zmínky vedou na obrazovky aplikace, takže se
     * tabulka kontroluje proti `TrasyPrototypu`, ne proti starým cestám.
     */
    public function test_every_mention_destination_is_a_real_route(): void
    {
        foreach (MentionSearchService::ROUTES as $type => $trasa) {
            $this->assertNotNull(
                TrasyPrototypu::adresa($trasa),
                "Zmínka typu '{$type}' míří na obrazovku '{$trasa}', kterou aplikace nezná.",
            );
        }
    }

    public function test_urls_are_built_from_the_table(): void
    {
        $this->assertSame('/galerie/kalendar', MentionSearchService::url('event', 'abc'));
        $this->assertSame('/galerie/cesty', MentionSearchService::url('trip', '7'));
        $this->assertSame('/galerie/denik', MentionSearchService::url('journal', 'anything'));
        // An unknown type must not produce a broken link.
        $this->assertSame('/', MentionSearchService::url('vymysleny', '1'));
    }
}
