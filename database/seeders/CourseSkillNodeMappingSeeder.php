<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\SkillNode;
use Illuminate\Database\Seeder;

/**
 * Additive course→node mapping fixes from the 2026-06-23 content audit.
 * See docs/SBN-Skill-Node-Expansion-Audit-2026-06-23.md for the evidence
 * behind each mapping (lesson titles, content_tag_slug bridges, etc.).
 *
 * Must run AFTER SkillNodeSeeder (DatabaseSeeder already orders it that way)
 * since several of these node slugs are new in this same audit pass.
 *
 * Uses syncWithoutDetaching, so it only ADDS pivot rows — existing
 * sbn_course_skill_node rows curated by hand in the admin editor are left
 * untouched. Safe to re-run.
 */
class CourseSkillNodeMappingSeeder extends Seeder
{
    /**
     * course slug => [skill node slugs to add]
     */
    private const MAPPINGS = [
        // Course 70 — had 8 lessons, only 3 nodes (triads/chord-inversions/
        // ii-v-i-major) before this pass. Closes lessons 1–8.
        'chord-progressions-and-voice-leading' => [
            'diatonic-harmony', 'cadences', 'pop-progressions', 'turnarounds',
            'secondary-dominants', 'borrowed-chords', 'voice-leading',
        ],

        // Course 5 — was the only published course with ZERO node mapping.
        'choro-guitar-masterpieces' => [
            'brazilian-rhythm-styles', 'clave-systems', 'two-four-feel',
            'syncopation', 'pulse-subdivision',
        ],

        // Course 10 is literally named "The Clave: Latin Rhythm 101" but had
        // no clave node — only the generic pulse/feel/syncopation trio.
        'the-clave' => ['clave-systems'],

        // Both are swing-jazz guitar courses with no swing-feel node.
        'latin-side-pat-metheny' => ['swing-feel'],
        'latin-side-wes-montgomery' => ['swing-feel'],

        // Source of the scale-degrees / tab-reading-basics / meter-basics
        // widgets (scale-steps, tab-diagram, time-signature).
        'music-theory-basics' => ['scale-degrees', 'tab-reading-basics', 'meter-basics'],

        // Rhythm-changes-adjacent progressions (ascending-diminished, the
        // rhythm-changes-progression) overlap turnarounds/secondary dominants.
        'diminished-chords-bossa-nova' => ['secondary-dominants', 'turnarounds'],
    ];

    public function run(): void
    {
        foreach (self::MAPPINGS as $courseSlug => $nodeSlugs) {
            $course = Course::where('slug', $courseSlug)->first();
            if (! $course) {
                $this->command?->warn("CourseSkillNodeMappingSeeder: unknown course slug '{$courseSlug}', skipping.");
                continue;
            }

            $nodeIds = SkillNode::whereIn('slug', $nodeSlugs)->pluck('id', 'slug');

            $missing = array_diff($nodeSlugs, $nodeIds->keys()->all());
            foreach ($missing as $slug) {
                $this->command?->warn("CourseSkillNodeMappingSeeder: unknown skill node slug '{$slug}' for course '{$courseSlug}', skipping.");
            }

            if ($nodeIds->isNotEmpty()) {
                $course->skillNodes()->syncWithoutDetaching($nodeIds->values()->all());
            }
        }
    }
}
