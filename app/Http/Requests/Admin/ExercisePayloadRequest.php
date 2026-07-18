<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ExercisePayloadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isInstructor();
    }

    public function rules(): array
    {
        $exerciseId = $this->route('exercise')?->id;

        return [
            'slug'              => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9\-]+$/', Rule::unique('sbn_exercises', 'slug')->ignore($exerciseId)],
            'title'             => ['required', 'string', 'max:255'],
            'composer'          => ['nullable', 'string', 'max:255'],
            'key_center'        => ['required', 'string', 'max:4'],
            'time_sig'          => ['required', 'string', 'max:8'],
            'bpm_default'       => ['required', 'integer', 'min:40', 'max:320'],
            'rhythm'            => ['nullable', 'string', 'max:50'],
            'measure_count'     => ['nullable', 'integer'],
            'course_id'         => ['nullable', 'integer', 'exists:sbn_courses,id'],
            'type'              => ['required', 'string', 'in:tab_exercise,chord_etude'],
            'content_json'      => ['required', 'string'],
            'shortcode_content' => ['nullable', 'string'],
            'tab_xml'           => ['nullable', 'string'],
            'description'       => ['nullable', 'string'],
            'harmony_notes'     => ['nullable', 'string'],
            'form_notes'        => ['nullable', 'string'],
            'voicing_notes'     => ['nullable', 'string'],
            'popularity'        => ['nullable', 'integer'],
        ];
    }

    /** content_json must decode to an array — only checked once the field itself passed rules(). */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('content_json')) {
                return;
            }

            if (!is_array(json_decode($this->input('content_json'), true))) {
                $validator->errors()->add('content_json', 'Invalid JSON content.');
            }
        });
    }

    /** Validated data with content_json decoded, ready for Exercise::create/update. */
    public function payload(): array
    {
        $data = $this->validated();
        $data['content_json'] = json_decode($data['content_json'], true);

        return $data;
    }
}
