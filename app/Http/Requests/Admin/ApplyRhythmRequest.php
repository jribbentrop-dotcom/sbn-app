<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApplyRhythmRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rhythm_pattern_slug' => ['required', 'string', 'exists:sbn_rhythm_patterns,slug'],
            // Voicing-style families aren't a fixed enum — bounded free-form string.
            'voicing_style'       => ['nullable', 'string', 'max:50'],
            'extension_mode'      => ['nullable', Rule::in(['basic', 'extended'])],
        ];
    }
}
