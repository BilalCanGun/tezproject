<?php

namespace Tests\Feature;

use App\Enums\SessionStatus;
use App\Models\DeviceProfile;
use App\Models\Participant;
use App\Models\ProtocolVersion;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every page renders with the seeded pilot protocol, so serialization and
 * eager-loading mistakes surface before a researcher hits them.
 */
class PageRenderingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);
    }

    #[Test]
    public function administration_pages_render(): void
    {
        $admin = User::where('email', 'admin@lab.test')->sole();
        $protocol = ProtocolVersion::where('code', 'PILOT-v1')->sole();
        $task = $protocol->tasks()->first();
        $article = $protocol->resources()->first();

        $pages = [
            [route('dashboard'), 'Dashboard'],
            [route('participants.index'), 'Participants/Index'],
            [route('participants.create'), 'Participants/Form'],
            [route('ratings.index'), 'Ratings/Index'],
            [route('admin.protocols.index'), 'Admin/Protocols/Index'],
            [route('admin.protocols.create'), 'Admin/Protocols/Form'],
            [route('admin.protocols.edit', $protocol), 'Admin/Protocols/Form'],
            [route('admin.protocols.tasks.index', $protocol), 'Admin/Tasks/Index'],
            [route('admin.protocols.tasks.edit', [$protocol, $task]), 'Admin/Tasks/Form'],
            [route('admin.protocols.resources.index', $protocol), 'Admin/Resources/Index'],
            [route('admin.protocols.resources.edit', [$protocol, $article]), 'Admin/Resources/Form'],
            [route('admin.protocols.rubric.index', $protocol), 'Admin/Rubric/Index'],
            [route('admin.protocols.allocation.show', $protocol), 'Admin/Allocation/Show'],
            [route('admin.devices.index'), 'Admin/Devices/Index'],
            [route('admin.devices.edit', DeviceProfile::first()), 'Admin/Devices/Form'],
            [route('admin.reports.progress'), 'Admin/Reports/Progress'],
            [route('admin.reports.manipulation'), 'Admin/Reports/Manipulation'],
            [route('admin.reports.pilot'), 'Admin/Reports/Pilot'],
            [route('admin.reports.reliability'), 'Admin/Reports/Reliability'],
            [route('admin.reports.devices'), 'Admin/Reports/Devices'],
            [route('admin.exports.index'), 'Admin/Exports/Index'],
            [route('admin.users.index'), 'Admin/Users/Index'],
            [route('admin.users.create'), 'Admin/Users/Form'],
            [route('admin.audit-logs.index'), 'Admin/AuditLogs/Index'],
        ];

        foreach ($pages as [$url, $component]) {
            $this->actingAs($admin)->get($url)
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page->component($component, false));
        }
    }

    #[Test]
    public function participant_and_session_pages_render(): void
    {
        $tester = User::where('email', 'tester@lab.test')->sole();
        $protocol = ProtocolVersion::where('code', 'PILOT-v1')->sole();
        $participant = Participant::factory()->for($protocol, 'protocolVersion')->create();
        $session = $participant->sessions()->create(['session_number' => 0, 'status' => SessionStatus::Scheduled, 'conducted_by' => $tester->id]);
        $session->devices()->create(['device_profile_id' => DeviceProfile::first()->id]);

        $this->actingAs($tester)->get(route('participants.show', $participant))->assertOk();
        $this->actingAs($tester)->get(route('participants.edit', $participant))->assertOk();
        $this->actingAs($tester)->get(route('sessions.show', $session))->assertOk();
        $this->actingAs($tester)->getJson(route('sessions.monitor', $session))->assertOk()->assertJsonStructure(['status', 'steps', 'markers', 'events']);
    }

    #[Test]
    public function the_seeded_pilot_protocol_is_ready_to_freeze(): void
    {
        $admin = User::where('email', 'admin@lab.test')->sole();
        $protocol = ProtocolVersion::where('code', 'PILOT-v1')->sole();

        $this->actingAs($admin)->post(route('admin.protocols.freeze.store', $protocol), ['confirmation' => 'PILOT-v1'])->assertSessionHas('success');

        $this->assertTrue($protocol->fresh()->isFrozen());
        $this->actingAs($admin)->put(route('admin.protocols.update', $protocol), [])->assertSessionHasErrors();
    }
}
