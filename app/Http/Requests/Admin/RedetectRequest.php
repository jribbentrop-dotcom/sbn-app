<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class RedetectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'detection_preset'      => 'nullable|string|in:balanced,sensitive,strict,custom',
            'onset_threshold'       => 'nullable|numeric|min:0.05|max:0.95',
            'frame_threshold'       => 'nullable|numeric|min:0.05|max:0.95',
            'minimum_note_length'   => 'nullable|numeric|min:10|max:500',
            'restrict_guitar_range' => 'nullable|boolean',
            'force'                 => 'nullable|boolean',
        ];
    }
}
