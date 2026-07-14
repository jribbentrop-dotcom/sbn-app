<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LeadsheetCreateBlankRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isInstructor();
    }

    public function rules(): array
    {
        return [
            'title'                => ['required', 'string', 'max:255'],
            'composer'             => ['nullable', 'string', 'max:255'],
            'song_key'             => ['required', 'string', 'max:10'],
            'tempo'                => ['required', 'integer', 'min:20', 'max:300'],
            'time_signature'       => ['required', 'string', 'max:10'],
            'rhythm'               => ['nullable', 'string', 'max:50'],
            'structure_mode'       => ['required', Rule::in(['simple', 'sectioned'])],
            'simple_bar_count'     => ['required_if:structure_mode,simple', 'integer', 'min:1', 'max:256'],
            'sections'             => ['required_if:structure_mode,sectioned', 'array', 'min:1', 'max:20'],
            'sections.*.name'      => ['required', 'string', 'max:50'],
            'sections.*.bars'      => ['required', 'integer', 'min:1', 'max:64'],
            'pickup_bar'           => ['nullable', 'boolean'],
        ];
    }
}
