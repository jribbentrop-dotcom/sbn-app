<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRhythmDescriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isInstructor();
    }

    public function rules(): array
    {
        return [
            'intro'   => 'nullable|string|max:10000',
            'details' => 'nullable|string|max:10000',
        ];
    }
}
