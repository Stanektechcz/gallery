<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Vchodem je od nasazení prototypu prototyp — má vlastní zámek, takže adresa
     * zůstává veřejná a přihlašuje se až uvnitř. Nabídka služby bývala i na
     * `/prehled`; ten od 27. 9. 2026 vede do aplikace (`PresmerujStareRozhrani`)
     * a nabídka zůstává na své veřejné adrese `/sluzba`.
     */
    public function test_the_front_page_shows_the_service_to_a_visitor(): void
    {
        $this->get('/prehled')->assertRedirect('/');

        $this->get('/sluzba')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Landing/Index'));
    }
}
