<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LeadsheetBackingTrackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isInstructor();
    }

    public function rules(): array
    {
        return [
            'track' => ['required', 'file', 'mimes:mp3,wav,m4a,aac,ogg', 'max:20480'],
            'kind'  => ['required', 'string', Rule::in(['backing', 'guitar'])],
        ];
    }
}
