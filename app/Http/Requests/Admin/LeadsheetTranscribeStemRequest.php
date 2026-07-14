<?php

namespace App\Http\Requests\Admin;

use App\Services\MidiTranscriptionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LeadsheetTranscribeStemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isInstructor();
    }

    public function rules(): array
    {
        return [
            'session'               => ['required', 'string', 'max:64'],
            'stems'                 => ['nullable', 'array'],
            'stems.*'               => ['string', Rule::in(MidiTranscriptionService::STEM_NAMES)],
            'detection_preset'      => ['nullable', 'string', Rule::in(['balanced', 'sensitive', 'strict', 'custom'])],
            'onset_threshold'       => ['nullable', 'numeric', 'min:0.05', 'max:0.95'],
            'frame_threshold'       => ['nullable', 'numeric', 'min:0.05', 'max:0.95'],
            'minimum_note_length'   => ['nullable', 'numeric', 'min:10', 'max:500'],
            'restrict_guitar_range' => ['nullable', 'boolean'],
            'force'                 => ['nullable', 'boolean'],
        ];
    }
}
