<?php

namespace Tests\Feature;

use App\Enums\AssistanceCondition;
use App\Models\Participant;
use App\Models\ProtocolVersion;
use App\Models\User;
use App\Services\Export\Anonymizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DataExportTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_outcome_file_is_keyed_by_code_and_contains_no_contact_data(): void
    {
        $admin = User::factory()->admin()->create();
        $protocol = ProtocolVersion::factory()->create();
        $participant = Participant::factory()->for($protocol, 'protocolVersion')->randomized(AssistanceCondition::SocraticAi)->create();
        $participant->contact()->create(['full_name' => 'Ece Demir', 'email' => 'ece@example.test']);

        $response = $this->actingAs($admin)->get(route('admin.exports.show', [$protocol, 'outcomes']));
        $csv = $response->assertOk()->streamedContent();

        $this->assertStringContainsString('s2_far_transfer_pct', $csv);
        $this->assertStringContainsString($participant->code, $csv);
        $this->assertStringContainsString('socratic_ai', $csv);
        $this->assertStringNotContainsString('Ece', $csv);
        $this->assertStringNotContainsString('ece@example.test', $csv);
        $this->assertDatabaseHas('audit_logs', ['action' => 'export.downloaded']);
    }

    #[Test]
    public function unknown_datasets_are_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $protocol = ProtocolVersion::factory()->create();

        $this->actingAs($admin)->get(route('admin.exports.show', [$protocol, 'contacts']))->assertSessionHasErrors('dataset');
    }

    #[Test]
    public function free_text_identifiers_are_scrubbed(): void
    {
        $scrubbed = app(Anonymizer::class)->scrub('Benim mailim ali@test.com, numaram 0532 123 45 67.');

        $this->assertStringNotContainsString('ali@test.com', $scrubbed);
        $this->assertStringNotContainsString('123 45 67', $scrubbed);
    }
}
