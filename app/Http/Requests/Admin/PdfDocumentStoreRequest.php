<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PdfDocumentStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isInstructor();
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'slug'  => ['required', 'string', 'max:255', Rule::unique('sbn_pdf_documents', 'slug')],
            'pages' => ['required', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('pages')) {
                return;
            }

            if (empty($this->pagesArray())) {
                $validator->errors()->add('pages', 'Select at least one page type.');
            }
        });
    }

    /** Decoded `pages` JSON — the Alpine form submits it as a string. */
    public function pagesArray(): array
    {
        return json_decode($this->input('pages'), true) ?? [];
    }
}
