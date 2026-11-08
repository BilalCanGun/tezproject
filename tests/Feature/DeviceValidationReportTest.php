<?php

namespace Tests\Feature;

use App\Console\Commands\ExportDeviceProfiles;
use App\Http\Controllers\Admin\ReportController;
use App\Models\DeviceProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DeviceValidationReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');
    }

    #[Test]
    public function the_page_explains_how_to_generate_a_missing_report(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.reports.devices'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Admin/Reports/Devices')->where('report', null));
    }

    #[Test]
    public function the_page_shows_the_python_report(): void
    {
        Storage::disk('local')->put(ReportController::DEVICE_REPORT, json_encode([
            'generated_at' => '2026-11-08T10:00:00Z',
            'datasets' => [['key' => 'wesad', 'title' => 'WESAD', 'participants' => 15]],
            'devices' => [['device' => 'Empatica EmbracePlus', 'modality' => 'wearable', 'checks' => []]],
            'models' => [],
        ]));

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.reports.devices'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('report.datasets.0.key', 'wesad')
                ->where('report.devices.0.device', 'Empatica EmbracePlus'));
    }

    #[Test]
    public function testers_cannot_open_the_report(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.reports.devices'))
            ->assertForbidden();
    }

    #[Test]
    public function only_active_device_profiles_are_exported(): void
    {
        DeviceProfile::factory()->create(['name' => 'Aktif EEG']);
        DeviceProfile::factory()->create(['name' => 'Pasif EEG', 'is_active' => false]);

        $this->artisan('devices:export-profiles')->assertSuccessful();

        $exported = json_decode(Storage::disk('local')->get(ExportDeviceProfiles::PATH), true);

        $this->assertSame(['Aktif EEG'], array_column($exported, 'name'));
        $this->assertSame('eeg', $exported[0]['modality']);
        $this->assertSame(500.0, (float) $exported[0]['sampling_rate_hz']);
    }
}
