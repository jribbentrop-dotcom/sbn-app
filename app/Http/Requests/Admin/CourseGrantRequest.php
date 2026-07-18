<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CourseGrantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isInstructor();
    }

    public function rules(): array
    {
        return [
            'email'      => ['required', 'email'],
            'course_id'  => ['required', 'exists:sbn_courses,id'],
            'source'     => ['required', Rule::in(['purchase', 'manual_grant', 'bundle', 'promo'])],
            'expires_at' => ['nullable', 'date'],
        ];
    }
}
