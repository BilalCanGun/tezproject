<?php

namespace App\Http\Requests;

use App\Enums\TaskKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class TaskRequest extends FormRequest
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
        $kind = TaskKind::tryFrom((string) $this->input('kind'));
        $format = $kind?->responseFormat();

        return [
            'session_number' => ['required', 'integer', 'in:0,1,2'],
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
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ((int) $this->input('session_number') !== 1 && $this->boolean('help_allowed')) {
                    $validator->errors()->add('help_allowed', 'Yardım yalnızca 1. oturum görevlerinde açılabilir; ön test ve gecikmeli oturum yardımsızdır.');
                }

                $options = collect($this->input('mcq_options', []))->pluck('key');

                if ($this->filled('mcq_answer') && $options->isNotEmpty() && ! $options->contains($this->input('mcq_answer'))) {
                    $validator->errors()->add('mcq_answer', 'Doğru yanıt seçeneklerden biri olmalıdır.');
                }
            },
        ];
    }
}
