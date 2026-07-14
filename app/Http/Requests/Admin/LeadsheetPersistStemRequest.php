<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LeadsheetPersistStemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isInstructor();
    }

    public function rules(): array
    {
        return [
            'session' => ['required', 'string', 'max:64'],
            'stem'    => ['required', 'string', Rule::in(['guitar', 'bass', 'vocals', 'drums', 'piano', 'other'])],
        ];
    }
}
