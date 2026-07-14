<?php

namespace App\Http\Requests\Admin;

use App\Models\RhythmPattern;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RhythmPatternRequest extends FormRequest
{
    /** Max bars a snippet may span — the legal/architectural cap. */
    private const MAX_SNIPPET_BARS = 16;

    public function authorize(): bool
    {
        return (bool) $this->user()?->isInstructor();
    }

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
            'name'           => ['required', 'string', 'max:100'],
            'slug'           => [
                'nullable', 'string', 'max:50', 'regex:/^[a-z0-9\-]+$/',
                Rule::unique('sbn_rhythm_patterns', 'slug')->ignore($rhythmId),
            ],
            'description'    => ['nullable', 'string', 'max:10000'],
            'intro'          => ['nullable', 'string', 'max:10000'],
            'details'        => ['nullable', 'string', 'max:10000'],
            'category'       => ['nullable', 'string', Rule::in(RhythmPattern::CATEGORIES)],
            'tags'           => ['nullable', 'string', 'max:500'],
            'time_signature' => ['nullable', 'string', Rule::in(['2/4', '3/4', '4/4', '6/8'])],
            'beats'          => ['nullable', 'integer', 'min:2', 'max:64'],
            'grid_type'      => ['nullable', 'string', Rule::in(['eighth', 'sixteenth', 'triplet'])],
            'rhythm_pattern' => ['required', 'string', 'max:32', 'regex:/^[xX\.]+$/'],
            'thumb_pattern'  => ['nullable', 'string', 'max:32', 'regex:/^[xX\.]*$/'],
            'picking_mode'   => ['nullable', 'boolean'],
            'finger_index'   => ['nullable', 'string', 'max:64', 'regex:/^[xX\.]*$/'],
            'finger_middle'  => ['nullable', 'string', 'max:64', 'regex:/^[xX\.]*$/'],
            'finger_ring'    => ['nullable', 'string', 'max:64', 'regex:/^[xX\.]*$/'],
            'default_bpm'    => ['nullable', 'integer', 'min:40', 'max:240'],
            'sound'          => ['nullable', 'string', 'max:20'],
            'perc_top'       => ['nullable', 'string', Rule::in(['none', 'shaker', 'tamborim', 'hihat-brush', 'brush-snare'])],
            'perc_bass'      => ['nullable', 'string', Rule::in(['none', 'kick'])],
            'mp3_file'       => ['nullable', 'string', 'max:255'],
            'difficulty'     => ['nullable', 'integer', 'min:1', 'max:5'],

            // Video snippet library — see docs/SBN-Course-Reference.md §10.
            'video_snippets'             => ['nullable', 'array'],
            'video_snippets.*.id'        => ['required', 'string', 'max:64'],
            'video_snippets.*.label'     => ['required', 'string', 'max:120'],
            'video_snippets.*.videoId'   => ['required', 'string', 'max:32'],
            'video_snippets.*.videoType' => ['required', 'string', Rule::in(['youtube', 'hosted'])],
            'video_snippets.*.startSec'  => ['required', 'numeric', 'min:0'],
            'video_snippets.*.endSec'    => ['required', 'numeric', 'min:0'],
            'video_snippets.*.tempoBpm'  => ['required', 'numeric', 'min:20', 'max:300'],
        ];
    }

    /**
     * Server-side enforcement of the snippet rules the authoring widget also
     * checks: end-after-start and the ≤16-bar cap, bar count derived from
     * the pattern's time signature. Only runs once the field-level rules
     * above passed, same as the previous manual-throw-after-validate()
     * ordering. See docs/SBN-Course-Reference.md §10.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $timeSignature = $this->input('time_signature', '4/4');
            $beatsPerBar = (int) (explode('/', $timeSignature)[0] ?? 4) ?: 4;

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

    /** [data, tagSlugs] — data ready for RhythmPattern::create/update, tags split out. */
    public function payload(): array
    {
        $data = $this->validated();

        $data['video_snippets'] = $this->buildSnippets($data['video_snippets'] ?? []);

        $tagSlugs = array_filter(
            array_map('trim', explode(',', $data['tags'] ?? '')),
            fn ($s) => $s !== ''
        );
        unset($data['tags']);

        return [$data, array_values($tagSlugs)];
    }

    private function buildSnippets(array $snippets): array
    {
        $clean = [];

        foreach ($snippets as $s) {
            $clean[] = [
                'id'        => $s['id'],
                'label'     => trim($s['label']),
                'videoId'   => $s['videoId'],
                'videoType' => $s['videoType'],
                'startSec'  => (float) $s['startSec'],
                'endSec'    => (float) $s['endSec'],
                'tempoBpm'  => (float) $s['tempoBpm'],
            ];
        }

        return $clean;
    }
}
