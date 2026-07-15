<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SaveArchetypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'        => 'required|string|max:120',
            'description' => 'nullable|string|max:2000',
        ];
    }
}
