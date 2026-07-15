<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ApplyProgressionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'selections'              => ['required', 'array', 'min:1'],
            'selections.*.chord_name' => ['required', 'string', 'max:50'],
            'selections.*.frets'      => ['required', 'string', 'max:20'],
            'selections.*.position'   => ['nullable', 'integer', 'min:0', 'max:24'],
            'selections.*.measure_index' => ['nullable', 'integer', 'min:0'],
            'time_signature'          => ['nullable', 'string', 'regex:/^\d{1,2}\/\d{1,2}$/'],
        ];
    }
}
