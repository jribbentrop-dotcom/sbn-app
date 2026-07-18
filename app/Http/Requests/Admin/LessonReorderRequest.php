<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class LessonReorderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isInstructor();
    }

    public function rules(): array
    {
        return [
            'items'               => ['required', 'array'],
            'items.*.id'          => ['required', 'integer'],
            'items.*.sort_order'  => ['required', 'integer'],
        ];
    }
}
