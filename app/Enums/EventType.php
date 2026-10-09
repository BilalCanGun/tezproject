<?php

namespace App\Enums;

/**
 * Interaction events captured by the kiosk. Events with a marker code are also
 * pushed to the LSL marker stream so they land in the same XDF file as the EEG
 * and gaze streams. Codes are grouped by decade to keep trigger lists readable.
 */
enum EventType: string
{
    case SessionStart = 'session_start';
    case SessionEnd = 'session_end';
    case StepStart = 'step_start';
    case StepEnd = 'step_end';
    case EyesOpenStart = 'eyes_open_start';
    case EyesOpenEnd = 'eyes_open_end';
    case EyesClosedStart = 'eyes_closed_start';
    case EyesClosedEnd = 'eyes_closed_end';
    case SyncGesture = 'sync_gesture';

    case TaskStart = 'task_start';
    case ReadingStart = 'reading_start';
    case PlanningStart = 'planning_start';
    case PlanningEnd = 'planning_end';
    case CodingStart = 'coding_start';
    case CheckingStart = 'checking_start';
    case TaskSubmit = 'task_submit';
    case TaskTimeout = 'task_timeout';
    case TaskGiveUp = 'task_give_up';

    case CodeRun = 'code_run';
    case CodeError = 'code_error';
    case CodeSuccess = 'code_success';
    case CodeChange = 'code_change';

    case AiPrompt = 'ai_prompt';
    case AiResponse = 'ai_response';
    case ResourceOpen = 'resource_open';
    case ResourceSearch = 'resource_search';

    case Copy = 'copy';
    case Paste = 'paste';
    case McqSelect = 'mcq_select';

    case FocusLost = 'focus_lost';
    case FocusGained = 'focus_gained';
    case PlanningInputViolation = 'planning_input_violation';

    case TesterNote = 'tester_note';
    case SessionPaused = 'session_paused';
    case SessionResumed = 'session_resumed';

    case AoiLayout = 'aoi_layout';
    case ClockSync = 'clock_sync';
    case MarkerAck = 'marker_ack';

    public function markerCode(): ?int
    {
        return match ($this) {
            self::SessionStart => 10,
            self::SessionEnd => 11,
            self::StepStart => 12,
            self::StepEnd => 13,
            self::EyesOpenStart => 20,
            self::EyesOpenEnd => 21,
            self::EyesClosedStart => 22,
            self::EyesClosedEnd => 23,
            self::SyncGesture => 25,
            self::TaskStart => 30,
            self::ReadingStart => 31,
            self::PlanningStart => 32,
            self::PlanningEnd => 33,
            self::CodingStart => 34,
            self::CheckingStart => 35,
            self::TaskSubmit => 37,
            self::TaskTimeout => 38,
            self::TaskGiveUp => 39,
            self::CodeRun => 40,
            self::CodeError => 41,
            self::CodeSuccess => 42,
            self::AiPrompt => 50,
            self::AiResponse => 51,
            self::ResourceOpen => 52,
            self::ResourceSearch => 53,
            self::Copy => 60,
            self::Paste => 61,
            self::McqSelect => 62,
            self::FocusLost => 70,
            self::FocusGained => 71,
            self::PlanningInputViolation => 72,
            self::TesterNote => 80,
            self::SessionPaused => 81,
            self::SessionResumed => 82,
            self::CodeChange, self::AoiLayout, self::ClockSync, self::MarkerAck => null,
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SessionStart => 'Oturum başladı',
            self::SessionEnd => 'Oturum bitti',
            self::StepStart => 'Adım başladı',
            self::StepEnd => 'Adım bitti',
            self::EyesOpenStart => 'Göz açık dinlenim başladı',
            self::EyesOpenEnd => 'Göz açık dinlenim bitti',
            self::EyesClosedStart => 'Göz kapalı dinlenim başladı',
            self::EyesClosedEnd => 'Göz kapalı dinlenim bitti',
            self::SyncGesture => 'Bileklik senkron hareketi (ivme tepe noktası)',
            self::TaskStart => 'Görev başladı',
            self::ReadingStart => 'Problemi okuma fazı',
            self::PlanningStart => 'Sessiz planlama başladı',
            self::PlanningEnd => 'Sessiz planlama bitti',
            self::CodingStart => 'Kod yazma fazı başladı',
            self::CheckingStart => 'Çözüm kontrol fazı',
            self::TaskSubmit => 'Görev teslim edildi',
            self::TaskTimeout => 'Görev süresi doldu',
            self::TaskGiveUp => 'Katılımcı görevi bıraktı',
            self::CodeRun => 'Kod çalıştırıldı',
            self::CodeError => 'Çalıştırma hata/başarısız test ile bitti',
            self::CodeSuccess => 'Tüm testler geçti',
            self::CodeChange => 'Kod değişikliği (toplu, marker yok)',
            self::AiPrompt => 'YZ’ye istem gönderildi',
            self::AiResponse => 'YZ yanıtı ekrana geldi',
            self::ResourceOpen => 'Kaynak sayfası açıldı',
            self::ResourceSearch => 'Kaynakta arama yapıldı',
            self::Copy => 'Kopyalama',
            self::Paste => 'Yapıştırma',
            self::McqSelect => 'Çoktan seçmeli yanıt seçildi',
            self::FocusLost => 'Pencere odağı kaybedildi',
            self::FocusGained => 'Pencere odağı geri geldi',
            self::PlanningInputViolation => 'Planlama fazında klavye/fare girişi',
            self::TesterNote => 'Araştırmacı notu (artefakt vb.)',
            self::SessionPaused => 'Oturum duraklatıldı',
            self::SessionResumed => 'Oturum devam etti',
            self::AoiLayout => 'İlgi alanı (AOI) koordinatları',
            self::ClockSync => 'Tarayıcı–LSL saat eşlemesi',
            self::MarkerAck => 'Geç gelen LSL marker onayı',
        };
    }

    /**
     * Event types the kiosk is allowed to submit. Tester notes and pause events
     * are written server-side only.
     *
     * @return list<string>
     */
    public static function kioskValues(): array
    {
        $serverOnly = [self::TesterNote, self::SessionPaused, self::SessionResumed];

        return array_values(array_map(
            fn (self $type) => $type->value,
            array_filter(self::cases(), fn (self $type) => ! in_array($type, $serverOnly, true)),
        ));
    }
}
