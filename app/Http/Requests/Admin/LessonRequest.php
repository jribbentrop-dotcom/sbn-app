<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LessonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $lessonId = $this->route('lesson')?->id;
        $courseId = $this->route('lesson')?->course_id
            ?? $this->route('course')?->id;

        return [
            'title'         => ['required', 'string', 'max:255'],
            'slug'          => [
                'required', 'string', 'max:255', 'regex:/^[a-z0-9\-]+$/',
                Rule::unique('sbn_lessons')->where('course_id', $courseId)->ignore($lessonId),
            ],
            'section_title' => ['nullable', 'string', 'max:255'],
            'content'       => ['nullable', 'string'],
            'is_preview'    => ['boolean'],
            'sort_order'    => ['integer'],
            'status'        => ['required', Rule::in(['publish', 'draft'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_preview' => $this->boolean('is_preview'),
            'sort_order' => (int) ($this->input('sort_order', 0)),
        ]);
    }
}
