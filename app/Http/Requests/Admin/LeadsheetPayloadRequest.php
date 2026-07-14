<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class LeadsheetPayloadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isInstructor();
    }

    public function rules(): array
    {
        return [
            'title'             => ['required', 'string', 'max:255'],
            'slug'              => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'composer'          => ['nullable', 'string', 'max:255'],
            'song_key'          => ['nullable', 'string', 'max:10'],
            'tempo'             => ['nullable', 'integer', 'min:20', 'max:300'],
            'time_signature'    => ['nullable', 'string', 'max:10'],
            'rhythm'            => ['nullable', 'string', 'max:50'],
            'course_id'         => ['nullable', 'integer'],
            'shortcode_content' => ['nullable', 'string'],
            'json_data'         => ['nullable', 'string'],
            'tab_xml'           => ['nullable', 'string'],
            'chord_tab_xml'     => ['nullable', 'string'],
            'description'       => ['nullable', 'string', 'max:5000'],
            'harmony_notes'     => ['nullable', 'string', 'max:5000'],
            'form_notes'        => ['nullable', 'string', 'max:5000'],
            'voicing_notes'     => ['nullable', 'string', 'max:5000'],
            'genre'             => ['nullable', 'string', 'max:50'],
            'popularity'        => ['nullable', 'integer', 'min:0', 'max:100'],
            'difficulty'        => ['nullable', 'integer', 'min:0', 'max:5'],
            'version_label'     => ['nullable', 'string', 'max:120'],
            'version_performer' => ['nullable', 'string', 'max:120'],
            'arrangement_notes' => ['nullable', 'string', 'max:5000'],
            'tags'              => ['nullable', 'string', 'max:500'],
        ];
    }
}
