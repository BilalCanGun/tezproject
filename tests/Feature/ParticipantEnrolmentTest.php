<?php

namespace Tests\Feature;

use App\Enums\ParticipantStatus;
use App\Models\Participant;
use App\Models\ProtocolVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ParticipantEnrolmentTest extends TestCase
{
    use RefreshDatabase;

    private User $tester;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->tester = User::factory()->create();
    }

    /**
     * @param  array<string, bool>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'full_name' => 'Deniz Kaya',
            'email' => 'deniz@example.test',
            'consent_version' => 'v1',
            'consented_at' => now()->subHour()->format('Y-m-d H:i'),
            'age' => 22,
            'screening' => [
                ...collect(Participant::SCREENING_ITEMS)->map(fn (array $item) => $item['expected'])->all(),
                ...$overrides,
            ],
        ];
    }

    #[Test]
    public function an_eligible_candidate_gets_the_next_code_of_the_active_protocol(): void
    {
        ProtocolVersion::factory()->active()->main()->create();

        $this->actingAs($this->tester)->post(route('participants.store'), $this->payload())->assertRedirect();
        $this->actingAs($this->tester)->post(route('participants.store'), $this->payload())->assertRedirect();

        $this->assertSame(['P-001', 'P-002'], Participant::orderBy('id')->pluck('code')->all());
        $this->assertSame(ParticipantStatus::Eligible, Participant::first()->status);
    }

    #[Test]
    public function failing_an_exclusion_criterion_marks_the_candidate_ineligible(): void
    {
        ProtocolVersion::factory()->active()->create();

        $this->actingAs($this->tester)->post(route('participants.store'), $this->payload(['concept_new' => false]));

        $participant = Participant::sole();
        $this->assertSame(ParticipantStatus::Ineligible, $participant->status);
        $this->assertStringContainsString('Hedef kavramı', $participant->exclusion_reason);
    }

    #[Test]
    public function review_level_answers_do_not_exclude(): void
    {
        ProtocolVersion::factory()->active()->create();

        $this->actingAs($this->tester)->post(route('participants.store'), $this->payload(['eye_condition' => true]));

        $this->assertSame(ParticipantStatus::Eligible, Participant::sole()->status);
    }

    #[Test]
    public function contact_data_is_stored_encrypted_and_never_in_the_participant_row(): void
    {
        ProtocolVersion::factory()->active()->create();

        $this->actingAs($this->tester)->post(route('participants.store'), $this->payload());

        $raw = \DB::table('participant_contacts')->value('email');
        $this->assertNotSame('deniz@example.test', $raw);
        $this->assertSame('deniz@example.test', Participant::sole()->contact->email);
    }

    #[Test]
    public function every_screening_question_must_be_answered(): void
    {
        ProtocolVersion::factory()->active()->create();
        $payload = $this->payload();
        unset($payload['screening']['adult']);

        $this->actingAs($this->tester)->post(route('participants.store'), $payload)->assertSessionHasErrors('screening.adult');
        $this->assertDatabaseCount('participants', 0);
    }

    #[Test]
    public function an_admin_can_purge_contact_data_while_research_data_stays(): void
    {
        $admin = User::factory()->admin()->create();
        $participant = Participant::factory()->create();
        $participant->contact()->create(['full_name' => 'Ada Yılmaz']);

        $this->actingAs($admin)->delete(route('admin.participants.contact.destroy', $participant))->assertRedirect();

        $this->assertDatabaseCount('participant_contacts', 0);
        $this->assertModelExists($participant);
        $this->assertDatabaseHas('audit_logs', ['action' => 'participant.contact_purged', 'subject_id' => $participant->id]);
    }
}
