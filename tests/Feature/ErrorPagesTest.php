<?php

namespace Tests\Feature;

use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    public function test_a_missing_page_shows_the_branded_screen_with_a_way_home(): void
    {
        $this->get('/no-such-page-here')
            ->assertNotFound()
            ->assertSee("We can't find that page")
            ->assertSee('این صفحه پیدا نشد.')
            ->assertSee('Home');
    }

    public function test_an_expired_page_sends_you_back_to_load_it_with_a_fresh_token(): void
    {
        $this->from('/login')
            ->view('errors.419')
            ->assertSee('This page timed out')
            ->assertSee('Refresh')
            ->assertSee('href="/login"', false);
    }

    public function test_a_server_error_offers_try_again(): void
    {
        $this->view('errors.500')
            ->assertSee('Something broke on our side')
            ->assertSee('Try again')
            ->assertSee('location.reload()', false);
    }

    public function test_maintenance_reloads_itself_and_has_no_button(): void
    {
        $this->view('errors.503')
            ->assertSee('Back in a few minutes')
            ->assertSee('http-equiv="refresh"', false)
            ->assertDontSee('<button', false);
    }
}
