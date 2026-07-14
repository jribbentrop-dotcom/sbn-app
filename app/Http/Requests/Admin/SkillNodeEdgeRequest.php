<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SkillNodeEdgeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isInstructor();
    }

    public function rules(): array
    {
        return [
            'from'     => ['required', 'integer', 'exists:sbn_skill_nodes,id'],      // dependent
            'requires' => ['required', 'integer', 'exists:sbn_skill_nodes,id', 'different:from'], // prerequisite
        ];
    }
}
