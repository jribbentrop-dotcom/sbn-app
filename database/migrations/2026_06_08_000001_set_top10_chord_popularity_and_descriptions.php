<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $data = [
        'maj6-custom-roote-inv2-9' => [
            'popularity'  => 15,
            'description' => "This is THE iconic sound from the Getz/Gilberto recording — the chord that made Bossa Nova famous worldwide. Despite its complex name, this major 6/9 voicing defines the smooth, lush character that captured global attention.",
        ],
        'm7-shell-roota-9' => [
            'popularity'  => 15,
            'description' => "The little sister of the Major 9 chord — a bit more mellow and at least as beautiful. Essential for the smooth Bossa Nova sound.",
        ],
        'm6-drop3-roote' => [
            'popularity'  => 15,
            'description' => "The minor 6th chord is the darker, more mysterious alternative to the minor 7th chord. Perfect as a tonic chord for Jazz and Bossa Nova ballads like 'Corcovado'.",
        ],
        'dom7-drop3-roote-13' => [
            'popularity'  => 15,
            'description' => "A complicated name for a simple, elegant sound. Often used in typical chord progressions for Latin tunes like Jobim's 'Wave'.",
        ],
        'm7b5-drop2-roota' => [
            'popularity'  => 15,
            'description' => "As part of a II-V-I cadence in minor, the half-diminished chord (m7b5) is an essential building block of Jazz and Bossa Nova songs.",
        ],
        'dom7-shell-roota-9' => [
            'popularity'  => 15,
            'description' => "Dominant Seventh chords are vital to any music genre. The Dom7(9) version is common in Latin-song intros like 'Oye Como Va'.",
        ],
        'dom7-shell-roota-b9' => [
            'popularity'  => 15,
            'description' => "Along with the half-diminished chord, the Dom7b9 forms the 'minor cadence' team. Adds dramatic tension and release.",
        ],
        'o7-drop2-roota' => [
            'popularity'  => 15,
            'description' => "The 'Secret Weapon' of Bossa Nova! Tom Jobim used diminished chords in numerous songs as smooth passing harmonies.",
        ],
        'o7-drop3-roote-b13' => [
            'popularity'  => 15,
            'description' => "To make a diminished chord softer, add a b13. This voicing was a favorite of Joao Gilberto, used in his treatment of 'Corcovado'.",
        ],
        'dom7-drop3-roote-b13' => [
            'popularity'  => 15,
            'description' => "Used to create a strong tension effect that typically resolves to a major chord, as heard in the beautiful 'How Insensitive'.",
        ],
    ];

    public function up(): void
    {
        foreach ($this->data as $slug => $values) {
            DB::table('sbn_chord_diagrams')
                ->where('slug', $slug)
                ->update($values);
        }
    }

    public function down(): void
    {
        // Restore previous values (all had popularity < 15 and auto-imported descriptions)
        $previous = [
            'maj6-custom-roote-inv2-9' => ['popularity' => 2,  'description' => 'Imported from leadsheet: The Girl from Ipanema'],
            'm7-shell-roota-9'         => ['popularity' => 5,  'description' => 'Imported from leadsheet: Fotografia'],
            'm6-drop3-roote'           => ['popularity' => 4,  'description' => null],
            'dom7-drop3-roote-13'      => ['popularity' => 4,  'description' => 'Imported from leadsheet: Blue Bossa'],
            'm7b5-drop2-roota'         => ['popularity' => 8,  'description' => null],
            'dom7-shell-roota-9'       => ['popularity' => 3,  'description' => 'Imported from leadsheet: Fotografia'],
            'dom7-shell-roota-b9'      => ['popularity' => 5,  'description' => null],
            'o7-drop2-roota'           => ['popularity' => 2,  'description' => null],
            'o7-drop3-roote-b13'       => ['popularity' => 5,  'description' => 'Imported from leadsheet: The Girl from Ipanema'],
            'dom7-drop3-roote-b13'     => ['popularity' => 13, 'description' => ''],
        ];

        foreach ($previous as $slug => $values) {
            DB::table('sbn_chord_diagrams')
                ->where('slug', $slug)
                ->update($values);
        }
    }
};
