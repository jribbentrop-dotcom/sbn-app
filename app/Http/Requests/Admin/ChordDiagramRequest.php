<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ChordDiagramRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'root_note'         => 'required|string|max:3',
            'quality'           => 'required|string|max:20',
            'extensions'        => 'nullable|string|max:50',
            'voicing_category'  => 'required|string|max:30',
            'root_string'       => 'required|string|max:20',
            'inversion'         => 'nullable|string|max:10',
            'bass_note'         => 'nullable|string|max:3',
            'shape_family'      => 'nullable|string|max:50',
            'is_fixed_position' => 'boolean',
            'start_fret'        => 'required|integer|min:1|max:24',
            'diagram_data'      => 'required|string',
            'description'       => 'nullable|string|max:10000',
            'name'              => 'nullable|string|max:200',
            'slug'              => 'nullable|string|max:200',
        ];
    }
}
