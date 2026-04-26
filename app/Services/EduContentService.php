<?php

namespace App\Services;

/**
 * Source of truth for educational text content shown alongside chords,
 * progressions, rhythms, and other study aids.
 *
 * Currently config-file backed. Future: replace the config lookup with an
 * Eloquent query against the edu_topics table. Public method signatures will
 * not change.
 */
class EduContentService
{
    /**
     * Look up a chord quality blurb by canonical quality slug.
     *
     * @param  string  $qualitySlug  e.g. 'maj7', 'm7b5', 'dom7'
     * @return array{title:string,blurb:string}|null
     */
    public function chordQuality(string $qualitySlug): ?array
    {
        return config("edu.chord-qualities.$qualitySlug");
    }

    /**
     * Bundle all chord-quality blurbs in one shot — used when the consumer
     * needs offline lookup over a known set (e.g. an Inertia payload that
     * surfaces blurbs for every chord on the page).
     *
     * @return array<string, array{title:string,blurb:string}>
     */
    public function allChordQualities(): array
    {
        return config('edu.chord-qualities', []);
    }

    /**
     * Look up multiple chord quality blurbs by their slugs.
     * Useful when you have a set of qualities used on a page.
     *
     * @param  string[]  $qualitySlugs
     * @return array<string, array{title:string,blurb:string}>
     */
    public function chordQualities(array $qualitySlugs): array
    {
        $all = $this->allChordQualities();
        $result = [];
        foreach ($qualitySlugs as $slug) {
            if (isset($all[$slug])) {
                $result[$slug] = $all[$slug];
            }
        }
        return $result;
    }
}
