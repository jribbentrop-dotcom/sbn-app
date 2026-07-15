<?php

namespace App\Http\Requests\Admin;

use App\Models\RhythmPattern;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RhythmPatternRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The video-snippet widget submits its library as a JSON string in a
     * hidden field (classic form POST). Decode it to an array before
     * validation so the per-snippet rules can apply.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('video_snippets') && is_string($this->input('video_snippets'))) {
            $decoded = json_decode($this->input('video_snippets'), true);
            $this->merge(['video_snippets' => is_array($decoded) ? $decoded : []]);
        }
    }

    public function rules(): array
    {
        $rhythmId = $this->route('rhythm')?->id;

        return [
            'name'           => 'required|string|max:100',
            'slug'           => [
                'nullable', 'string', 'max:50', 'regex:/^[a-z0-9\-]+$/',
                Rule::unique('sbn_rhythm_patterns', 'slug')->ignore($rhythmId),
            ],
            'description'    => 'nullable|string|max:10000',
            'intro'          => 'nullable|string|max:10000',
            'details'        => 'nullable|string|max:10000',
            'category'       => ['nullable', 'string', Rule::in(RhythmPattern::CATEGORIES)],
            'tags'           => 'nullable|string|max:500',
            'time_signature' => 'nullable|string|in:2/4,3/4,4/4,6/8',
            'beats'          => 'nullable|integer|min:2|max:64',
            'grid_type'      => 'nullable|string|in:eighth,sixteenth,triplet',
            'rhythm_pattern' => 'required|string|max:32|regex:/^[xX\.]+$/',
            'thumb_pattern'  => 'nullable|string|max:32|regex:/^[xX\.]*$/',
            'picking_mode'   => 'nullable|boolean',
            'finger_index'   => 'nullable|string|max:64|regex:/^[xX\.]*$/',
            'finger_middle'  => 'nullable|string|max:64|regex:/^[xX\.]*$/',
            'finger_ring'    => 'nullable|string|max:64|regex:/^[xX\.]*$/',
            'default_bpm'    => 'nullable|integer|min:40|max:240',
            'sound'          => 'nullable|string|max:20',
            'perc_top'       => 'nullable|string|in:none,shaker,tamborim,hihat-brush,brush-snare',
            'perc_bass'      => 'nullable|string|in:none,kick',
            'mp3_file'       => 'nullable|string|max:255',
            'difficulty'     => 'nullable|integer|min:1|max:5',

            // Video snippet library — see docs/SBN-Course-Reference.md §10.
            'video_snippets'             => 'nullable|array',
            'video_snippets.*.id'        => 'required|string|max:64',
            'video_snippets.*.label'     => 'required|string|max:120',
            'video_snippets.*.videoId'   => 'required|string|max:32',
            'video_snippets.*.videoType' => 'required|string|in:youtube,hosted',
            'video_snippets.*.startSec'  => 'required|numeric|min:0',
            'video_snippets.*.endSec'    => 'required|numeric|min:0',
            'video_snippets.*.tempoBpm'  => 'required|numeric|min:20|max:300',
        ];
    }
}
