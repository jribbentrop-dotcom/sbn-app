<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Models\ChordProgression;
use App\Models\Leadsheet;
use Illuminate\Support\Str;
use Inertia\Inertia;

class SongLibraryController extends Controller
{
    /**
     * Map a rhythm slug to a music-style slug for color assignment.
     * Rhythm slugs from the RhythmPattern model → canonical style slugs.
     */
    private function rhythmToStyleSlug(?string $rhythm): string
    {
        if (!$rhythm) {
            return 'bossa';
        }

        $map = [
            'bossa'        => 'bossa',
            'bossa-nova'   => 'bossa',
            'samba'        => 'samba',
            'jazz'         => 'jazz',
            'swing'        => 'jazz',
            'latin'        => 'latin',
            'afro-cuban'   => 'latin',
            'blues'        => 'blues',
            'pop'          => 'pop',
            'ballad'       => 'pop',
            'classical'    => 'classical',
        ];

        // Try exact match first
        if (isset($map[$rhythm])) {
            return $map[$rhythm];
        }

        // Try prefix match (e.g. "bossa-nova-variation" → "bossa")
        foreach ($map as $prefix => $style) {
            if (str_starts_with($rhythm, $prefix)) {
                return $style;
            }
        }

        return 'bossa';
    }

    private function serializeSong(Leadsheet $song): array
    {
        return [
            'id'            => $song->id,
            'slug'          => $song->slug,
            'title'         => $song->title,
            'composer'      => $song->composer,
            'songKey'       => $song->song_key,
            'tempo'         => $song->tempo,
            'timeSignature' => $song->time_signature,
            'rhythm'        => $song->rhythm,
            'styleSlug'     => $this->rhythmToStyleSlug($song->rhythm),
            'description'   => $song->description ? Str::limit(strip_tags($song->description), 120) : null,
            'popularity'    => $song->popularity,
            'measureCount'  => $song->measure_count,
        ];
    }

    public function index()
    {
        $songs = Leadsheet::orderBy('title')->get();

        $serialized = $songs->map(fn ($s) => $this->serializeSong($s));

        $composers = Leadsheet::whereNotNull('composer')
            ->where('composer', '!=', '')
            ->selectRaw('composer, COUNT(*) as cnt')
            ->groupBy('composer')
            ->orderByDesc('cnt')
            ->limit(40)
            ->pluck('composer')
            ->toArray();

        $keys = Leadsheet::whereNotNull('song_key')
            ->where('song_key', '!=', '')
            ->distinct()
            ->orderBy('song_key')
            ->pluck('song_key')
            ->toArray();

        $rhythms = Leadsheet::whereNotNull('rhythm')
            ->where('rhythm', '!=', '')
            ->distinct()
            ->orderBy('rhythm')
            ->pluck('rhythm')
            ->toArray();

        return Inertia::render('Library/Songs/Index', [
            'songs'      => $serialized,
            'composers'  => $composers,
            'keys'       => $keys,
            'rhythms'    => $rhythms,
            'totalCount' => $songs->count(),
        ]);
    }

    public function show(Leadsheet $leadsheet)
    {
        // Chord names from the parsed leadsheet JSON
        $chordNames = $leadsheet->getChordNames();

        // Progressions detected in this song
        $progressions = ChordProgression::query()
            ->join('sbn_progression_occurrences as o', 'sbn_chord_progressions.id', '=', 'o.progression_id')
            ->where('o.leadsheet_id', $leadsheet->id)
            ->select('sbn_chord_progressions.id', 'sbn_chord_progressions.slug', 'sbn_chord_progressions.name', 'sbn_chord_progressions.category', 'sbn_chord_progressions.numerals')
            ->distinct()
            ->orderBy('sbn_chord_progressions.name')
            ->get()
            ->map(fn ($p) => [
                'id'             => $p->id,
                'slug'           => $p->slug,
                'name'           => $p->name,
                'category'       => $p->category,
                'numeralsDisplay'=> $p->numerals_display,
            ]);

        return Inertia::render('Library/Songs/Show', [
            'song' => [
                'id'            => $leadsheet->id,
                'slug'          => $leadsheet->slug,
                'title'         => $leadsheet->title,
                'composer'      => $leadsheet->composer,
                'songKey'       => $leadsheet->song_key,
                'tempo'         => $leadsheet->tempo,
                'timeSignature' => $leadsheet->time_signature,
                'description'   => $leadsheet->description,
                'harmonyNotes'  => $leadsheet->harmony_notes,
                'formNotes'     => $leadsheet->form_notes,
                'voicingNotes'  => $leadsheet->voicing_notes,
                'rhythm'        => $leadsheet->rhythm,
                'styleSlug'     => $this->rhythmToStyleSlug($leadsheet->rhythm),
                'measureCount'  => $leadsheet->measure_count,
                'popularity'    => $leadsheet->popularity,
            ],
            'chordNames'   => $chordNames,
            'progressions' => $progressions,
        ]);
    }
}
