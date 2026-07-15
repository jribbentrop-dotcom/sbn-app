<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FillVoicingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Voicing-style families (popular, shell, drop2, drop3, archetype, ...)
            // aren't a fixed enum — kept as a bounded free-form string.
            'voicing_style'  => ['nullable', 'string', 'max:50'],
            'extension_mode' => ['nullable', Rule::in(['basic', 'extended'])],
            'fill_gaps_only' => ['nullable', 'boolean'],
            'category'       => ['nullable', 'string', 'max:50'],
        ];
    }
}
