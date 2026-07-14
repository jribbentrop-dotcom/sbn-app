<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ChordDiagramRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isInstructor();
    }

    public function rules(): array
    {
        return [
            'root_note'         => ['required', 'string', 'max:3'],
            'quality'           => ['required', 'string', 'max:20'],
            'extensions'        => ['nullable', 'string', 'max:50'],
            'voicing_category'  => ['required', 'string', 'max:30'],
            'root_string'       => ['required', 'string', 'max:20'],
            'inversion'         => ['nullable', 'string', 'max:10'],
            'bass_note'         => ['nullable', 'string', 'max:3'],
            'shape_family'      => ['nullable', 'string', 'max:50'],
            'is_fixed_position' => ['boolean'],
            'start_fret'        => ['required', 'integer', 'min:1', 'max:24'],
            'diagram_data'      => ['required', 'string'],
            'description'       => ['nullable', 'string', 'max:10000'],
            'name'              => ['nullable', 'string', 'max:200'],
            'slug'              => ['nullable', 'string', 'max:200'],
        ];
    }

    /**
     * Same post-validation shaping ChordController::validateChord() did:
     * fall back diagram_data to an empty shape on malformed JSON, and
     * default inversion/is_fixed_position.
     */
    public function chordData(): array
    {
        $data = $this->validated();

        $decoded = json_decode($data['diagram_data'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $data['diagram_data'] = json_encode([
                'positions' => [], 'barres' => [], 'muted' => [6, 1], 'open' => [],
            ]);
        }

        $data['inversion'] = $data['inversion'] ?? 'root';
        $data['is_fixed_position'] = $data['is_fixed_position'] ?? false;

        return $data;
    }
}
