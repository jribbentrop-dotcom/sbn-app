<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PdfDocumentUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isInstructor();
    }

    public function rules(): array
    {
        return [
            'title'   => ['nullable', 'string', 'max:255'],
            'status'  => ['nullable', 'string', Rule::in(['draft', 'publish'])],
            'content' => ['nullable'],
        ];
    }

    /**
     * Content comes in as a JSON string from the Alpine form, or already
     * an array. Returns null if the field was omitted (caller falls back
     * to the existing document's content in that case).
     */
    public function contentValue(): mixed
    {
        $content = $this->input('content');

        if (is_string($content)) {
            $content = json_decode($content, true) ?? [];
        }

        return $content;
    }
}
