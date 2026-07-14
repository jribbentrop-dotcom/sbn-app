<?php

namespace App\Http\Requests\Admin;

use App\Models\ChordProgression;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ChordProgressionRequest extends FormRequest
{
    /** Max bars a snippet may span — the legal/architectural cap (plan §2/§7). */
    private const MAX_SNIPPET_BARS = 16;

    public function authorize(): bool
    {
        return (bool) $this->user()?->isInstructor();
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
            'name'           => ['required', 'string', 'max:120'],
            'slug'           => ['nullable', 'string', 'max:180', 'regex:/^[a-z0-9\-]+$/'],
            'category'       => ['required', 'string', Rule::in(ChordProgression::CATEGORIES)],
            'numerals'       => ['required', 'string', 'max:255'],
            'description'    => ['nullable', 'string'],
            'intro'          => ['nullable', 'string'],
            'details'        => ['nullable', 'string'],
            'tags'           => ['nullable', 'string', 'max:255'],
            'tonality'       => ['required', 'string', Rule::in(['both', 'major', 'minor'])],
            'match_mode'     => ['required', 'string', Rule::in(['strict', 'degree'])],
            'sort_order'     => ['nullable', 'integer'],
            'difficulty'     => ['nullable', 'integer', 'min:1', 'max:5'],
            'featured'       => ['nullable', 'boolean'],
            'alt_numerals'   => ['nullable', 'array'],
            'alt_numerals.*.label'    => ['required', 'string', 'max:100'],
            'alt_numerals.*.numerals' => ['required', 'string', 'max:255'],

            // Video snippet library — see docs/SBN-Course-Reference.md §10.
            'video_snippets'               => ['nullable', 'array'],
            'video_snippets.*.id'          => ['required', 'string', 'max:64'],
            'video_snippets.*.label'       => ['required', 'string', 'max:120'],
            'video_snippets.*.videoId'     => ['required', 'string', 'max:32'],
            'video_snippets.*.videoType'   => ['required', 'string', Rule::in(['youtube', 'hosted'])],
            'video_snippets.*.startSec'    => ['required', 'numeric', 'min:0'],
            'video_snippets.*.endSec'      => ['required', 'numeric', 'min:0'],
            'video_snippets.*.tempoBpm'    => ['required', 'numeric', 'min:20', 'max:300'],
            // Pinned voicings: the key the musician plays in + one chord slug per numeral slot.
            'video_snippets.*.key'         => ['nullable', 'string', 'max:4'],
            'video_snippets.*.chords'      => ['nullable', 'array'],
            'video_snippets.*.chords.*'    => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * Server-side enforcement of the snippet rules the authoring widget also
     * checks: end-after-start and the ≤16-bar cap (plan §2/§7). Progressions
     * have no time signature, so a 4-beat bar is assumed (matches the
     * widget's default). Only runs once the field-level rules above passed,
     * same as the previous manual-throw-after-validate() ordering.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $beatsPerBar = 4;
            foreach ($this->input('video_snippets', []) as $i => $s) {
                $start = (float) $s['startSec'];
                $end   = (float) $s['endSec'];
                $bpm   = (float) $s['tempoBpm'];

                if ($end <= $start) {
                    $validator->errors()->add("video_snippets.$i.endSec", 'End must be after start.');
                    continue;
                }

                $bars = (($end - $start) / 60) * $bpm / $beatsPerBar;
                if ($bars > self::MAX_SNIPPET_BARS) {
                    $validator->errors()->add(
                        "video_snippets.$i.endSec",
                        sprintf('Snippet spans %.1f bars — keep it to %d or fewer.', $bars, self::MAX_SNIPPET_BARS)
                    );
                }
            }
        });
    }

    /** Validated + normalized data, ready for ChordProgression::create/update. */
    public function payload(): array
    {
        $data = $this->validated();

        $data['sort_order']     = $data['sort_order'] ?? 0;
        $data['featured']       = $data['featured'] ?? false;
        $data['tags']           = $data['tags'] ?? '';
        $data['video_snippets'] = $this->buildSnippets($data['video_snippets'] ?? []);
        $data['alt_numerals']   = $data['alt_numerals'] ?? null;

        return $data;
    }

    private function buildSnippets(array $snippets): array
    {
        $clean = [];

        foreach ($snippets as $s) {
            $entry = [
                'id'        => $s['id'],
                'label'     => trim($s['label']),
                'videoId'   => $s['videoId'],
                'videoType' => $s['videoType'],
                'startSec'  => (float) $s['startSec'],
                'endSec'    => (float) $s['endSec'],
                'tempoBpm'  => (float) $s['tempoBpm'],
            ];

            if (!empty($s['key'])) {
                $entry['key'] = trim($s['key']);
            }
            if (!empty($s['chords']) && is_array($s['chords'])) {
                $entry['chords'] = array_values(array_map('strval', $s['chords']));
            }

            $clean[] = $entry;
        }

        return $clean;
    }
}
