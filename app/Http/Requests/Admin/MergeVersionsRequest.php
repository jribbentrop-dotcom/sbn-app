<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class MergeVersionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mother_version_slug' => 'required|string',
            'melody_version_id'   => 'required|integer|exists:sbn_leadsheet_versions,id',
            'chord_version_id'    => 'required|integer|exists:sbn_leadsheet_versions,id',
        ];
    }
}
