<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Samba line cliché voicings: Im → Im(b6) → Im6
 *
 * The inner voice moves chromatically on string 2:
 *   Im      x02210  — G on string 2 (5th)
 *   Im(b6)  x02110  — Ab/G# on string 2 (b6, enharmonic #5)
 *   Im6     x02210  wait — Im6 is already in the DB via m6 quality
 *
 * Concrete shapes in A minor (root-agnostic, transposed at runtime):
 *
 *   Am   x02210  frets: x-0-2-2-1-0  string 2 = B  (5th above A)
 *   Am(b6) x02110  wait — the b6 of A is F (Ab = b6). Let's use the
 *          standard guitaristic inner-voice cliché on strings 4-1:
 *
 * The classic samba/bossa cliché voicing cluster (all roota / string 5):
 *
 *   Am     x02210  — quality=m,    no extension
 *   Am(b6) x01210  — quality=m,    extension=b6   (F on string 3, half-step above E)
 *   Am6    x02212  — quality=m6,   no extension   (should already exist)
 *
 * Fret encoding: x=muted, 0=open, 1..n=fret number, string order 6→1 (low E → high e)
 * diagram_data JSON: positions = [{string, fret}], muted=[6], open=[5,4,2,1] etc.
 *
 * The three inserted rows cover quality=m with extension=b6 (the missing middle chord).
 * We insert root-agnostic (root_note=null / root based on archetype root C→transposed)
 * and fixed shapes for Am as well, giving the builder two options.
 */
return new class extends Migration
{
    // Am(b6) voicings — the missing b6 inner-voice passing chord.
    // We store them root-relative (root_note='A') with is_fixed_position=false
    // so ChordShapeCalculator can transpose to any root.
    // Frets string: 6 chars, low-E first. x=muted, digits=fret, 0=open.
    /**
     * Voicing rows. Defined as a method (not a property default) because the
     * rows call json_encode(), which is not a constant expression and would
     * raise "Constant expression contains invalid operations" as a default.
     */
    private function voicings(): array
    {
        return [
        // ── Shape 1: open-position Am(b6) ────────────────────────────────────
        // x 0 2 1 1 0  (strings 6→1)
        // String 5 (A) = root A, string 4 (D→E@2) = 5th, string 3 (G→Ab@1) = b6,
        // string 2 (B→C@1) = b3, string 1 (e) open = 5th.
        // Inner voice on string 3 moves G(natural)→Ab — the cliché half-step.
        [
            'root_note'         => 'A',
            'quality'           => 'm',
            'extensions'        => 'b6',
            'voicing_category'  => 'archetype',
            'root_string'       => 'roota',
            'inversion'         => 'root',
            'bass_note'         => '',
            'is_fixed_position' => false,
            'start_fret'        => 1,
            'diagram_data'      => json_encode([
                'positions' => [
                    ['string' => 4, 'fret' => 2],
                    ['string' => 3, 'fret' => 1],
                    ['string' => 2, 'fret' => 1],
                ],
                'barres' => [],
                'muted'  => [6],
                'open'   => [5, 1],
            ]),
            'popularity'        => 5,
            'name'              => 'Am(b6) — Samba Line Cliché',
            'slug'              => 'm-archetype-roota-b6',
        ],

        // ── Shape 2: closed-position Im(b6) on strings 4-1, moveable ────────
        // Root on string 4. In C: x-x-3-5-5-4 → Cm(b6)
        // String 4 = root, string 3 = 5th, string 2 = b6, string 1 = b3
        // This is the drop-2-adjacent shape jazz players use.
        [
            'root_note'         => 'C',
            'quality'           => 'm',
            'extensions'        => 'b6',
            'voicing_category'  => 'closed',
            'root_string'       => 'rootd',
            'inversion'         => 'root',
            'bass_note'         => '',
            'is_fixed_position' => false,
            'start_fret'        => 3,
            'diagram_data'      => json_encode([
                'positions' => [
                    ['string' => 4, 'fret' => 3],
                    ['string' => 3, 'fret' => 5],
                    ['string' => 2, 'fret' => 5],
                    ['string' => 1, 'fret' => 4],
                ],
                'barres' => [],
                'muted'  => [6, 5],
                'open'   => [],
            ]),
            'popularity'        => 4,
            'name'              => 'm(b6) — Closed Position (D-root)',
            'slug'              => 'm-closed-rootd-b6',
        ],

        // ── Shape 3: Im(b6) on strings 5-2, moveable (A-string root) ────────
        // Root on string 5. In A: x-0-2-1-2-x → Am(b6)
        // String 5 = root A, str 4 = 5th E, str 3 = b6 F (fret 1 on G string = Ab),
        // str 2 = b3 C. Clean four-note chord, strings 6+1 muted.
        [
            'root_note'         => 'A',
            'quality'           => 'm',
            'extensions'        => 'b6',
            'voicing_category'  => 'closed',
            'root_string'       => 'roota',
            'inversion'         => 'root',
            'bass_note'         => '',
            'is_fixed_position' => false,
            'start_fret'        => 1,
            'diagram_data'      => json_encode([
                'positions' => [
                    ['string' => 4, 'fret' => 2],
                    ['string' => 3, 'fret' => 1],
                    ['string' => 2, 'fret' => 2],
                ],
                'barres' => [],
                'muted'  => [6, 1],
                'open'   => [5],
            ]),
            'popularity'        => 4,
            'name'              => 'm(b6) — Closed Position (A-root)',
            'slug'              => 'm-closed-roota-b6',
        ],
        ];
    }

    public function up(): void
    {
        foreach ($this->voicings() as $v) {
            // Skip if slug already exists (idempotent)
            if (DB::table('sbn_chord_diagrams')->where('slug', $v['slug'])->exists()) {
                continue;
            }

            $id = DB::table('sbn_chord_diagrams')->insertGetId(array_merge($v, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));

            // Re-run interval/note computation via the model so interval_labels,
            // root_note (if transposable), and other computed columns are populated.
            $diagram = \App\Models\ChordDiagram::find($id);
            if ($diagram) {
                $computed = $diagram->computeIntervalsAndNotes();
                $diagram->update($computed);
            }
        }
    }

    public function down(): void
    {
        DB::table('sbn_chord_diagrams')
            ->whereIn('slug', array_column($this->voicings(), 'slug'))
            ->delete();
    }
};
