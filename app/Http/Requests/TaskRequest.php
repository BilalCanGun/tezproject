<?php

namespace App\Http\Requests;

use App\Enums\TaskKind;
use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class TaskRequest extends FormRequest
{
    public const MEASURE_PERSISTENCE = 'persistence';

    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    /**
     * A form task has no fixed session: it appears in the session its form is
     * assigned to, so session_number is cleared whenever a form is chosen.
     */
    protected function prepareForValidation(): void
    {
        $form = $this->input('form') ?: null;

        $this->merge(['form' => $form, ...($form ? ['session_number' => null] : [])]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $kind = TaskKind::tryFrom((string) $this->input('kind'));
        $format = $kind?->responseFormat();

        return [
            'form' => ['nullable', 'in:A,B'],
            'session_number' => [Rule::requiredIf(! $this->filled('form')), Rule::prohibitedIf($this->filled('form')), 'nullable', 'integer', 'in:0,1,2'],
            'kind' => ['required', Rule::enum(TaskKind::class)],
            'title' => ['required', 'string', 'max:160'],
            'isomorph_group' => ['nullable', 'string', 'max:60', 'alpha_dash'],
            'sort_order' => ['required', 'integer', 'between:0,999'],
            'prompt' => ['required', 'string', 'max:20000'],
            'starter_code' => [Rule::requiredIf($format === 'code'), 'nullable', 'string', 'max:10000'],
            'reference_solution' => [Rule::requiredIf($format === 'code'), 'nullable', 'string', 'max:10000'],
            'test_cases' => [Rule::requiredIf($format === 'code'), 'nullable', 'array', 'max:50'],
            'test_cases.*.call' => ['required', 'string', 'max:2000'],
            'test_cases.*.expected' => ['required', 'string', 'max:2000'],
            'test_cases.*.hidden' => ['boolean'],
            'mcq_options' => [Rule::requiredIf($format === 'mcq'), 'nullable', 'array', 'min:2', 'max:8'],
            'mcq_options.*.key' => ['required', 'string', 'max:5', 'distinct'],
            'mcq_options.*.text' => ['required', 'string', 'max:2000'],
            'mcq_answer' => [Rule::requiredIf($format === 'mcq'), 'nullable', 'string', 'max:5'],
            'max_score' => ['required', 'numeric', 'between:0,100'],
            'min_reading_seconds' => ['required', 'integer', 'between:0,600'],
            'planning_seconds' => ['required', 'integer', 'between:0,600'],
            'time_limit_seconds' => ['required', 'integer', 'between:30,7200'],
            'help_allowed' => ['boolean'],
            'is_active' => ['boolean'],
            'scoring_key' => ['nullable', 'array'],
            'scoring_key.measures' => ['nullable', Rule::in([...array_column(TaskKind::cases(), 'value'), self::MEASURE_PERSISTENCE])],
            'scoring_key.expected_approach' => ['nullable', 'string', 'max:5000'],
            'scoring_key.difficulty' => ['nullable', 'in:kolay,orta,zor'],
            'scoring_key.estimated_minutes' => ['nullable', 'numeric', 'between:0,240'],
            'scoring_key.category_rationale' => ['nullable', 'string', 'max:5000'],
            'scoring_key.alternative_solutions' => ['nullable', 'array', 'max:20'],
            'scoring_key.alternative_solutions.*' => ['string', 'max:10000'],
            'scoring_key.partial_credit' => ['nullable', 'array', 'max:20'],
            'scoring_key.partial_credit.*.criterion' => ['required', 'string', 'max:2000'],
            'scoring_key.partial_credit.*.points' => ['required', 'numeric', 'between:0,100'],
            'scoring_key.misconceptions' => ['nullable', 'array', 'max:20'],
            'scoring_key.misconceptions.*.code' => ['required', 'string', 'max:60', 'alpha_dash'],
            'scoring_key.misconceptions.*.description' => ['required', 'string', 'max:2000'],
            'scoring_key.target_bugs' => ['nullable', 'array', 'max:20'],
            'scoring_key.target_bugs.*.line_hint' => ['nullable', 'string', 'max:500'],
            'scoring_key.target_bugs.*.description' => ['required', 'string', 'max:2000'],
            'scoring_key.explanation_elements' => ['nullable', 'array', 'max:20'],
            'scoring_key.explanation_elements.*' => ['string', 'max:2000'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $hasForm = $this->filled('form');
                $kind = TaskKind::tryFrom((string) $this->input('kind'));

                if ($hasForm && $kind && ! in_array($kind, Task::FORM_KINDS, true)) {
                    $validator->errors()->add('form', 'A/B formu yalnızca yakın transfer, uzak transfer ve hata bulma görevlerinde kullanılabilir.');
                }

                if (! $hasForm && (int) $this->input('session_number') !== 1 && $this->boolean('help_allowed')) {
                    $validator->errors()->add('help_allowed', 'Yardım yalnızca 1. oturum görevlerinde açılabilir; ön test ve gecikmeli oturum yardımsızdır.');
                }

                if ($kind?->isPreAssessment() && (int) $this->input('session_number') !== 0) {
                    $validator->errors()->add('session_number', 'Ön test maddeleri ve kavram ön kontrolü yalnızca 0. oturumda (ön değerlendirme) yer alabilir.');
                }

                $options = collect($this->input('mcq_options', []))->pluck('key');

                if ($this->filled('mcq_answer') && $options->isNotEmpty() && ! $options->contains($this->input('mcq_answer'))) {
                    $validator->errors()->add('mcq_answer', 'Doğru yanıt seçeneklerden biri olmalıdır.');
                }
            },
        ];
    }
}
