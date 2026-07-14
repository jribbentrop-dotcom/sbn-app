<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class LeadsheetSeparateStemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isInstructor();
    }

    public function rules(): array
    {
        return [
            'youtube_id'  => ['nullable', 'string'],
            'local_audio' => ['nullable', 'file', 'mimes:mp3,wav,m4a,ogg,flac', 'max:102400'],
            // Separate an existing leadsheet's persisted original recording
            // (from the editor's video-sync sidebar) — no re-upload.
            'leadsheet_id' => ['nullable', 'integer', 'exists:sbn_leadsheets,id'],
        ];
    }
}
