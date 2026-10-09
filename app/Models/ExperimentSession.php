<?php

namespace App\Models;

use App\Enums\AssistanceCondition;
use App\Enums\SessionKind;
use App\Enums\SessionStatus;
use Database\Factories\ExperimentSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'participant_id', 'session_number', 'status', 'condition', 'scheduled_at', 'conducted_by',
    'environment', 'setup_checklist', 'notes',
])]
#[Hidden(['kiosk_token_hash'])]
class ExperimentSession extends Model
{
    /** @use HasFactory<ExperimentSessionFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'scheduled',
        'is_paused' => false,
    ];

    /**
     * Pre-flight checklist shown to the tester before the kiosk is opened.
     * Items flagged "physiology" are skipped for the baseline pre-assessment.
     *
     * @var array<string, array{label: string, physiology: bool}>
     */
    public const SETUP_CHECKLIST = [
        'consent_confirmed' => ['label' => 'Onam formu imzalı ve dosyada', 'physiology' => false],
        'phone_away' => ['label' => 'Katılımcının telefonu ve kişisel cihazları kaldırıldı', 'physiology' => false],
        'room_lighting_fixed' => ['label' => 'Oda aydınlatması standart ayarda (lüks ölçümü girildi)', 'physiology' => false],
        'screen_brightness_fixed' => ['label' => 'Ekran parlaklığı ve çözünürlük sabit profilde', 'physiology' => false],
        'clock_synced' => ['label' => 'Deney bilgisayarı saati NTP ile senkron', 'physiology' => true],
        'bridge_running' => ['label' => 'LSL köprüsü çalışıyor ve kiosk bağlandı', 'physiology' => true],
        'eeg_signal_ok' => ['label' => 'EEG sinyal kalitesi / empedans kabul aralığında', 'physiology' => true],
        'eye_tracker_calibrated' => ['label' => 'Göz izleyici kalibrasyonu ve doğrulaması yapıldı', 'physiology' => true],
        'wearable_on' => ['label' => 'Bileklik takıldı, kayıt başladı, saat senkronu kontrol edildi', 'physiology' => true],
        'labrecorder_started' => ['label' => 'LabRecorder kaydı başlatıldı (tüm akışlar seçili)', 'physiology' => true],
        'instructions_read' => ['label' => 'Standart yönerge metni okundu, sesli düşünme yapılmayacağı belirtildi', 'physiology' => false],
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'session_number' => 'integer',
            'status' => SessionStatus::class,
            'condition' => AssistanceCondition::class,
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'kiosk_token_expires_at' => 'datetime',
            'kiosk_connected_at' => 'datetime',
            'kiosk_last_seen_at' => 'datetime',
            'bridge_status' => 'array',
            'ai_settings_snapshot' => 'array',
            'environment' => 'array',
            'setup_checklist' => 'array',
            'is_paused' => 'boolean',
        ];
    }

    public function kind(): SessionKind
    {
        return SessionKind::from($this->session_number);
    }

    /**
     * Assistance is only ever available in the learning session; the baseline
     * and the delayed session are fully unassisted by design.
     */
    public function allowsAssistance(): bool
    {
        return $this->kind() === SessionKind::Learning && $this->condition !== null;
    }

    /**
     * @return array<string, array{label: string, physiology: bool}>
     */
    public function applicableChecklist(): array
    {
        if ($this->kind()->recordsPhysiology()) {
            return self::SETUP_CHECKLIST;
        }

        return array_filter(self::SETUP_CHECKLIST, fn (array $item) => ! $item['physiology']);
    }

    public function durationMinutes(): ?float
    {
        if (! $this->started_at || ! $this->ended_at) {
            return null;
        }

        return round($this->started_at->diffInSeconds($this->ended_at) / 60, 1);
    }

    /**
     * @return BelongsTo<Participant, $this>
     */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function conductor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'conducted_by');
    }

    /**
     * @return HasMany<SessionStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(SessionStep::class)->orderBy('position');
    }

    /**
     * @return HasMany<TaskAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(TaskAttempt::class);
    }

    /**
     * @return HasMany<InteractionEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(InteractionEvent::class);
    }

    /**
     * @return HasMany<AiMessage, $this>
     */
    public function aiMessages(): HasMany
    {
        return $this->hasMany(AiMessage::class);
    }

    /**
     * @return HasMany<SessionDevice, $this>
     */
    public function devices(): HasMany
    {
        return $this->hasMany(SessionDevice::class);
    }

    /**
     * @return HasOne<QualityReview, $this>
     */
    public function qualityReview(): HasOne
    {
        return $this->hasOne(QualityReview::class);
    }

    /**
     * @return HasMany<DataFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(DataFile::class);
    }
}
