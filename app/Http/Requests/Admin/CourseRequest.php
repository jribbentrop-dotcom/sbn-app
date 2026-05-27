<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $courseId = $this->route('course')?->id;

        return [
            'title'               => ['required', 'string', 'max:255'],
            'slug'                => ['required', 'string', 'max:255', 'regex:/^[a-z0-9\-]+$/', Rule::unique('sbn_courses', 'slug')->ignore($courseId)],
            'excerpt'             => ['nullable', 'string'],
            'description'         => ['nullable', 'string'],
            'category'            => ['nullable', 'string', \Illuminate\Validation\Rule::in(\App\Models\ChordProgression::CATEGORIES)],
            'tags'                => ['nullable', 'string', 'max:500'],
            'levels_raw'          => ['nullable', 'string', Rule::in(['','basic','early-intermediate','intermediate','late-intermediate','advanced'])],
            'topics_raw'          => ['nullable', 'string'],
            'is_free'             => ['boolean'],
            'product_id'          => ['nullable', 'integer', 'exists:sbn_products,id'],
            'featured_image_path' => ['nullable', 'string', 'max:500'],
            'sort_order'          => ['integer'],
            'status'              => ['required', Rule::in(['publish', 'draft'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_free'    => $this->boolean('is_free'),
            'sort_order' => (int) ($this->input('sort_order', 0)),
        ]);
    }

    /** Return tag slugs as a clean array (separate from model data). */
    public function tagSlugs(): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $this->input('tags', ''))),
            fn ($s) => $s !== ''
        ));
    }

    /** Return data ready to pass to Course::create/update. */
    public function courseData(): array
    {
        $split = fn (?string $raw) => $raw
            ? array_values(array_filter(array_map('trim', explode(',', $raw))))
            : [];

        $data = $this->safe()->except(['genres_raw', 'levels_raw', 'topics_raw', 'tags']);

        return array_merge($data, [
            'levels' => $this->input('levels_raw') ? [$this->input('levels_raw')] : [],
            'topics' => $split($this->input('topics_raw')),
        ]);
    }
}
