<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class RetuneDetectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'min_note_length_ms' => 'nullable|numeric|min:0|max:2000',
            'midi_min'           => 'nullable|integer|min:0|max:127',
            'midi_max'           => 'nullable|integer|min:0|max:127',
            'force'              => 'nullable|boolean',
        ];
    }
}
