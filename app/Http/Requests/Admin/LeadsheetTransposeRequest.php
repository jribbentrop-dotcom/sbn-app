<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class LeadsheetTransposeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isInstructor();
    }

    public function rules(): array
    {
        return [
            'semitones' => ['required', 'integer', 'between:-11,11'],
            'v'         => ['nullable', 'string'],
        ];
    }
}
