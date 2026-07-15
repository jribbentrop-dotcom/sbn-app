<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UploadBackingTrackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'track' => ['required', 'file', 'mimes:mp3,wav,m4a,aac,ogg', 'max:20480'],
            'kind'  => ['required', 'string', 'in:backing,guitar'],
        ];
    }
}
