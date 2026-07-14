<?php

namespace App\Http\Requests\Admin;

use App\Models\ChordDiagram;
use App\Models\SkillNode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SkillNodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isInstructor();
    }

    public function rules(): array
    {
        return [
            'title'            => ['required', 'string', 'max:255'],
            'slug'             => ['nullable', 'string', 'max:120'],
            'branch'           => ['required', Rule::in(SkillNode::BRANCHES)],
            'sub_branch'       => ['nullable', 'string', 'max:120'],
            'description'      => ['nullable', 'string', 'max:2000'],
            'content_tag_slug' => ['nullable', 'string', 'max:120'],
            'grade'            => ['nullable', 'integer', 'min:1', 'max:5'],
            'icon_key'         => ['nullable', 'string', 'max:120'],
            'sort_order'       => ['nullable', 'integer', 'min:0'],
            'prereqs'          => ['nullable', 'array'],
            'prereqs.*'        => ['integer', 'exists:sbn_skill_nodes,id'],
            'courses'          => ['nullable', 'array'],
            'courses.*'        => ['integer', 'exists:sbn_courses,id'],
            'styles'           => ['nullable', 'array'],
            'styles.*'         => ['integer', 'min:0', 'max:3'], // keyed by style slug; 0 = not tagged
            'rhythm_patterns'    => ['nullable', 'array'],
            'rhythm_patterns.*'  => ['integer', 'exists:sbn_rhythm_patterns,id'],
            'chord_progressions'   => ['nullable', 'array'],
            'chord_progressions.*' => ['integer', 'exists:sbn_chord_progressions,id'],
            'voicing_categories'   => ['nullable', 'array'],
            'voicing_categories.*' => ['string', Rule::in(array_keys(ChordDiagram::VOICING_CATEGORIES))],
            'leadsheets'         => ['nullable', 'array'],
            'leadsheets.*'       => ['integer', 'exists:sbn_leadsheets,id'],
        ];
    }

    /**
     * @return array{attributes:array,prereqs:array<int>,courses:array<int>,styles:array,rhythmPatterns:array<int>,chordProgressions:array<int>,leadsheets:array<int>}
     */
    public function payload(): array
    {
        $raw = $this->validated();
        $exceptId = $this->route('skillNode')?->id;

        $attributes = [
            'title'            => $raw['title'],
            'slug'             => $this->uniqueSlug($raw['slug'] ?: $raw['title'], $exceptId),
            'branch'           => $raw['branch'],
            'sub_branch'       => $raw['sub_branch'] ?? null,
            'description'      => $raw['description'] ?? null,
            'content_tag_slug' => $raw['content_tag_slug'] ?: null,
            'grade'            => $raw['grade'] ?? null,
            'icon_key'         => $raw['icon_key'] ?: null,
            'completion_type'  => SkillNode::COMPLETION_SELF_REPORT, // v1: fixed
            'sort_order'       => $raw['sort_order'] ?? 0,
            // Chord voicings link by category (stored on the node, not a pivot).
            'voicing_categories' => array_values($raw['voicing_categories'] ?? []) ?: null,
        ];

        // Style weights come in as { style-slug => weight }; drop 0s (untagged)
        // and anything outside the controlled vocabulary (syncStyles re-checks too).
        $styleWeights = [];
        foreach (($raw['styles'] ?? []) as $style => $weight) {
            if ((int) $weight > 0 && in_array($style, SkillNode::STYLES, true)) {
                $styleWeights[$style] = (int) $weight;
            }
        }

        // A node can never be its own prerequisite.
        $prereqs = array_values(array_filter(
            $raw['prereqs'] ?? [],
            fn ($id) => (int) $id !== (int) $exceptId,
        ));

        return [
            'attributes' => $attributes,
            'prereqs'    => $prereqs,
            'courses'    => $raw['courses'] ?? [],
            'styles'     => $styleWeights,
            'rhythmPatterns'    => $raw['rhythm_patterns'] ?? [],
            'chordProgressions' => $raw['chord_progressions'] ?? [],
            'leadsheets'        => $raw['leadsheets'] ?? [],
        ];
    }

    private function uniqueSlug(string $slug, ?int $exceptId): string
    {
        $base = Str::slug($slug) ?: 'skill-node';
        $candidate = $base;
        $i = 2;
        while (
            SkillNode::where('slug', $candidate)
                ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
                ->exists()
        ) {
            $candidate = $base . '-' . $i++;
        }

        return $candidate;
    }
}
