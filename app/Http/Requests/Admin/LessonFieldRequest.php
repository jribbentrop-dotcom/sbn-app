<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LessonFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isInstructor();
    }

    public function rules(): array
    {
        return [
            'field' => ['required', 'string', Rule::in(['section_title', 'title', 'status'])],
            'value' => ['nullable', 'string', 'max:255'],
        ];
    }
}
