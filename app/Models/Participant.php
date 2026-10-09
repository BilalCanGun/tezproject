<?php

namespace App\Models;

use App\Enums\AssistanceCondition;
use App\Enums\ParticipantStatus;
use App\Enums\SessionKind;
use App\Enums\SkillStratum;
use Database\Factories\ParticipantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'protocol_version_id', 'code', 'status', 'consent_version', 'consented_at', 'data_sharing_level',
    'age', 'gender', 'occupation_type', 'education_level', 'programming_experience_months',
    'primary_language', 'vision_correction', 'handedness', 'screening', 'exclusion_reason',
    'withdrawn_at', 'notes', 'created_by',
])]
class Participant extends Model
{
    /** @use HasFactory<ParticipantFactory> */
    use HasFactory;

    /**
     * Screening questions. Answers that differ from the expected value make a
     * candidate ineligible; "review" items are flagged for the researcher.
     *
     * @var array<string, array{label: string, expected: bool, effect: string}>
     */
    public const SCREENING_ITEMS = [
        'adult' => ['label' => '18 yaşını doldurmuş', 'expected' => true, 'effect' => 'exclude'],
        'reads_code' => ['label' => 'Temel Python kodunu okuyabiliyor (değişken, koşul, döngü, fonksiyon, liste)', 'expected' => true, 'effect' => 'exclude'],
        'concept_new' => ['label' => 'Hedef kavramı daha önce sistematik olarak öğrenmedi / kullanmadı', 'expected' => true, 'effect' => 'exclude'],
        'neurological_history' => ['label' => 'Epilepsi veya EEG’yi etkileyebilecek nörolojik tanı öyküsü var', 'expected' => false, 'effect' => 'exclude'],
        'psychoactive_medication' => ['label' => 'Son 24 saatte EEG’yi etkileyebilecek ilaç kullandı', 'expected' => false, 'effect' => 'review'],
        'scalp_condition' => ['label' => 'Saçlı deride yara, hassasiyet veya elektrot temasını engelleyen durum var', 'expected' => false, 'effect' => 'exclude'],
        'uncorrected_vision' => ['label' => 'Ekranı düzeltilmemiş görme bozukluğu nedeniyle rahat okuyamıyor', 'expected' => false, 'effect' => 'exclude'],
        'eye_condition' => ['label' => 'Göz izlemeyi engelleyebilecek göz durumu (şaşılık, nistagmus vb.)', 'expected' => false, 'effect' => 'review'],
        'available_second_session' => ['label' => '6–8 gün sonra ikinci oturuma katılabilir', 'expected' => true, 'effect' => 'exclude'],
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ParticipantStatus::class,
            'condition' => AssistanceCondition::class,
            'stratum' => SkillStratum::class,
            'baseline_score' => 'float',
            'baseline_max' => 'float',
            'randomized_at' => 'datetime',
            'consented_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'screening' => 'array',
        ];
    }

    public function baselinePercentage(): ?float
    {
        if ($this->baseline_score === null || ! $this->baseline_max) {
            return null;
        }

        return round($this->baseline_score / $this->baseline_max * 100, 1);
    }

    public function session(SessionKind $kind): ?ExperimentSession
    {
        return $this->sessions->firstWhere('session_number', $kind->value);
    }

    /**
     * @return BelongsTo<ProtocolVersion, $this>
     */
    public function protocolVersion(): BelongsTo
    {
        return $this->belongsTo(ProtocolVersion::class);
    }

    /**
     * @return HasOne<ParticipantContact, $this>
     */
    public function contact(): HasOne
    {
        return $this->hasOne(ParticipantContact::class);
    }

    /**
     * @return HasMany<ExperimentSession, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(ExperimentSession::class)->orderBy('session_number');
    }

    /**
     * @return HasOne<RandomizationSlot, $this>
     */
    public function randomizationSlot(): HasOne
    {
        return $this->hasOne(RandomizationSlot::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
