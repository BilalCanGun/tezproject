<?php

namespace App\Http\Requests;

use App\Models\Participant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ParticipantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $screening = collect(Participant::SCREENING_ITEMS)
            ->mapWithKeys(fn ($item, $key) => ["screening.{$key}" => ['required', 'boolean']])
            ->all();

        return [
            'full_name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:30'],
            'purge_after' => ['nullable', 'date', 'after:today'],

            'consent_version' => ['nullable', 'string', 'max:20', 'required_with:consented_at'],
            'consented_at' => ['nullable', 'date', 'before_or_equal:now'],
            'data_sharing_level' => ['nullable', Rule::in(['open_anonymized', 'controlled', 'none'])],

            'age' => ['nullable', 'integer', 'between:18,99'],
            'gender' => ['nullable', Rule::in(['female', 'male', 'other', 'undisclosed'])],
            'occupation_type' => ['nullable', Rule::in(['student', 'employee', 'other'])],
            'education_level' => ['nullable', Rule::in(['undergraduate', 'graduate', 'postgraduate', 'other'])],
            'programming_experience_months' => ['nullable', 'integer', 'between:0,600'],
            'primary_language' => ['nullable', 'string', 'max:40'],
            'vision_correction' => ['nullable', Rule::in(['none', 'glasses', 'contacts'])],
            'handedness' => ['nullable', Rule::in(['right', 'left', 'both'])],

            'screening' => ['required', 'array'],
            ...$screening,
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'full_name' => 'ad soyad',
            'consent_version' => 'onam formu sürümü',
            'consented_at' => 'onam tarihi',
            'purge_after' => 'kişisel veri silme tarihi',
            'age' => 'yaş',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function participantAttributes(): array
    {
        return $this->safe()->except(['full_name', 'email', 'phone', 'purge_after']);
    }

    /**
     * @return array<string, mixed>
     */
    public function contactAttributes(): array
    {
        return $this->safe()->only(['full_name', 'email', 'phone', 'purge_after']);
    }
}
