<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Guards the local-only Edu Content System dev harness route
 * (/dev/edu/{type}/{slug}). It is the verification surface for the
 * <sbn-widget> pipeline; the client-side mount itself runs in the browser
 * and is out of scope for a PHP test, but the server contract — topic
 * resolution, body_html delivery, the embedded widget tag — is asserted here.
 */
class EduHarnessRouteTest extends TestCase
{
    public function test_harness_renders_a_topic_with_its_widget_tag(): void
    {
        $this->get('/dev/edu/concept/triad')
            ->assertOk()
            ->assertSee('EduHarness')          // Inertia page component
            ->assertSee('sbn-widget', false)   // raw widget tag in body_html
            ->assertSee('triad-builder', false);
    }

    public function test_harness_404s_for_an_unknown_topic(): void
    {
        $this->get('/dev/edu/concept/does-not-exist')->assertNotFound();
    }
}
