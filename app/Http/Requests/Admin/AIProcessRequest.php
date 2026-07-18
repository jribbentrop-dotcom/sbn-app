<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AIProcessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isInstructor();
    }

    public function rules(): array
    {
        return [
            'action'  => ['required', 'string', Rule::in(['proofread', 'autocomplete', 'generate', 'chat', 'describe'])],
            'content' => ['required', 'string'],
            'context' => ['nullable', 'string'],
            // chat: prior turns as [{role, text}, ...]
            'history'         => ['nullable', 'array'],
            'history.*.role'  => ['required_with:history', 'string', Rule::in(['user', 'assistant'])],
            'history.*.text'  => ['required_with:history', 'string'],
            // chat: text the editor currently has selected (may be empty)
            'selection'  => ['nullable', 'string'],
            // describe: structured entity metadata
            'entityType' => ['nullable', 'string', Rule::in(['rhythm', 'progression', 'chord', 'leadsheet', 'course'])],
            'entityMeta' => ['nullable', 'array'],
            // chat: lesson + course metadata
            'lessonMeta' => ['nullable', 'array'],
        ];
    }
}
