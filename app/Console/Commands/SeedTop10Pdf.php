<?php

namespace App\Console\Commands;

use App\Models\PdfDocument;
use Illuminate\Console\Command;

class SeedTop10Pdf extends Command
{
    protected $signature   = 'sbn:seed-top10-pdf';
    protected $description = 'Seed the top10-bossa-nova-rich PDF document from design HTML content';

    public function handle(): int
    {
        $content = $this->buildContent();

        PdfDocument::updateOrCreate(
            ['slug' => 'top10-bossa-nova-rich'],
            [
                'template_key' => 'top10',
                'title'        => 'TOP10 Bossa Nova Chords',
                'content'      => $content,
                'status'       => 'draft',
            ]
        );

        $this->info('Seeded top10-bossa-nova-rich.');
        return self::SUCCESS;
    }

    private function buildContent(): array
    {
        return [
            // ── Cover ────────────────────────────────────────────────────────────────
            'eyebrow'  => 'SBN Teaching Hub · Top 10',
            'title'    => "TOP10\nBossa Nova Chords",
            'subtitle' => 'Essential Voicings for Guitar',
            'hook'     => 'Ten chords. Seven song excerpts. Everything you need to start sounding like Bossa Nova.',
            'facts'    => implode("\n", [
                'Ten movable chord shapes — learn each one once, play it in any of the 12 keys.',
                'Every chord cross-referenced to where it actually shows up — Ipanema, Corcovado, Wave, and more.',
                'Plus the favorite chord progressions and harmonic tricks of Tom Jobim and João Gilberto — including the one diminished shape that secretly works as four different chords.',
            ]),

            // ── Theory page ──────────────────────────────────────────────────────────
            'theory_title' => 'Chord Theory in a Nutshell',
            'theory_html'  => implode('', [
                '<p>Bossa Nova is famous for its soft, warm-sounding chords. Most of them are four-part chords — chords built from four notes instead of the three you\'d find in a basic major or minor triad. Those four notes have names: the root, the 3rd, the 5th, and the 7th. Stack them up and you get a seventh chord — the basic building block behind almost everything in this catalog.</p>',
                '<p>On top of that four-note core, you can add an extension tone for extra color: a 9th, an 11th, or a 13th. The 9th is the most common in Bossa Nova — it\'s what gives chords like the Major 6/9 (Item&nbsp;#1) or the Minor 7(9) (Item&nbsp;#2) their warmth. You\'ll see extensions written in parentheses after the chord name, like Cm7(9) — that just means "add the 9th on top."</p>',
                '<p>Guitar chords aren\'t usually played as a plain stack of those four (or five) notes in order — the notes get rearranged across the strings for a shape that\'s actually playable. This catalog groups those rearrangements into a few families you\'ll see named throughout: a <strong>Shell</strong> voicing keeps just the root, 3rd, and 7th (plus the extension), for a lean, open sound. A <strong>Drop 2</strong> or <strong>Drop 3</strong> voicing takes a normal stacked chord and drops one note down an octave into the bass, spreading the chord across more strings for a fuller sound. A few chords in this set use <strong>custom</strong> voicings that don\'t fit either pattern — built by ear, for a specific color.</p>',
                '<p>Two kinds of notation carry the practice patterns and song excerpts throughout this book.</p>',
                '<p><strong>TAB</strong> shows you exactly where to put your fingers. The six horizontal lines are the six strings, and a number on a line is the fret to play on that string — stacked numbers are a chord, played together. The chord name printed above each bar tells you which shape you\'re in (matching the diagrams elsewhere in this book), and the rhythm — how long to hold each note, where the rests fall — is notated the normal way, with stems, beams, and rest signs.</p>',
                '<p><strong>Rhythm pattern grids</strong> show you how to strum or pick, independent of which chord you\'re playing. Read left to right: the top row numbers the beats, and the row (or two rows) underneath show where to strike. If a pattern splits the picking hand into thumb and fingers, you\'ll see them as two separate rows — thumb below, fingers above. A tall block means strike there; a darker block is an accent (play it harder), a lighter one is a softer hit, and a short flat mark means rest — silence still gets its own beat in the grid, it\'s just drawn low instead of tall.</p>',
                '<p>With the theory out of the way — here are ten of the most useful, recognizable chords in the Bossa Nova vocabulary.</p>',
            ]),

            // ── Chord items ──────────────────────────────────────────────────────────
            'chords' => [

                // #1 — The Major 6/9 Chord
                [
                    'title'            => 'The Major 6/9 Chord',
                    'display_chord'    => 'Db6(9)/Ab',
                    'slug'             => 'maj7-drop2-roota',
                    'lede'             => 'The opening sound of Bossa Nova — the chord that introduced a genre to the world.',
                    'body'             => implode('', [
                        '<p>This is <em>the</em> chord — the opening voicing of <em>The Girl from Ipanema</em> on the Getz/Gilberto recording, and the sound that introduced Bossa Nova to the world. Despite its complex name (Major 6/9 with the 5th in the bass — João Gilberto\'s own preference), it\'s a relaxed, open shape that sits naturally under the fingers.</p>',
                        '<p>This Major 6/9 is a custom voicing rather than a standard chord shape: instead of the usual stack of 3rds, the notes here are arranged in 4ths. That\'s what gives the chord its open, slightly suspended sound compared to a conventional Major7 chord.</p>',
                    ]),
                    'voicing_pill'     => "Custom\nVoicing",
                    'intervals'        => '5th:fifth, 3rd:third, 6th:ext, 9th:ext',
                    'listen'           => '<strong>Stan Getz &amp; João Gilberto</strong>, <em>Getz/Gilberto</em> (1964).',
                    'try_this'         => 'Slide the whole shape up a half-step and back — Db6/9 to D6/9. That semitone move is a signature of Jobim\'s harmonic language.',
                    'related'          => '<strong>The Ellington Progression</strong> — Jobim borrowed this chord movement from Duke Ellington\'s <em>Take the A-Train</em> for the opening of <em>The Girl from Ipanema</em>.',
                    'practice_label'   => 'Apply it to the guitar: Db6(9)/Ab → Gb6(9)/Db',
                    'practice_meta'    => 'Same shape moved up a 4th — the moveable shape teaching point',
                    'rhythm_slug'      => 'gilberto-rhythm',
                    'rhythm_meta'      => 'Gilberto Rhythm · 2/4 · sixteenth-note grid',
                    'practice_tab_slug'=> 'top10',
                    'tab_bars'         => [1, 4],
                    'tab_bars_per_row' => 4,
                ],

                // #2 — Minor Seventh with 9
                [
                    'title'            => 'Minor Seventh with 9',
                    'display_chord'    => 'Cm7(9)',
                    'slug'             => 'm7-drop2-roota',
                    'lede'             => 'The little sister of the Major 6/9 — a bit more mellow, just as beautiful.',
                    'body'             => implode('', [
                        '<p>The minor 7th with a 9th on top softens the chord\'s dark quality and gives it an airy, floating feel that\'s essential for minor-key Bossa Nova. Kenny Dorham\'s <em>Blue Bossa</em> is built almost entirely around this sound.</p>',
                        '<p>This is a Shell voicing rooted on the A-string — root, 3rd, and 7th, with the 9th on top. Dropping the 5th makes room for that 9th without adding a fifth string — the 9th carries more color here than the 5th would. The same shape also works with the 5th in the bass instead of the root, as shown in the example below.</p>',
                    ]),
                    'voicing_pill'     => 'Shell Voicing',
                    'intervals'        => 'R:root, ♭3:third, ♭7:seventh, 9:ext',
                    'listen'           => '<strong>Kenny Dorham</strong>, <em>Page One</em> (1963).',
                    'try_this'         => 'Use this chord as the tonic in a minor Jazz Cadence: Dm7♭5 | G7(♭9) | Cm7(9) — the most common minor cadence in jazz and Bossa Nova alike.',
                    'related'          => '<strong>The Minor Jazz Cadence</strong> — the progression <em>Blue Bossa</em> keeps returning to; the 9th on top is what makes the resolution feel warm rather than heavy.',
                    'practice_label'   => 'Apply it to the guitar: Cm7(9) → Fm7(9)',
                    'practice_meta'    => 'Same shell shape moved up a 4th — the A section of Blue Bossa',
                    'rhythm_slug'      => 'gilberto-rhythm',
                    'rhythm_meta'      => 'Gilberto Rhythm · 2/4 · sixteenth-note grid',
                    'practice_tab_slug'=> 'top10',
                    'tab_bars'         => [5, 8],
                    'tab_bars_per_row' => 4,
                ],

                // #3 — The Minor Sixth Chord
                [
                    'title'            => 'The Minor Sixth Chord',
                    'display_chord'    => 'Am6',
                    'slug'             => 'm6-drop3-roote',
                    'lede'             => 'Where the m7 floats, this one resolves — the grounded, final-sounding tonic of Jobim ballads.',
                    'body'             => implode('', [
                        '<p>The minor 6th chord replaces the 7th with a major 6th — a subtle swap that changes the mood entirely. Where the m7 feels unresolved and floating, the m6 feels grounded and final. That quality of stillness-within-minor is the defining color of Jobim ballads like <em>Corcovado</em> and <em>Insensatez</em>.</p>',
                        '<p>This is a Drop 3 voicing — one of the four chord tones is dropped down an octave into the bass while the rest stack on top. Compared to a Shell voicing, it spreads the chord across more strings, trading one extra finger for a fuller, more resonant sound.</p>',
                    ]),
                    'voicing_pill'     => 'Drop 3 Voicing',
                    'intervals'        => 'R:root, 6:ext, ♭3:third, 5:fifth',
                    'listen'           => '<strong>João Gilberto</strong>, <em>O Amor, O Sorriso E A Flor</em> (1960).',
                    'try_this'         => 'Use the Minor6 chord as a tonic chord instead of a regular Minor or Minor7 chord.',
                    'related'          => '<strong>The Corcovado Progression</strong> — pairing this chord with a diminished passing chord is a common technique in Bossa Nova.',
                    'practice_label'   => 'Apply it to the guitar: Am6 → Dm7(9)',
                    'practice_meta'    => 'Tonic → same shape up a 4th',
                    'rhythm_slug'      => 'extended-gilberto-rhythm',
                    'rhythm_meta'      => 'Extended Gilberto Rhythm · 2/4 · sixteenth-note grid',
                    'practice_tab_slug'=> 'top10',
                    'tab_bars'         => [10, 13],
                    'tab_bars_per_row' => 4,
                ],

                // #4 — Dominant Seventh with 13
                [
                    'title'            => 'Dominant Seventh with 13',
                    'display_chord'    => 'G7(13)',
                    'slug'             => 'dom7-drop3-roote-13',
                    'lede'             => 'A complicated name for a simple, elegant sound — the dominant chord of warm, sun-drenched Bossa Nova resolutions.',
                    'body'             => implode('', [
                        '<p>A complicated name for a simple, elegant sound. The 13th adds brightness and color without extra tension. It\'s the dominant chord Jobim used for smooth, sun-drenched resolutions in <em>Wave</em>, <em>The Girl from Ipanema</em>, and dozens of other songs.</p>',
                        '<p>Another Drop 3 voicing, built the same way as the Minor Sixth shape in Item&nbsp;#3 — one chord tone drops out of the usual stack. In this case the 5th is also replaced by the 13th. Substituting a chord tone for an extension note is a common technique in Jazz &amp; Bossa Nova.</p>',
                    ]),
                    'voicing_pill'     => 'Drop 3 Voicing',
                    'intervals'        => 'R:root, ♭7:seventh, 3:third, 13:ext',
                    'listen'           => '<strong>Antonio Carlos Jobim</strong>, <em>Wave</em> (1967).',
                    'try_this'         => 'Resolve the cadence: Dm7(9) → G7(13) → Cmaj7. Move the whole three-chord cell around the cycle of fourths.',
                    'related'          => '<strong>The Jazz Half Cadence</strong> — one of the most characteristic sounds in Bossa Nova; the 13th creates a warm, optimistic tension that melts cleanly into the major tonic.',
                    'practice_label'   => 'Apply it to the guitar: Dm7(9) → G7(13)',
                    'practice_meta'    => 'The ii–V at the heart of Wave',
                    'rhythm_slug'      => 'gilberto-rhythm',
                    'rhythm_meta'      => 'Gilberto Rhythm · 2/4 · sixteenth-note grid',
                    'practice_tab_slug'=> 'top10',
                    'tab_bars'         => [21, 22],
                    'tab_bars_per_row' => 4,
                ],

                // #5 — The Half Diminished Chord
                [
                    'title'            => 'The Half Diminished Chord',
                    'display_chord'    => 'Bm7b5',
                    'slug'             => 'm7b5-drop2-roota',
                    'lede'             => 'The II chord in every minor jazz cadence — unsettled, questioning, inevitable.',
                    'body'             => implode('', [
                        '<p>The half-diminished — also written m7♭5 or ø — is the II chord in every minor jazz cadence. It has an unsettled, questioning quality that makes the dominant that follows feel inevitable. Learn this shape once and you\'ll recognize it in <em>Manhã de Carnaval</em>, <em>Chega de Saudade</em>, <em>Blue Bossa</em>, and virtually every minor-key standard in the repertoire.</p>',
                        '<p>This is a Drop 2 voicing rooted on the A-string — one chord tone is dropped down an octave from a Shell-style stack, spreading the chord across four strings for a fuller sound than a Shell voicing alone.</p>',
                    ]),
                    'voicing_pill'     => 'Drop 2 Voicing',
                    'intervals'        => 'R:root, ♭5:fifth, ♭7:seventh, ♭3:third',
                    'listen'           => '<strong>Luiz Bonfá</strong>, <em>Solo In Rio</em> (1959).',
                    'try_this'         => 'Move the whole ii–V–i around the cycle of fourths — Em7b5 → A7 → Dm, then Am7b5 → D7 → Gm, and on. Same suspended-to-resolved feeling, any minor key.',
                    'related'          => '<strong>The Minor Jazz Cadence</strong> in its purest form — the half-diminished creates a sense of suspension that won\'t feel resolved until it reaches the minor tonic.',
                    'practice_label'   => 'Apply it to the guitar: Bm7b5 → E7 → Am6',
                    'practice_meta'    => 'The minor ii–V–i — resolves into Item #3\'s tonic',
                    'rhythm_slug'      => 'gilberto-rhythm',
                    'rhythm_meta'      => 'Gilberto Rhythm · 2/4 · sixteenth-note grid',
                    'practice_tab_slug'=> 'top10',
                    'tab_bars'         => [16, 17],
                    'tab_bars_per_row' => 4,
                ],

                // #6 — Dominant Seventh with 9
                [
                    'title'            => 'Dominant Seventh with 9',
                    'display_chord'    => 'D7(9)',
                    'slug'             => 'dom7-shell-roota-9',
                    'lede'             => 'The most practical shell dominant — bright, open, with room for a bassline underneath.',
                    'body'             => implode('', [
                        '<p>The Dom7(9) shell voicing is one of the most practical chords in the guitar repertoire. The 9th adds warmth without complexity, and the open shell structure leaves space for a bassline in the thumb. Listen to Astrud Gilberto\'s recording of Jobim\'s <em>Fotografia</em>: the intro builds a hypnotic cross rhythm, and the D7(9) arrives at the end of the phrase like a question mark — tense, bright, and perfectly placed.</p>',
                        '<p>This is a Shell voicing rooted on the A-string — just the root, 3rd, and 7th, with the 9th stacked on top for color. Same shape family as Item #2\'s Cm7(9), just dominant quality instead of minor.</p>',
                    ]),
                    'voicing_pill'     => 'Shell Voicing',
                    'intervals'        => 'R:root, 3:third, ♭7:seventh, 9:ext',
                    'listen'           => '<em>Fotografia</em> (Astrud Gilberto, 1965) — this exact 4-bar intro; the D7(9) lands right at the end of the phrase.',
                    'try_this'         => 'Use only this voicing to play a full 12-bar blues — same Dom7(9) shape, just sliding it to the I, IV, and V chords. One shape, three positions, an entire blues.',
                    'related'          => '<strong>Modal Interchange</strong> — this chord is borrowed from the parallel key, creating a bright, unexpected tension that resolves back to the major tonic.',
                    'practice_label'   => 'Apply it to the guitar: Amaj7 → Am7 → D7(9)',
                    'practice_meta'    => 'The Fotografia intro — Modal Interchange I to Im',
                    'rhythm_slug'      => 'gilberto-rhythm',
                    'rhythm_meta'      => 'Gilberto Rhythm · 2/4 · sixteenth-note grid',
                    'practice_tab_slug'=> 'fotografia',  // confirmed present
                    'tab_bars'         => [1, 4],
                    'tab_bars_per_row' => 4,
                ],

                // #7 — Dominant Seventh with b9
                [
                    'title'            => 'Dominant Seventh with ♭9',
                    'display_chord'    => 'E7(b9)',
                    'slug'             => 'dom7-shell-roota-b9',
                    'lede'             => 'The most tension-laden chord in minor harmony — a half-step dissonance that aches to resolve.',
                    'body'             => implode('', [
                        '<p>The V7(♭9) is the most tension-laden chord in minor jazz harmony — the ♭9 sits just a half-step above the root, creating a dissonance that aches to resolve. Baden Powell leans into that tension in <em>The Shadow of Your Smile</em> (bars 7–10), voicing the chord as B7(♭9) with its 5th in the bass instead of the root, resolving the classic minor ii–V–i straight into Em.</p>',
                        '<p>Same Shell-voicing family as Item #6\'s D7(9) — just with a ♭9 instead of a 9 on top, the half-step dissonance that makes this the tensest chord in the catalog.</p>',
                    ]),
                    'voicing_pill'     => 'Shell Voicing',
                    'intervals'        => 'R:root, 3:third, ♭7:seventh, ♭9:ext',
                    'listen'           => '<strong>Baden Powell</strong>, <em>Solitude on Guitar</em> (1973).',
                    'try_this'         => 'Practice this same minor ii–V–i thread — 5th of the iim7 stepping down to the ♭9 of the V7(♭9), then down again to the 5th of the im — in two or three other minor keys.',
                    'related'          => '<strong>The Minor Jazz Cadence</strong> — the same ii–V–i family as Items #2 and #5, here with the ♭9 built as a borrowed diminished shape.',
                    'practice_label'   => 'Apply it to the guitar: F#m7 → B7(♭9) → Em',
                    'practice_meta'    => 'Minor ii–V–i from The Shadow of Your Smile, bars 7–10',
                    'rhythm_slug'      => 'gilberto-rhythm',
                    'rhythm_meta'      => 'Gilberto Rhythm · 2/4 · sixteenth-note grid',
                    'practice_tab_slug'=> 'the-shadow-of-your-smile',  // confirmed present
                    'tab_bars'         => [8, 11],
                    'tab_bars_per_row' => 4,
                ],

                // #8 — The Diminished Seventh Chord
                [
                    'title'            => 'The Diminished Seventh Chord',
                    'display_chord'    => 'C#dim7',
                    'slug'             => 'o7-drop2-roota',
                    'lede'             => "Jobim's secret weapon — one shape that secretly works as four different chords.",
                    'body'             => implode('', [
                        '<p>Jobim\'s secret weapon. Every note in a diminished seventh chord can function as the root, meaning one shape gives you four chords at once. Jobim exploited this in <em>How Insensitive</em>, <em>Desafinado</em>, and especially <em>Once I Loved</em>, where diminished passing chords appear throughout — sliding the shape up or down in half-steps to create smooth chromatic motion between any two chords.</p>',
                        '<p>A Drop 2 voicing of the diminished 7th. Like Items #2, #5, #6, and #7, this voicing sits tightly on the middle four strings, so it connects smoothly with any of those shapes without a big jump across the neck.</p>',
                    ]),
                    'voicing_pill'     => 'Drop 2 Voicing',
                    'intervals'        => 'R:root, ♭3:third, ♭5:fifth, ♭♭7:seventh',
                    'listen'           => '<strong>Stan Getz &amp; João Gilberto</strong>, <em>Getz/Gilberto</em> (1964) — the passing diminished in <em>Desafinado</em>.',
                    'try_this'         => 'Because of the symmetry, this one shape already gives you four diminished chords — C#°7, E°7, G°7, B♭°7. Try resolving each a half-step up into a different major or minor chord.',
                    'related'          => '<strong>The Ascending Diminished</strong> — this chord acts as a chromatic bridge between two chords a half-step apart; the smooth, inevitable motion Jobim used throughout <em>Once I Loved</em>.',
                    'practice_label'   => 'Apply it to the guitar: Cmaj7 → C#dim7 → Dm7(9)',
                    'practice_meta'    => 'The Ascending Diminished — chromatic bridge from I to II7',
                    'rhythm_slug'      => 'gilberto-rhythm',
                    'rhythm_meta'      => 'Gilberto Rhythm · 2/4 · sixteenth-note grid',
                    'practice_tab_slug'=> 'top10',
                    'tab_bars'         => [19, 21],
                    'tab_bars_per_row' => 4,
                ],

                // #9 — Diminished with Flat 13
                // TODO verify bars — Content-Draft says top10 bars 25–28 (Corcovado Progression)
                [
                    'title'            => 'Diminished with Flat 13',
                    'display_chord'    => 'G#dim7(b13)',
                    'slug'             => 'o7-drop3-roote-b13',
                    'lede'             => 'A refinement of the diminished — the ♭13 softens its edge and gives it a more impressionistic, modal quality.',
                    'body'             => implode('', [
                        '<p>A refinement of the diminished seventh — adding the ♭13 softens the chord\'s harsh edge and gives it a more ambiguous, modal quality. Where the plain °7 cuts sharply, the °7(♭13) blurs the outline just enough to feel more impressionistic. This was a characteristic touch of João Gilberto, who used it in <em>Corcovado</em> to keep the diminished passing chord from sounding too abrupt against the song\'s quiet atmosphere.</p>',
                        '<p>A Drop 3 voicing of the diminished 7th — symmetrical in the same way as Item #8\'s diminished, though built as a different shape on a different string set. One note is added on top: a ♭13, usually the melody note sitting directly above the chord, harmonized straight in.</p>',
                    ]),
                    'voicing_pill'     => 'Drop 3 Voicing',
                    'intervals'        => 'R:root, ♭♭7:seventh, ♭3:third, ♭13:ext',
                    'listen'           => '<strong>João Gilberto</strong>, <em>O Amor, O Sorriso E A Flor</em> (1960).',
                    'try_this'         => 'Compare this with Item #7\'s B7(♭9)/F# — both are diminished-7 shapes with one extra note bolted on, just in different places: a bass note underneath there, a melody note on top here.',
                    'related'          => '<strong>The Corcovado Progression</strong> — this chord sits between the Am6 tonic and its return, a fleeting moment of chromatic tension.',
                    'practice_label'   => 'Apply it to the guitar: Am6 → Ab°7(♭13)',
                    'practice_meta'    => 'The Corcovado Progression — chromatic tension over the tonic',
                    'rhythm_slug'      => 'gilberto-rhythm',
                    'rhythm_meta'      => 'Gilberto Rhythm · 2/4 · sixteenth-note grid',
                    'practice_tab_slug'=> 'top10',
                    'tab_bars'         => [26, 29],  // TODO verify bars against top10 leadsheet
                    'tab_bars_per_row' => 4,
                ],

                // #10 — Dominant Seventh with b13
                // TODO verify bars — Content-Draft says top10 bars 60–62 (Minor Blues Cadence, Dm7b5→G7(b13)→Cm7(9))
                [
                    'title'            => 'Dominant Seventh with ♭13',
                    'display_chord'    => 'G7(b13)',
                    'slug'             => 'dom7-drop3-roote-b13',
                    'lede'             => 'The darker twin of Item #4 — one semitone lower on the top note, pulling toward minor instead of major.',
                    'body'             => implode('', [
                        '<p>Compare this to Item #4: both are dominant sevenths with a 13th, but the ♭13 sits a half-step lower — and that single semitone changes everything. Where the Dom7(13) sounds warm and optimistic, the Dom7(♭13) sounds darker, more serious, pulling toward minor rather than major. Bossa Nova and jazz players like to play with this expectation and resolve it to a major tonic for a surprising twist.</p>',
                        '<p>The same Drop 3 shape as Item #4\'s G7(13), with the 13th flatted by a half-step — same fingering family, one fret lower on that top note.</p>',
                    ]),
                    'voicing_pill'     => 'Drop 3 Voicing',
                    'intervals'        => 'R:root, ♭7:seventh, 3:third, ♭13:ext',
                    'listen'           => '<strong>João Gilberto</strong>, <em>S\'Wonderful</em> (1978).',
                    'try_this'         => 'Compare directly with Item #4: play G7(13), then G7(♭13) — same shape, one fret lower on the top note — and feel how a single half-step flips warm and optimistic into dark and serious.',
                    'related'          => '<strong>The Minor Blues Cadence</strong> — Gilberto injected this dominant color into <em>S\'Wonderful</em>, turning the modulation to the new key into a smooth cadence.',
                    'practice_label'   => 'Apply it to the guitar: Dm7b5 → G7(♭13) → Cm7(9)',
                    'practice_meta'    => 'Full minor ii–V–i — resolves into Item #2\'s tonic',
                    'rhythm_slug'      => 'gilberto-rhythm',
                    'rhythm_meta'      => 'Gilberto Rhythm · 2/4 · sixteenth-note grid',
                    'practice_tab_slug'=> 'top10',
                    'tab_bars'         => [61, 63],  // TODO verify bars against top10 leadsheet
                    'tab_bars_per_row' => 4,
                ],
            ],

            // ── Song example pages ───────────────────────────────────────────────────
            'songs' => [

                // Example 1 — The Girl from Ipanema
                [
                    'title'        => 'The Girl from Ipanema',
                    'sub'          => 'Bars 5–12 · Stan Getz &amp; João Gilberto, <em>Getz/Gilberto</em>, 1964',
                    'eyebrow'      => 'Repertoire Example · 1 of 7',
                    'legend'       => implode("\n", ['01 Db6(9)/Ab', '06 D7(9)', '02 Cm7(9)', '09 G#dim7(b13)']),
                    'note'         => 'This phrase is built almost entirely from TOP10 chords, just in disguise. The opening Db6(9)/Ab is Item #1 itself — the chord this whole catalog entry is named for. Two bars in, Jobim makes his favorite leap: straight from the I chord to a II7, the same trick Duke Ellington used in <em>Take the A-Train</em>. That II7 is Eb7(9)/Bb — Item #6, with its 5th instead of its root in the bass. Right behind it, Ebm7(9)/Bb does the same disguise to Item #2. Then the cleverest move: the Ab7(b9,13) chord is a dim7 shape playing dominant — the same family of trick Item #9 teaches.',
                    'slug'         => 'the-girl-from-ipanema-1',
                    'bars'         => [6, 13],
                    'bars_per_row' => 4,
                ],

                // Example 2 — So Danço Samba
                [
                    'title'        => 'So Danço Samba',
                    'sub'          => 'Bars 0–7 · Stan Getz &amp; João Gilberto, <em>Getz/Gilberto</em>, 1964',
                    'eyebrow'      => 'Repertoire Example · 2 of 7',
                    'legend'       => implode("\n", ['01 D6(9)', '06 E9', '10 A7(b13)']),
                    'note'         => 'Jobim runs the same trick as <em>The Girl from Ipanema</em> here, just a semitone higher — D major instead of Db major. The tonic D6/9 is Item #1\'s family, and two bars in, the same Ellington Progression leap shows up again: E9, Item #6\'s quality on a new root. The phrase closes on A7(b13) sliding home to the D6/9 tonic — Item #10\'s exact quality, and the same guide-tone motion as the catalog: the ♭13 (F) resolves down a half-step into the 9th of the tonic chord (E).',
                    'slug'         => 'so-danco-samba-jazz',
                    'bars'         => [1, 8],
                    'bars_per_row' => 4,
                ],

                // Example 3 — Blue Bossa
                [
                    'title'        => 'Blue Bossa',
                    'sub'          => 'Bars 0–7 · Kenny Dorham, <em>Page One</em>, 1963',
                    'eyebrow'      => 'Repertoire Example · 3 of 7',
                    'legend'       => implode("\n", ['02 Cm7(9)', '05 Fm7(9)', '10 G7(b13)']),
                    'note'         => 'Two exact catalog matches anchor this phrase: the Cm7(9) tonic is Item #2 itself, and the dominant that resolves back into it is Item #10\'s G7(b13), same root as the catalog. In between, Fm7(9) is Item #2\'s own "moved up a fourth" practice pattern showing up verbatim in the real chart. The voice leading into the turnaround is diatonic, not chromatic — the ♭13 (Eb) is just the minor 3rd of the key, and it resolves down a half-step into D, the 9th of Cm7(9).',
                    'slug'         => 'blue-bossa',
                    'bars'         => [1, 8],
                    'bars_per_row' => 4,
                ],

                // Example 4 — Manhã de Carnaval
                [
                    'title'        => 'Manhã de Carnaval',
                    'sub'          => 'Bars 8–15 · Luiz Bonfá, <em>Solo In Rio</em>, 1959',
                    'eyebrow'      => 'Repertoire Example · 4 of 7',
                    'legend'       => implode("\n", ['05 Bm7b5', '08 Bdim7']),
                    'note'         => 'Bonfá closes this 8-bar phrase with a real minor ii–V into Am: Bm7b5 is Item #5, exact root and all. Right behind it, the chart doesn\'t spell the V chord as E7(b9) — it writes Bdim7 instead, the same symmetric trick Item #8 teaches: any dim7 shape can double as a rootless dominant 7(♭9) on another root entirely. The same ii–V shows up again, compressed into a single bar, right before the phrase resolves to the Am tonic.',
                    'slug'         => 'manha-de-carnaval-jazz',
                    'bars'         => [9, 16],
                    'bars_per_row' => 4,
                ],

                // Example 5 — Once I Loved
                [
                    'title'        => 'Once I Loved',
                    'sub'          => 'Bars 0–7 · Pat Martino, <em>El Hombre</em>, 1967',
                    'eyebrow'      => 'Repertoire Example · 5 of 7',
                    'legend'       => implode("\n", ['04 G7(13)', '08 C#dim7']),
                    'note'         => 'Two exact catalog matches appear back-to-back, both built from the same chromatic device. G7(13) is Item #4, note for note, leading into CMaj7. From there, C#dim7 is Item #8, note for note — the Ascending Diminished bridge Item #8\'s own practice pattern is built on, doing exactly what that item\'s citation promises ("especially in <em>Once I Loved</em>"). Jobim immediately repeats the same trick a step higher (D#dim7 bridging Dm9 to Em7(9)), turning one chromatic device into the engine for the whole phrase.',
                    'slug'         => 'once-i-loved',
                    'bars'         => [1, 8],
                    'bars_per_row' => 4,
                ],

                // Example 6 — How Insensitive (Insensatez)
                [
                    'title'        => 'How Insensitive (Insensatez)',
                    'sub'          => 'Bars 4–11 · João Gilberto, <em>O Amor, O Sorriso E A Flor</em>, 1960',
                    'eyebrow'      => 'Repertoire Example · 6 of 7',
                    'legend'       => implode("\n", ['09 Bbo7(b13)', '03 Am6']),
                    'note'         => 'Bbo7(b13) here is Item #9\'s exact quality, sitting a half-step above the Am6 tonic it resolves into — the same descending diminished-in-half-steps motion that drives <em>Corcovado</em>, another signature Jobim move. Am6 itself is Item #3, note for note. João Gilberto\'s original recording is the reference for this one.',
                    'slug'         => 'insensatez',
                    'bars'         => [5, 12],
                    'bars_per_row' => 4,
                ],

                // Example 7 — Corcovado
                [
                    'title'        => 'Corcovado',
                    'sub'          => 'Bars 0–7 · João Gilberto, <em>O Amor, O Sorriso E A Flor</em>, 1960',
                    'eyebrow'      => 'Repertoire Example · 7 of 7',
                    'legend'       => implode("\n", ['03 Am6', '09 G#o7(b13)', '06 C9/G']),
                    'note'         => 'The strongest pairing in the set, right at the top of the form: Am6 is Item #3, exact, and the G#o7(b13) that follows immediately is Item #9, exact — same root as the catalog, same chord. A few bars later, C9/G touches Item #6\'s quality with its 5th, not its root, in the bass — the same disguise heard in <em>The Girl from Ipanema</em>.',
                    'slug'         => 'corcovado',
                    'bars'         => [1, 8],
                    'bars_per_row' => 4,
                ],
            ],
        ];
    }
}
