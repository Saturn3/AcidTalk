<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThemePreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_theme_preference_is_saved_and_validated(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/me')->assertOk()->assertJsonPath('data.notification_prefs.theme', 'light');
        $this->actingAs($user)->putJson('/api/me', ['notification_prefs' => ['theme' => 'dark']])
            ->assertOk()->assertJsonPath('data.notification_prefs.theme', 'dark')->assertJsonPath('data.notification_prefs.mentions', true);
        $this->actingAs($user)->putJson('/api/me', ['notification_prefs' => ['theme' => 'neon']])->assertUnprocessable();
        $this->assertSame('dark', $user->fresh()->prefs()['theme']);
        $this->actingAs($user)->get('/app')->assertOk()->assertSee('data-theme-pref="dark"', false);
    }
}
