<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ExerciseSliceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isInstructor();
    }

    public function rules(): array
    {
        return [
            'measure_indices'   => ['required', 'array', 'min:1'],
            'measure_indices.*' => ['integer', 'min:0'],
            'title'             => ['nullable', 'string', 'max:255'],
        ];
    }
}
