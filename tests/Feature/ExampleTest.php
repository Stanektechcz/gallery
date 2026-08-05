<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * "/" is the public front door. It used to sit behind auth, so a visitor arriving at
     * the domain met a login form and never saw what the service was.
     */
    public function test_the_front_page_shows_the_service_to_a_visitor(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Landing/Index'));
    }
}
