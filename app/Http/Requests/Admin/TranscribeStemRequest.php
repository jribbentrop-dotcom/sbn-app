<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class TranscribeStemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'session'               => 'required|string|max:64',
            'stems'                 => 'nullable|array',
            'stems.*'               => 'string|in:guitar,bass,vocals,drums,piano,other',
            'detection_preset'      => 'nullable|string|in:balanced,sensitive,strict,custom',
            'onset_threshold'       => 'nullable|numeric|min:0.05|max:0.95',
            'frame_threshold'       => 'nullable|numeric|min:0.05|max:0.95',
            'minimum_note_length'   => 'nullable|numeric|min:10|max:500',
            'restrict_guitar_range' => 'nullable|boolean',
            'force'                 => 'nullable|boolean',
        ];
    }
}
