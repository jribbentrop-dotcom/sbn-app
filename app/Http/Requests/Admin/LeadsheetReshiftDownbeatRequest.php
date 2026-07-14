<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LeadsheetReshiftDownbeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isInstructor();
    }

    public function rules(): array
    {
        return [
            'offset'    => ['required', 'integer', 'min:0', 'max:1919'],
            'bass_snap' => ['nullable', 'boolean'],
            'tab_position_style' => ['nullable', 'string', Rule::in(['fretted', 'open'])],
            // Set true (by the client, right after reopen-tuning) to re-derive a
            // transcription the user had latched as "fixed". See §13.
            'force'     => ['nullable', 'boolean'],
        ];
    }
}
