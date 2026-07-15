<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class BuildVoicingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'style'          => 'nullable|string|max:50',
            'extensions'     => 'nullable|boolean',
            'root_only'      => 'nullable|boolean',
            'voicing_style'  => 'nullable|string|max:50',
            'progression_id' => 'nullable|integer|exists:sbn_chord_progressions,id',
            'leadsheet_id'   => 'nullable|integer|exists:sbn_leadsheets,id',
            'numerals'       => 'nullable|string',
            'chords'         => 'nullable|array',
            'chords.*'       => 'string|max:120',
            'key'            => 'nullable|string|max:10',
        ];
    }
}
