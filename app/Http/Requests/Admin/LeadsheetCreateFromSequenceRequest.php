<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LeadsheetCreateFromSequenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isInstructor();
    }

    public function rules(): array
    {
        return [
            'title'          => ['required', 'string', 'max:255'],
            'composer'       => ['nullable', 'string', 'max:255'],
            'song_key'       => ['required', 'string', 'max:10'],
            'tempo'          => ['required', 'integer', 'min:20', 'max:300'],
            'time_signature' => ['required', 'string', 'max:10'],
            'rhythm'         => ['nullable', 'string', 'max:50'],
            'bars_per_chord' => ['nullable', 'integer', 'min:1', 'max:16'],
            'source_type'    => ['required', Rule::in(['free', 'chordpro', 'bars', 'clone', 'progression', 'jazz_standard', 'standard'])],
            'sequence_text'  => ['nullable', 'string'],
            'clone_source_id'   => ['nullable', 'integer', 'exists:sbn_leadsheets,id'],
            'progression_id'    => ['nullable', 'integer', 'exists:sbn_chord_progressions,id'],
            'jazz_standard_id'  => ['nullable', 'integer', 'exists:sbn_jazz_standards,id'],
            'build_voicings' => ['nullable', 'boolean'],
            'extension_mode' => ['nullable', 'string', Rule::in(['basic', 'extended'])],

            'voicing_style'  => ['nullable', 'string', Rule::in(['popular', 'shell', 'drop2', 'drop3', 'closed', 'archetype', 'quartal', 'custom', 'closed_triads', 'spread_triads', 'slash'])],
        ];
    }
}
