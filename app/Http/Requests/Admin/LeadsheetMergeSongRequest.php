<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class LeadsheetMergeSongRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isInstructor();
    }

    public function rules(): array
    {
        return [
            'source_leadsheet_id' => ['required', 'integer', 'exists:sbn_leadsheets,id'],
        ];
    }
}
