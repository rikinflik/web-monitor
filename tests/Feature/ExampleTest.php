<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The root URL is a redirect to the monitor list, not a landing page.
     */
    public function test_the_root_url_redirects_to_the_monitor_list(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('monitors.index'));
    }
}
