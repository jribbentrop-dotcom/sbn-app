<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SkillNodeLayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isInstructor();
    }

    public function rules(): array
    {
        return [
            'positions'       => ['required', 'array'],
            'positions.*.id'  => ['required', 'integer', 'exists:sbn_skill_nodes,id'],
            'positions.*.x'   => ['required', 'integer'],
            'positions.*.y'   => ['required', 'integer'],
        ];
    }
}
