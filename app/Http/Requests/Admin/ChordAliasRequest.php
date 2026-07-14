<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ChordAliasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isInstructor();
    }

    public function rules(): array
    {
        return [
            'alt_root_note'  => ['required', 'string', 'max:3'],
            'alt_quality'    => ['required', 'string', 'max:20'],
            'alt_extensions' => ['nullable', 'string', 'max:50'],
            'alt_bass_note'  => ['nullable', 'string', 'max:5'],
        ];
    }
}
