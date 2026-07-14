<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LeadsheetRedetectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isInstructor();
    }

    public function rules(): array
    {
        return [
            'detection_preset'      => ['nullable', 'string', Rule::in(['balanced', 'sensitive', 'strict', 'custom'])],
            'onset_threshold'       => ['nullable', 'numeric', 'min:0.05', 'max:0.95'],
            'frame_threshold'       => ['nullable', 'numeric', 'min:0.05', 'max:0.95'],
            'minimum_note_length'   => ['nullable', 'numeric', 'min:10', 'max:500'],
            'restrict_guitar_range' => ['nullable', 'boolean'],
            'force'                 => ['nullable', 'boolean'],
        ];
    }
}
