<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRhythmDescriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'intro'   => 'nullable|string|max:10000',
            'details' => 'nullable|string|max:10000',
        ];
    }
}
