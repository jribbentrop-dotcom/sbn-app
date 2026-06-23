<?php

namespace Database\Seeders;

use App\Models\SkillNode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the v1 skill graph across all six branches: Harmony, Rhythm, Melody,
 * Technique, Ear Training, and Reading & Theory, plus their prerequisite edges.
 * Some edges cross branches deliberately (e.g. Ear Training's
 * interval-recognition requires Harmony's intervals) — the graph is meant to
 * be cross-cutting, not six independent trees. See "Key Design Principles" in
 * docs/SBN-Skill-System-Plan.md.
 *
 * Idempotent — keyed on slug via updateOrCreate, edges via insertOrIgnore.
 * Re-running is safe. Harmony and Rhythm were curated against existing course
 * content (see SBN-Skill-System-Plan.md "Course → Node Mapping"); Melody,
 * Technique, Ear Training, and Reading & Theory are seeded from the taxonomy
 * first draft and still need the same content-evidence pass before being
 * treated as load-bearing.
 *
 * 2026-06-23 expansion: added meter-basics, waltz-feel, polyrhythm, swing-feel,
 * clave-systems, brazilian-rhythm-styles (Rhythm); diatonic-harmony, cadences,
 * pop-progressions, turnarounds, secondary-dominants, borrowed-chords,
 * voice-leading (Harmony); scale-degrees, tab-reading-basics (Reading & Theory).
 * Every one of these is backed by specific existing content (a lesson, a
 * `sbn_chord_progressions`/`sbn_rhythm_patterns` row, or a theory widget) — see
 * docs/SBN-Skill-Node-Expansion-Audit-2026-06-23.md for the evidence per node.
 * Deliberately NOT included: `fretboard-note-names` — a real gap (CAGED,
 * position-shifting, and shell-voicings all assume it) but no lesson currently
 * teaches it, so seeding the node now would leave it pointing at nothing.
 */
class SkillNodeSeeder extends Seeder
{
    /**
     * Node definitions: slug => [title, branch, sub_branch, content_tag_slug?, prerequisites[]]
     * Prerequisites reference other slugs in this list; wired in a second pass.
     */
    private const NODES = [
        // ── Harmony ──────────────────────────────────────────────────────────
        'intervals' => [
            'title' => 'Intervals', 'branch' => 'harmony', 'sub_branch' => 'Foundations',
            'prereqs' => [],
        ],
        'triads' => [
            'title' => 'Triads', 'branch' => 'harmony', 'sub_branch' => 'Foundations',
            'prereqs' => ['intervals'],
        ],
        'chord-inversions' => [
            'title' => 'Chord Inversions', 'branch' => 'harmony', 'sub_branch' => 'Foundations',
            'prereqs' => ['triads'],
        ],
        'shell-voicings' => [
            'title' => 'Shell Voicings (3+7)', 'branch' => 'harmony', 'sub_branch' => 'Voicings',
            'prereqs' => ['triads'],
        ],
        'drop2-voicings' => [
            'title' => 'Drop 2 Voicings', 'branch' => 'harmony', 'sub_branch' => 'Voicings',
            'prereqs' => ['shell-voicings', 'chord-inversions'],
        ],
        'drop3-voicings' => [
            'title' => 'Drop 3 Voicings', 'branch' => 'harmony', 'sub_branch' => 'Voicings',
            'prereqs' => ['drop2-voicings'],
        ],
        'ii-v-i-major' => [
            'title' => 'ii-V-I in Major', 'branch' => 'harmony', 'sub_branch' => 'Progressions',
            'prereqs' => ['shell-voicings'],
        ],
        'ii-v-i-minor' => [
            'title' => 'ii-V-I in Minor', 'branch' => 'harmony', 'sub_branch' => 'Progressions',
            'prereqs' => ['ii-v-i-major'],
        ],
        'tritone-substitution' => [
            'title' => 'Tritone Substitution', 'branch' => 'harmony', 'sub_branch' => 'Reharmonization',
            'prereqs' => ['ii-v-i-major'],
        ],
        'chord-melody' => [
            'title' => 'Chord Melody', 'branch' => 'harmony', 'sub_branch' => 'Reharmonization',
            'prereqs' => ['drop2-voicings', 'ii-v-i-major'],
        ],

        // ── Harmony (2026-06-23 addition — Course 70 had 8 lessons, 3 nodes) ───
        // Evidence: docs/SBN-Skill-Node-Expansion-Audit-2026-06-23.md
        'diatonic-harmony' => [
            'title' => "Diatonic Harmony (Building the Scale's Chords)", 'branch' => 'harmony', 'sub_branch' => 'Foundations',
            'prereqs' => ['triads'],
        ],
        'cadences' => [
            'title' => 'Cadences (Classical)', 'branch' => 'harmony', 'sub_branch' => 'Progressions',
            'prereqs' => ['diatonic-harmony'],
        ],
        'pop-progressions' => [
            'title' => 'Pop & Folk Progressions', 'branch' => 'harmony', 'sub_branch' => 'Progressions',
            'prereqs' => ['triads'], // deliberately not behind ii-V-I — earliest reachable harmony node
        ],
        'turnarounds' => [
            'title' => 'Turnarounds', 'branch' => 'harmony', 'sub_branch' => 'Progressions',
            'prereqs' => ['ii-v-i-major'],
        ],
        'secondary-dominants' => [
            'title' => 'Secondary Dominants', 'branch' => 'harmony', 'sub_branch' => 'Reharmonization',
            'prereqs' => ['ii-v-i-major'],
        ],
        'borrowed-chords' => [
            'title' => 'Borrowed Chords / Modal Interchange', 'branch' => 'harmony', 'sub_branch' => 'Reharmonization',
            'prereqs' => ['diatonic-harmony', 'cadences'],
        ],
        'voice-leading' => [
            'title' => 'Smooth Voice Leading', 'branch' => 'harmony', 'sub_branch' => 'Voicings',
            'prereqs' => ['drop2-voicings'],
        ],

        // ── Rhythm ───────────────────────────────────────────────────────────
        'meter-basics' => [
            'title' => 'Meter & Time Signatures', 'branch' => 'rhythm', 'sub_branch' => 'Foundations',
            'prereqs' => [], // new floor node — see pulse-subdivision below
        ],
        'pulse-subdivision' => [
            'title' => 'Pulse & Subdivision', 'branch' => 'rhythm', 'sub_branch' => 'Foundations',
            'prereqs' => ['meter-basics'],
        ],
        'two-four-feel' => [
            'title' => '2/4 Feel (Bossa / Samba)', 'branch' => 'rhythm', 'sub_branch' => 'Feels',
            'content_tag_slug' => 'samba', 'prereqs' => ['pulse-subdivision'],
        ],
        'syncopation' => [
            'title' => 'Syncopation', 'branch' => 'rhythm', 'sub_branch' => 'Feels',
            'prereqs' => ['pulse-subdivision'],
        ],
        'waltz-feel' => [
            'title' => '3/4 / Waltz Feel', 'branch' => 'rhythm', 'sub_branch' => 'Feels',
            'prereqs' => ['pulse-subdivision'],
        ],
        'swing-feel' => [
            'title' => 'Swing Feel', 'branch' => 'rhythm', 'sub_branch' => 'Feels',
            'prereqs' => ['pulse-subdivision'],
        ],
        'polyrhythm' => [
            'title' => 'Polyrhythm', 'branch' => 'rhythm', 'sub_branch' => 'Feels',
            'prereqs' => ['syncopation'],
        ],
        'comping-patterns' => [
            'title' => 'Comping Patterns', 'branch' => 'rhythm', 'sub_branch' => 'Application',
            'prereqs' => ['two-four-feel', 'syncopation'],
        ],
        'clave-systems' => [
            'title' => 'Clave Systems', 'branch' => 'rhythm', 'sub_branch' => 'Latin Rhythm',
            'prereqs' => ['two-four-feel'],
        ],
        'brazilian-rhythm-styles' => [
            'title' => 'Brazilian & Afro-Latin Rhythm Styles', 'branch' => 'rhythm', 'sub_branch' => 'Latin Rhythm',
            'prereqs' => ['clave-systems', 'two-four-feel'],
        ],

        // ── Melody ───────────────────────────────────────────────────────────
        'scale-patterns' => [
            'title' => 'Scale Patterns', 'branch' => 'melody', 'sub_branch' => 'Foundations',
            'prereqs' => [],
        ],
        'pentatonic-scale' => [
            'title' => 'Pentatonic Scale', 'branch' => 'melody', 'sub_branch' => 'Scales',
            'prereqs' => ['scale-patterns'],
        ],
        'arpeggio-shapes' => [
            'title' => 'Arpeggio Shapes', 'branch' => 'melody', 'sub_branch' => 'Scales',
            'prereqs' => ['scale-patterns', 'triads'],
        ],
        'motivic-development' => [
            'title' => 'Motivic Development', 'branch' => 'melody', 'sub_branch' => 'Application',
            'prereqs' => ['scale-patterns'],
        ],
        'improvisation-over-changes' => [
            'title' => 'Improvisation Over Changes', 'branch' => 'melody', 'sub_branch' => 'Application',
            'prereqs' => ['arpeggio-shapes', 'motivic-development', 'ii-v-i-major'],
        ],

        // ── Technique ────────────────────────────────────────────────────────
        'fingerpicking-basics' => [
            'title' => 'Fingerpicking Basics', 'branch' => 'technique', 'sub_branch' => 'Fingerstyle',
            'prereqs' => [],
        ],
        'right-hand-independence' => [
            'title' => 'Right Hand Independence', 'branch' => 'technique', 'sub_branch' => 'Fingerstyle',
            'prereqs' => ['fingerpicking-basics'],
        ],
        'thumb-independence' => [
            'title' => 'Thumb Independence', 'branch' => 'technique', 'sub_branch' => 'Fingerstyle',
            'prereqs' => ['fingerpicking-basics'],
        ],
        'caged-system' => [
            'title' => 'CAGED System', 'branch' => 'technique', 'sub_branch' => 'Fretboard',
            'prereqs' => [],
        ],
        'barre-chords' => [
            'title' => 'Barre Chords', 'branch' => 'technique', 'sub_branch' => 'Fretboard',
            'prereqs' => ['caged-system'],
        ],
        'position-shifting' => [
            'title' => 'Position Shifting', 'branch' => 'technique', 'sub_branch' => 'Fretboard',
            'prereqs' => ['caged-system'],
        ],
        'legato-slurs' => [
            'title' => 'Legato / Slurs', 'branch' => 'technique', 'sub_branch' => 'Articulation',
            'prereqs' => [],
        ],
        'tone-production' => [
            'title' => 'Tone Production', 'branch' => 'technique', 'sub_branch' => 'Articulation',
            'prereqs' => ['fingerpicking-basics'],
        ],

        // ── Ear Training ─────────────────────────────────────────────────────
        'interval-recognition' => [
            'title' => 'Interval Recognition', 'branch' => 'ear-training', 'sub_branch' => 'Recognition',
            'prereqs' => ['intervals'],
        ],
        'chord-quality-recognition' => [
            'title' => 'Chord Quality Recognition', 'branch' => 'ear-training', 'sub_branch' => 'Recognition',
            'prereqs' => ['interval-recognition', 'triads'],
        ],
        'rhythm-dictation' => [
            'title' => 'Rhythm Dictation', 'branch' => 'ear-training', 'sub_branch' => 'Dictation',
            'prereqs' => ['pulse-subdivision'],
        ],
        'melodic-dictation' => [
            'title' => 'Melodic Dictation', 'branch' => 'ear-training', 'sub_branch' => 'Dictation',
            'prereqs' => ['interval-recognition', 'scale-patterns'],
        ],

        // ── Reading & Theory ─────────────────────────────────────────────────
        'standard-notation-basics' => [
            'title' => 'Standard Notation Basics', 'branch' => 'reading-theory', 'sub_branch' => 'Notation',
            'prereqs' => [],
        ],
        'tab-reading-basics' => [
            'title' => 'Tab Reading Basics', 'branch' => 'reading-theory', 'sub_branch' => 'Notation',
            'prereqs' => [], // new floor node — evidence: tab-diagram widget, Music Theory Basics
        ],
        'rhythm-notation' => [
            'title' => 'Rhythm Notation', 'branch' => 'reading-theory', 'sub_branch' => 'Notation',
            'prereqs' => ['standard-notation-basics', 'pulse-subdivision'],
        ],
        'scale-degrees' => [
            'title' => 'Scale Degrees & Roman Numerals', 'branch' => 'reading-theory', 'sub_branch' => 'Foundations',
            'prereqs' => [], // new floor node — evidence: scale-steps widget, Music Theory Basics
        ],
        'leadsheet-reading' => [
            'title' => 'Leadsheet Reading', 'branch' => 'reading-theory', 'sub_branch' => 'Systems',
            'prereqs' => ['standard-notation-basics', 'triads', 'scale-degrees'],
        ],
        'nashville-number-system' => [
            'title' => 'Nashville Number System', 'branch' => 'reading-theory', 'sub_branch' => 'Systems',
            'prereqs' => ['leadsheet-reading', 'scale-degrees'],
        ],
    ];

    public function run(): void
    {
        // Pass 1 — upsert all nodes so every slug exists before wiring edges.
        $idBySlug = [];
        $order = 0;
        foreach (self::NODES as $slug => $def) {
            $node = SkillNode::updateOrCreate(
                ['slug' => $slug],
                [
                    'title'            => $def['title'],
                    'branch'           => $def['branch'],
                    'sub_branch'       => $def['sub_branch'] ?? null,
                    'content_tag_slug' => $def['content_tag_slug'] ?? null,
                    'completion_type'  => SkillNode::COMPLETION_SELF_REPORT,
                    'sort_order'       => $order++,
                ],
            );
            $idBySlug[$slug] = $node->id;
        }

        // Pass 2 — wire prerequisite edges (insertOrIgnore = idempotent).
        $edges = [];
        foreach (self::NODES as $slug => $def) {
            foreach ($def['prereqs'] as $requiresSlug) {
                $edges[] = [
                    'skill_node_id'          => $idBySlug[$slug],
                    'requires_skill_node_id' => $idBySlug[$requiresSlug],
                ];
            }
        }

        if ($edges) {
            DB::table('sbn_skill_node_prerequisites')->insertOrIgnore($edges);
        }
    }
}
