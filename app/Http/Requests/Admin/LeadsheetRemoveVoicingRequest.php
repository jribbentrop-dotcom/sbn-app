<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class LeadsheetRemoveVoicingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isInstructor();
    }

    public function rules(): array
    {
        return [
            'chord_name'  => ['required', 'string', 'max:50'],
            'fret_string' => ['required', 'string', 'max:20'],
        ];
    }
}
