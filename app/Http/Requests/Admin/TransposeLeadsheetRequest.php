<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class TransposeLeadsheetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'semitones' => ['required', 'integer', 'between:-11,11'],
            'v'         => ['nullable', 'string'],
        ];
    }
}
