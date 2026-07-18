<?php

namespace App\Http\Requests\Admin;

use App\Models\Leadsheet;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateIsProRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is enforced by the route's ['auth', 'instructor'] middleware.
        return true;
    }

    public function rules(): array
    {
        return [
            'is_pro' => 'required|boolean',
        ];
    }

    /**
     * is_pro must only ever be true on public_domain rows — the full
     * Viewer/Cinema arrangement is only licensable for public-domain songs
     * (see CLAUDE.md "Content access model" and Leadsheet licensing rules).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $leadsheet = $this->route('leadsheet');

            if ($leadsheet && $this->boolean('is_pro')
                && $leadsheet->license_status !== Leadsheet::LICENSE_PUBLIC_DOMAIN) {
                $validator->errors()->add(
                    'is_pro',
                    "is_pro can only be enabled for public_domain leadsheets (this one is \"{$leadsheet->license_status}\")."
                );
            }
        });
    }
}
