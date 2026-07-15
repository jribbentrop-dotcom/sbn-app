<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class PersistStemAsSyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'session' => 'required|string|max:64',
            'stem'    => 'required|string|in:guitar,bass,vocals,drums,piano,other',
        ];
    }
}
