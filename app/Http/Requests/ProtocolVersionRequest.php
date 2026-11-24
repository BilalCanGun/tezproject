<?php

namespace App\Http\Requests;

use App\Enums\AssistantProvider;
use App\Enums\ProtocolPhase;
use App\Models\ProtocolVersion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProtocolVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $protocol = $this->route('protocol');

        return [
            'code' => ['required', 'string', 'max:40', 'alpha_dash', Rule::unique('protocol_versions')->ignore($protocol)],
            'name' => ['required', 'string', 'max:160'],
            'phase' => ['required', Rule::enum(ProtocolPhase::class)],
            'description' => ['nullable', 'string', 'max:4000'],
            'concept_title' => ['nullable', 'string', 'max:160'],
            'concept_rationale' => ['nullable', 'string', 'max:4000'],
            'system_prompt_direct' => ['required', 'string', 'max:20000'],
            'system_prompt_socratic' => ['required', 'string', 'max:20000'],
            'copy_from' => ['nullable', 'integer', 'exists:protocol_versions,id'],

            'settings.assistant.provider' => ['required', Rule::enum(AssistantProvider::class)],
            'settings.assistant.model' => ['nullable', 'string', 'max:80'],
            'settings.assistant.temperature' => ['nullable', 'numeric', 'between:0,2'],
            'settings.assistant.effort' => ['nullable', Rule::in(['low', 'medium', 'high', 'xhigh', 'max'])],
            'settings.assistant.max_tokens' => ['required', 'integer', 'between:200,32000'],
            'settings.assistant.history_turns' => ['required', 'integer', 'between:1,30'],
            'settings.assistant.socratic_max_code_lines' => ['required', 'integer', 'between:0,10'],
            'settings.assistant.socratic_similarity_threshold' => ['required', 'numeric', 'between:0.1,1'],

            'settings.session.eyes_open_seconds' => ['required', 'integer', 'between:0,600'],
            'settings.session.eyes_closed_seconds' => ['required', 'integer', 'between:0,600'],
            'settings.session.sync_gesture_taps' => ['required', 'integer', 'between:1,10'],
            'settings.session.delay_min_days' => ['required', 'integer', 'between:1,60'],
            'settings.session.delay_max_days' => ['required', 'integer', 'between:1,60', 'gte:settings.session.delay_min_days'],
            'settings.session.learning_target_minutes' => ['required', 'integer', 'between:5,180'],
            'settings.session.session1_target_minutes' => ['required', 'integer', 'between:10,300'],
            'settings.session.session2_target_minutes' => ['required', 'integer', 'between:10,300'],

            'settings.stratification.low_upper_percent' => ['required', 'numeric', 'between:1,99'],
            'settings.stratification.medium_upper_percent' => ['required', 'numeric', 'between:1,99', 'gt:settings.stratification.low_upper_percent'],
            'settings.stratification.block_sizes' => ['required', 'array', 'min:1'],
            'settings.stratification.block_sizes.*' => ['integer', 'in:3,6,9,12'],

            'settings.sample.completers_per_condition' => ['required', 'integer', 'between:1,500'],
            'settings.sample.enrolment_target' => ['required', 'integer', 'between:3,2000'],

            'settings.quality.min_eeg_usable_epoch_ratio' => ['required', 'numeric', 'between:0,1'],
            'settings.quality.min_gaze_valid_ratio' => ['required', 'numeric', 'between:0,1'],
            'settings.quality.max_wearable_loss_ratio' => ['required', 'numeric', 'between:0,1'],
            'settings.quality.min_marker_integrity' => ['required', 'numeric', 'between:0,1'],
            'settings.quality.max_calibration_error_deg' => ['required', 'numeric', 'between:0,5'],

            'settings.pilot.min_task_completion_rate' => ['required', 'numeric', 'between:0,1'],
            'settings.pilot.far_transfer_floor_percent' => ['required', 'numeric', 'between:0,100'],
            'settings.pilot.far_transfer_ceiling_percent' => ['required', 'numeric', 'between:0,100', 'gt:settings.pilot.far_transfer_floor_percent'],
            'settings.pilot.min_direct_full_code_rate' => ['required', 'numeric', 'between:0,1'],
            'settings.pilot.min_direct_request_fulfilment_rate' => ['required', 'numeric', 'between:0,1'],
            'settings.pilot.max_socratic_leak_rate' => ['required', 'numeric', 'between:0,1'],
            'settings.pilot.min_socratic_questions_per_reply' => ['required', 'numeric', 'between:0,10'],
            'settings.pilot.max_planning_violations_per_task' => ['required', 'numeric', 'between:0,50'],

            'settings.rating.double_rating_percent' => ['required', 'integer', 'between:0,100'],

            'settings.screening.concept_precheck_exclude_percent' => ['required', 'numeric', 'between:0,100'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $provider = AssistantProvider::tryFrom((string) $this->input('settings.assistant.provider'));

                if ($provider === AssistantProvider::OpenAi && ! $this->input('settings.assistant.model')) {
                    $validator->errors()->add('settings.assistant.model', 'OpenAI için model adı zorunludur.');
                }
            },
        ];
    }

    /**
     * Validated settings over the current (or default) settings, so values
     * without a form field, such as the per-session task order, are stored
     * with the protocol and frozen with it.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $protocol = $this->route('protocol');
        $current = $protocol instanceof ProtocolVersion ? $protocol->resolvedSettings() : config('experiment.protocol_defaults');
        $settings = array_replace_recursive($current, $this->validated('settings'));
        $settings['stratification']['block_sizes'] = array_values(array_unique(array_map('intval', $this->validated('settings.stratification.block_sizes'))));

        return $settings;
    }
}
