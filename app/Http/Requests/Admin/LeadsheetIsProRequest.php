<?php

namespace App\Http\Requests\Admin;

use App\Models\Leadsheet;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class LeadsheetIsProRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isInstructor();
    }

    public function rules(): array
    {
        return [
            'is_pro' => ['required', 'boolean'],
        ];
    }

    /** is_pro must only ever be true on public_domain rows (see CLAUDE.md content access model). */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $leadsheet = $this->route('leadsheet');

            if ($this->boolean('is_pro') && $leadsheet->license_status !== Leadsheet::LICENSE_PUBLIC_DOMAIN) {
                $validator->errors()->add(
                    'is_pro',
                    "is_pro can only be enabled for public_domain leadsheets (this one is \"{$leadsheet->license_status}\")."
                );
            }
        });
    }
}
