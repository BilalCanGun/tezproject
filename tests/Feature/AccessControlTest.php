<?php

namespace Tests\Feature;

use App\Models\ExperimentSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    #[Test]
    public function guests_are_redirected_to_login(): void
    {
        $this->get(route('participants.index'))->assertRedirect(route('login'));
        $this->get(route('admin.protocols.index'))->assertRedirect(route('login'));
    }

    #[Test]
    public function an_active_user_can_log_in(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    #[Test]
    public function an_inactive_user_cannot_log_in(): void
    {
        $user = User::factory()->inactive()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    #[Test]
    public function a_deactivated_user_loses_an_existing_session(): void
    {
        $user = User::factory()->inactive()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    #[Test]
    public function testers_cannot_reach_administration(): void
    {
        $tester = User::factory()->create();

        $this->actingAs($tester)->get(route('admin.protocols.index'))->assertForbidden();
        $this->actingAs($tester)->get(route('admin.exports.index'))->assertForbidden();
        $this->actingAs($tester)->get(route('participants.index'))->assertOk();
    }

    #[Test]
    public function admins_can_reach_tester_pages_and_administration(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('participants.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.devices.index'))->assertOk();
    }

    #[Test]
    public function only_admins_can_delete_recording_files(): void
    {
        $tester = User::factory()->create();
        $session = ExperimentSession::factory()->create();
        $file = $session->files()->create([
            'kind' => 'xdf', 'original_name' => 'a.xdf', 'path' => 'recordings/a.xdf', 'size_bytes' => 1, 'sha256' => str_repeat('a', 64),
        ]);

        $this->actingAs($tester)->delete(route('sessions.files.destroy', [$session, $file]))->assertForbidden();
        $this->assertModelExists($file);
    }

    #[Test]
    public function an_invalid_kiosk_token_is_rejected(): void
    {
        $this->getJson(route('kiosk.api.state', 'not-a-real-token'))->assertNotFound();
    }
}
