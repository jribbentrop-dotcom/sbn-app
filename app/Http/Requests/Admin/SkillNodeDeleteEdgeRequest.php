<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SkillNodeDeleteEdgeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isInstructor();
    }

    public function rules(): array
    {
        return [
            'from'     => ['required', 'integer'],
            'requires' => ['required', 'integer'],
        ];
    }
}
