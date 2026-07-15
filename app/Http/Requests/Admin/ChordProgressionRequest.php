<?php

namespace App\Http\Requests\Admin;

use App\Models\ChordProgression;
use Illuminate\Foundation\Http\FormRequest;

class ChordProgressionRequest extends FormRequest
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
        return [
            'name'           => 'required|string|max:120',
            'slug'           => 'nullable|string|max:180|regex:/^[a-z0-9\-]+$/',
            'category'       => 'required|string|in:' . implode(',', ChordProgression::CATEGORIES),
            'numerals'       => 'required|string|max:255',
            'description'    => 'nullable|string',
            'intro'          => 'nullable|string',
            'details'        => 'nullable|string',
            'tags'           => 'nullable|string|max:255',
            'tonality'       => 'required|string|in:both,major,minor',
            'match_mode'     => 'required|string|in:strict,degree',
            'sort_order'     => 'nullable|integer',
            'difficulty'     => 'nullable|integer|min:1|max:5',
            'featured'       => 'nullable|boolean',
            'alt_numerals'   => 'nullable|array',
            'alt_numerals.*.label'    => 'required|string|max:100',
            'alt_numerals.*.numerals' => 'required|string|max:255',

            // Video snippet library — see docs/SBN-Course-Reference.md §10.
            'video_snippets'               => 'nullable|array',
            'video_snippets.*.id'          => 'required|string|max:64',
            'video_snippets.*.label'       => 'required|string|max:120',
            'video_snippets.*.videoId'     => 'required|string|max:32',
            'video_snippets.*.videoType'   => 'required|string|in:youtube,hosted',
            'video_snippets.*.startSec'    => 'required|numeric|min:0',
            'video_snippets.*.endSec'      => 'required|numeric|min:0',
            'video_snippets.*.tempoBpm'    => 'required|numeric|min:20|max:300',
            // Pinned voicings: the key the musician plays in + one chord slug per numeral slot.
            'video_snippets.*.key'         => 'nullable|string|max:4',
            'video_snippets.*.chords'      => 'nullable|array',
            'video_snippets.*.chords.*'    => 'nullable|string|max:120',
        ];
    }
}
