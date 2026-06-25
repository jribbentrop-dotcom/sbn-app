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
     * Node definitions: slug => [title, branch, sub_branch, grade, icon_key, content_tag_slug?, prerequisites[]]
     *
     * grade     — primary grade placement (1=basic … 5=advanced); nullable where unclear
     * icon_key  — Heroicon name used as placeholder until custom icon_path is set
     *
     * Branch-level icon keys (permanent):
     *   harmony        → musical-note
     *   rhythm         → clock
     *   melody         → microphone
     *   technique      → hand-raised
     *   ear-training   → speaker-wave
     *   reading-theory → book-open
     *
     * Per-node keys below use the branch icon as a starting point and diverge where
     * a better Heroicon exists. Swap to a custom icon_path in the admin editor once
     * the Canva icon set is ready — no code change needed.
     *
     * Prerequisites reference other slugs in this list; wired in a second pass.
     */
    private const NODES = [
        // ── Harmony ──────────────────────────────────────────────────────────
        'intervals' => [
            'title' => 'Intervals', 'branch' => 'harmony', 'sub_branch' => 'Foundations',
            'grade' => 1, 'icon_key' => 'arrows-up-down',
            'prereqs' => [],
        ],
        'triads' => [
            'title' => 'Triads', 'branch' => 'harmony', 'sub_branch' => 'Foundations',
            'grade' => 2, 'icon_key' => 'triangle',
            'prereqs' => ['intervals'],
        ],
        'chord-inversions' => [
            'title' => 'Chord Inversions', 'branch' => 'harmony', 'sub_branch' => 'Foundations',
            'grade' => 2, 'icon_key' => 'arrows-right-left',
            'prereqs' => ['triads'],
        ],
        'shell-voicings' => [
            'title' => 'Shell Voicings (3+7)', 'branch' => 'harmony', 'sub_branch' => 'Voicings',
            'grade' => 2, 'icon_key' => 'musical-note',
            'prereqs' => ['triads'],
        ],
        'drop2-voicings' => [
            'title' => 'Drop 2 Voicings', 'branch' => 'harmony', 'sub_branch' => 'Voicings',
            'grade' => 3, 'icon_key' => 'musical-note',
            'prereqs' => ['shell-voicings', 'chord-inversions'],
        ],
        'drop3-voicings' => [
            'title' => 'Drop 3 Voicings', 'branch' => 'harmony', 'sub_branch' => 'Voicings',
            'grade' => 3, 'icon_key' => 'musical-note',
            'prereqs' => ['drop2-voicings'],
        ],
        'ii-v-i-major' => [
            'title' => 'ii-V-I in Major', 'branch' => 'harmony', 'sub_branch' => 'Progressions',
            'grade' => 3, 'icon_key' => 'arrow-trending-up',
            'prereqs' => ['shell-voicings'],
        ],
        'ii-v-i-minor' => [
            'title' => 'ii-V-I in Minor', 'branch' => 'harmony', 'sub_branch' => 'Progressions',
            'grade' => 3, 'icon_key' => 'arrow-trending-down',
            'prereqs' => ['ii-v-i-major'],
        ],
        'tritone-substitution' => [
            'title' => 'Tritone Substitution', 'branch' => 'harmony', 'sub_branch' => 'Reharmonization',
            'grade' => 4, 'icon_key' => 'arrows-right-left',
            'prereqs' => ['ii-v-i-major'],
        ],
        'chord-melody' => [
            'title' => 'Chord Melody', 'branch' => 'harmony', 'sub_branch' => 'Reharmonization',
            'grade' => 5, 'icon_key' => 'queue-list',
            'prereqs' => ['drop2-voicings', 'ii-v-i-major'],
        ],

        // ── Harmony (2026-06-23 addition) ────────────────────────────────────
        'diatonic-harmony' => [
            'title' => "Diatonic Harmony (Building the Scale's Chords)", 'branch' => 'harmony', 'sub_branch' => 'Foundations',
            'grade' => 2, 'icon_key' => 'squares-2x2',
            'prereqs' => ['triads'],
        ],
        'cadences' => [
            'title' => 'Cadences (Classical)', 'branch' => 'harmony', 'sub_branch' => 'Progressions',
            'grade' => 2, 'icon_key' => 'flag',
            'prereqs' => ['diatonic-harmony'],
        ],
        'pop-progressions' => [
            'title' => 'Pop & Folk Progressions', 'branch' => 'harmony', 'sub_branch' => 'Progressions',
            'grade' => 2, 'icon_key' => 'arrow-path',
            'prereqs' => ['triads'],
        ],
        'turnarounds' => [
            'title' => 'Turnarounds', 'branch' => 'harmony', 'sub_branch' => 'Progressions',
            'grade' => 3, 'icon_key' => 'arrow-uturn-left',
            'prereqs' => ['ii-v-i-major'],
        ],
        'secondary-dominants' => [
            'title' => 'Secondary Dominants', 'branch' => 'harmony', 'sub_branch' => 'Reharmonization',
            'grade' => 4, 'icon_key' => 'adjustments-horizontal',
            'prereqs' => ['ii-v-i-major'],
        ],
        'borrowed-chords' => [
            'title' => 'Borrowed Chords / Modal Interchange', 'branch' => 'harmony', 'sub_branch' => 'Reharmonization',
            'grade' => 5, 'icon_key' => 'arrow-path-rounded-square',
            'prereqs' => ['diatonic-harmony', 'cadences'],
        ],
        'voice-leading' => [
            'title' => 'Smooth Voice Leading', 'branch' => 'harmony', 'sub_branch' => 'Voicings',
            'grade' => 3, 'icon_key' => 'arrows-pointing-in',
            'prereqs' => ['drop2-voicings'],
        ],

        // ── Rhythm ───────────────────────────────────────────────────────────
        'meter-basics' => [
            'title' => 'Meter & Time Signatures', 'branch' => 'rhythm', 'sub_branch' => 'Foundations',
            'grade' => 1, 'icon_key' => 'clock',
            'prereqs' => [],
        ],
        'pulse-subdivision' => [
            'title' => 'Pulse & Subdivision', 'branch' => 'rhythm', 'sub_branch' => 'Foundations',
            'grade' => 1, 'icon_key' => 'clock',
            'prereqs' => ['meter-basics'],
        ],
        'two-four-feel' => [
            'title' => '2/4 Feel (Bossa / Samba)', 'branch' => 'rhythm', 'sub_branch' => 'Feels',
            'grade' => 2, 'icon_key' => 'sun',
            'content_tag_slug' => 'samba', 'prereqs' => ['pulse-subdivision'],
        ],
        'syncopation' => [
            'title' => 'Syncopation', 'branch' => 'rhythm', 'sub_branch' => 'Feels',
            'grade' => 2, 'icon_key' => 'bolt',
            'prereqs' => ['pulse-subdivision'],
        ],
        'waltz-feel' => [
            'title' => '3/4 / Waltz Feel', 'branch' => 'rhythm', 'sub_branch' => 'Feels',
            'grade' => 2, 'icon_key' => 'arrow-path',
            'prereqs' => ['pulse-subdivision'],
        ],
        'swing-feel' => [
            'title' => 'Swing Feel', 'branch' => 'rhythm', 'sub_branch' => 'Feels',
            'grade' => 3, 'icon_key' => 'adjustments-horizontal',
            'prereqs' => ['pulse-subdivision'],
        ],
        'polyrhythm' => [
            'title' => 'Polyrhythm', 'branch' => 'rhythm', 'sub_branch' => 'Feels',
            'grade' => 5, 'icon_key' => 'circle-stack',
            'prereqs' => ['syncopation'],
        ],
        'comping-patterns' => [
            'title' => 'Comping Patterns', 'branch' => 'rhythm', 'sub_branch' => 'Application',
            'grade' => 3, 'icon_key' => 'squares-plus',
            'prereqs' => ['two-four-feel', 'syncopation'],
        ],
        'clave-systems' => [
            'title' => 'Clave Systems', 'branch' => 'rhythm', 'sub_branch' => 'Latin Rhythm',
            'grade' => 3, 'icon_key' => 'ellipsis-horizontal',
            'prereqs' => ['two-four-feel'],
        ],
        'brazilian-rhythm-styles' => [
            'title' => 'Brazilian & Afro-Latin Rhythm Styles', 'branch' => 'rhythm', 'sub_branch' => 'Latin Rhythm',
            'grade' => 3, 'icon_key' => 'globe-alt',
            'prereqs' => ['clave-systems', 'two-four-feel'],
        ],

        // ── Melody ───────────────────────────────────────────────────────────
        'scale-patterns' => [
            'title' => 'Scale Patterns', 'branch' => 'melody', 'sub_branch' => 'Foundations',
            'grade' => 2, 'icon_key' => 'bars-3-bottom-left',
            'prereqs' => [],
        ],
        'pentatonic-scale' => [
            'title' => 'Pentatonic Scale', 'branch' => 'melody', 'sub_branch' => 'Scales',
            'grade' => 2, 'icon_key' => 'bars-3',
            'prereqs' => ['scale-patterns'],
        ],
        'arpeggio-shapes' => [
            'title' => 'Arpeggio Shapes', 'branch' => 'melody', 'sub_branch' => 'Scales',
            'grade' => 3, 'icon_key' => 'chart-bar',
            'prereqs' => ['scale-patterns', 'triads'],
        ],
        'motivic-development' => [
            'title' => 'Motivic Development', 'branch' => 'melody', 'sub_branch' => 'Application',
            'grade' => 5, 'icon_key' => 'puzzle-piece',
            'prereqs' => ['scale-patterns'],
        ],
        'improvisation-over-changes' => [
            'title' => 'Improvisation Over Changes', 'branch' => 'melody', 'sub_branch' => 'Application',
            'grade' => 5, 'icon_key' => 'sparkles',
            'prereqs' => ['arpeggio-shapes', 'motivic-development', 'ii-v-i-major'],
        ],

        // ── Technique ────────────────────────────────────────────────────────
        'fingerpicking-basics' => [
            'title' => 'Fingerpicking Basics', 'branch' => 'technique', 'sub_branch' => 'Fingerstyle',
            'grade' => 1, 'icon_key' => 'hand-raised',
            'prereqs' => [],
        ],
        'right-hand-independence' => [
            'title' => 'Right Hand Independence', 'branch' => 'technique', 'sub_branch' => 'Fingerstyle',
            'grade' => 2, 'icon_key' => 'hand-raised',
            'prereqs' => ['fingerpicking-basics'],
        ],
        'thumb-independence' => [
            'title' => 'Thumb Independence', 'branch' => 'technique', 'sub_branch' => 'Fingerstyle',
            'grade' => 2, 'icon_key' => 'hand-thumb-up',
            'prereqs' => ['fingerpicking-basics'],
        ],
        'caged-system' => [
            'title' => 'CAGED System', 'branch' => 'technique', 'sub_branch' => 'Fretboard',
            'grade' => 3, 'icon_key' => 'map',
            'prereqs' => [],
        ],
        'barre-chords' => [
            'title' => 'Barre Chords', 'branch' => 'technique', 'sub_branch' => 'Fretboard',
            'grade' => 2, 'icon_key' => 'bars-3',
            'prereqs' => ['caged-system'],
        ],
        'position-shifting' => [
            'title' => 'Position Shifting', 'branch' => 'technique', 'sub_branch' => 'Fretboard',
            'grade' => 3, 'icon_key' => 'arrows-right-left',
            'prereqs' => ['caged-system'],
        ],
        'legato-slurs' => [
            'title' => 'Legato / Slurs', 'branch' => 'technique', 'sub_branch' => 'Articulation',
            'grade' => 2, 'icon_key' => 'minus',
            'prereqs' => [],
        ],
        'tone-production' => [
            'title' => 'Tone Production', 'branch' => 'technique', 'sub_branch' => 'Articulation',
            'grade' => 2, 'icon_key' => 'speaker-wave',
            'prereqs' => ['fingerpicking-basics'],
        ],

        // ── Ear Training ─────────────────────────────────────────────────────
        'interval-recognition' => [
            'title' => 'Interval Recognition', 'branch' => 'ear-training', 'sub_branch' => 'Recognition',
            'grade' => 2, 'icon_key' => 'speaker-wave',
            'prereqs' => ['intervals'],
        ],
        'chord-quality-recognition' => [
            'title' => 'Chord Quality Recognition', 'branch' => 'ear-training', 'sub_branch' => 'Recognition',
            'grade' => 3, 'icon_key' => 'speaker-wave',
            'prereqs' => ['interval-recognition', 'triads'],
        ],
        'rhythm-dictation' => [
            'title' => 'Rhythm Dictation', 'branch' => 'ear-training', 'sub_branch' => 'Dictation',
            'grade' => 3, 'icon_key' => 'pencil-square',
            'prereqs' => ['pulse-subdivision'],
        ],
        'melodic-dictation' => [
            'title' => 'Melodic Dictation', 'branch' => 'ear-training', 'sub_branch' => 'Dictation',
            'grade' => 4, 'icon_key' => 'pencil-square',
            'prereqs' => ['interval-recognition', 'scale-patterns'],
        ],

        // ── Reading & Theory ─────────────────────────────────────────────────
        'standard-notation-basics' => [
            'title' => 'Standard Notation Basics', 'branch' => 'reading-theory', 'sub_branch' => 'Notation',
            'grade' => 1, 'icon_key' => 'book-open',
            'prereqs' => [],
        ],
        'tab-reading-basics' => [
            'title' => 'Tab Reading Basics', 'branch' => 'reading-theory', 'sub_branch' => 'Notation',
            'grade' => 1, 'icon_key' => 'bars-3-bottom-left',
            'prereqs' => [],
        ],
        'rhythm-notation' => [
            'title' => 'Rhythm Notation', 'branch' => 'reading-theory', 'sub_branch' => 'Notation',
            'grade' => 2, 'icon_key' => 'document-text',
            'prereqs' => ['standard-notation-basics', 'pulse-subdivision'],
        ],
        'scale-degrees' => [
            'title' => 'Scale Degrees & Roman Numerals', 'branch' => 'reading-theory', 'sub_branch' => 'Foundations',
            'grade' => 2, 'icon_key' => 'variable',
            'prereqs' => [],
        ],
        'leadsheet-reading' => [
            'title' => 'Leadsheet Reading', 'branch' => 'reading-theory', 'sub_branch' => 'Systems',
            'grade' => 3, 'icon_key' => 'document-magnifying-glass',
            'prereqs' => ['standard-notation-basics', 'triads', 'scale-degrees'],
        ],
        'nashville-number-system' => [
            'title' => 'Nashville Number System', 'branch' => 'reading-theory', 'sub_branch' => 'Systems',
            'grade' => 3, 'icon_key' => 'hashtag',
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
                    'icon_key'         => $def['icon_key'] ?? null,
                    'grade'            => $def['grade'] ?? null,
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
