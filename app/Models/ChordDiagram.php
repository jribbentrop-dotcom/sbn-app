<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChordDiagram extends Model
{
    protected $table = 'sbn_chord_diagrams';

    protected $guarded = ['id'];

    protected $casts = [
        'is_default'        => 'boolean',
        'is_fixed_position' => 'boolean',
        'start_fret'        => 'integer',
        'sort_order'        => 'integer',
    ];

    // =========================================================================
    // CONSTANTS
    // =========================================================================

    /**
     * Voicing categories.
     *
     * archetype      = Fundamental open guitar shapes (E, Em, A, Am, D, Dm, C, G)
     * closed         = Close-voiced 7th chords (systematic inversions)
     * closed_triads  = Close-voiced triads
     * spread_triads  = Spread-voiced triads
     * drop2 / drop3  = Systematic 4-note spread voicings
     * shell          = 2–3 note functional voicings
     * rootless       = Voicings without root (jazz context)
     * custom         = Everything else
     */
    const VOICING_CATEGORIES = [
        'archetype'     => 'Archetypes',
        'drop2'         => 'Drop 2',
        'drop3'         => 'Drop 3',
        'shell'         => 'Shell Voicings',
        'rootless'      => 'Rootless',
        'closed'        => 'Closed Position',
        'closed_triads' => 'Closed Triads',
        'spread_triads' => 'Spread Triads',
        'slash'         => 'Slash / Bass Voicings',
        'custom'        => 'Custom',
    ];

    const CHORD_QUALITIES = [
        'maj'   => 'Major (triad)',
        'min'   => 'Minor (triad)',
        'aug'   => 'Augmented (triad)',
        'dim'   => 'Diminished (triad)',
        '5'     => 'Power Chord',
        'sus4'  => 'Suspended 4th',
        'sus2'  => 'Suspended 2nd',
        'add9'  => 'Add 9',
        'maj7'  => 'Major 7',
        'm7'    => 'Minor 7',
        'dom7'  => 'Dominant 7',
        'm7b5'  => 'Half-Diminished (m7♭5)',
        'o7'    => 'Diminished 7',
        'maj6'  => 'Major 6',
        'm6'    => 'Minor 6',
        'mMaj7' => 'Minor-Major 7',
        'aug7'  => 'Augmented 7',
        '7sus4' => 'Dominant 7 sus4',
    ];

    const EXTENSIONS = [
        '9'    => '9',
        'b9'   => '♭9',
        '#9'   => '♯9',
        '11'   => '11',
        '#11'  => '♯11',
        '13'   => '13',
        'b13'  => '♭13',
        'add9' => 'add9',
        'sus4' => 'sus4',
        'sus2' => 'sus2',
        'b5'   => '♭5',
        '#5'   => '♯5',
    ];

    const ROOT_NOTES = [
        'C', 'C#', 'Db', 'D', 'D#', 'Eb', 'E', 'F',
        'F#', 'Gb', 'G', 'G#', 'Ab', 'A', 'A#', 'Bb', 'B',
    ];

    const ROOT_STRINGS = [
        'roote'  => 'Root on 6th String (Low E)',
        'roota'  => 'Root on 5th String (A)',
        'rootd'  => 'Root on 4th String (D)',
        'rootg'  => 'Root on 3rd String (G)',
        'custom' => 'Custom Root Position',
    ];

    const INVERSIONS = [
        'root' => 'Root Position',
        'inv1' => '1st Inversion',
        'inv2' => '2nd Inversion',
        'inv3' => '3rd Inversion',
    ];

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopeCategory($query, ?string $category)
    {
        return $category ? $query->where('voicing_category', $category) : $query;
    }

    public function scopeQuality($query, ?string $quality)
    {
        return $quality ? $query->where('quality', $quality) : $query;
    }

    public function scopeRootString($query, ?string $rootString)
    {
        return $rootString ? $query->where('root_string', $rootString) : $query;
    }

    public function scopeSearch($query, ?string $term)
    {
        if (! $term) return $query;
        return $query->where(function ($q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
              ->orWhere('slug', 'like', "%{$term}%")
              ->orWhere('quality', 'like', "%{$term}%")
              ->orWhere('description', 'like', "%{$term}%");
        });
    }

    // =========================================================================
    // ACCESSORS
    // =========================================================================

    public function getQualityLabelAttribute(): string
    {
        return self::CHORD_QUALITIES[$this->quality] ?? $this->quality;
    }

    public function getCategoryLabelAttribute(): string
    {
        return self::VOICING_CATEGORIES[$this->voicing_category] ?? ucfirst($this->voicing_category ?? 'custom');
    }

    public function getRootStringLabelAttribute(): string
    {
        return self::ROOT_STRINGS[$this->root_string] ?? $this->root_string ?? '—';
    }

    public function getInversionLabelAttribute(): string
    {
        return self::INVERSIONS[$this->inversion] ?? 'Root Position';
    }

    /**
     * Build the shape slug: quality-category-rootstring[-inversion][-extensions][-overBass]
     */
    public function getShapeSlugAttribute(): string
    {
        $parts = [$this->quality, $this->voicing_category, $this->root_string];

        if ($this->inversion && $this->inversion !== 'root') {
            $parts[] = $this->inversion;
        }
        if ($this->extensions) {
            $parts[] = str_replace(['#', '♯', '♭', ' '], ['s', 's', 'b', ''], $this->extensions);
        }
        if ($this->bass_note) {
            $parts[] = 'over' . $this->bass_note;
        }

        return implode('-', $parts);
    }

    /**
     * Decoded diagram_data JSON.
     */
    public function getDiagramAttribute(): array
    {
        $data = json_decode($this->diagram_data ?? '{}', true);
        return array_merge([
            'positions' => [],
            'barres'    => [],
            'muted'     => [],
            'open'      => [],
        ], $data ?: []);
    }

    // =========================================================================
    // STATIC HELPERS
    // =========================================================================

    /**
     * Get all diagrams organised: category → root_string → [plain, extensions].
     */
    public static function getOrganised(?string $filterCat = null, ?string $filterQual = null, ?string $filterRs = null)
    {
        $query = self::query()
            ->category($filterCat)
            ->quality($filterQual)
            ->rootString($filterRs)
            ->orderBy('voicing_category')
            ->orderBy('quality')
            ->orderBy('root_note')
            ->orderBy('sort_order')
            ->orderBy('name');

        $diagrams = $query->get();

        $organised = [];
        foreach ($diagrams as $d) {
            $cat = $d->voicing_category ?: 'custom';
            $rs  = $d->root_string ?: 'roota';
            $hasExt = $d->extensions ? 1 : 0;

            $organised[$cat] ??= [];
            $organised[$cat][$rs] ??= [0 => collect(), 1 => collect()];
            $organised[$cat][$rs][$hasExt]->push($d);
        }

        return $organised;
    }

    /**
     * Quick stats for the index page.
     */
    public static function getStats(): array
    {
        return [
            'total'      => self::count(),
            'categories' => self::distinct('voicing_category')->count('voicing_category'),
            'qualities'  => self::distinct('quality')->count('quality'),
            'archetypes' => self::where('voicing_category', 'archetype')->count(),
        ];
    }

    // =========================================================================
    // INTERVAL & NOTE COMPUTATION
    // =========================================================================

    /**
     * Standard tuning: internal string number => semitone.
     * INTERNAL NUMBERING: 1=Low E, 2=A, 3=D, 4=G, 5=B, 6=High E
     */
    private static array $tuning = [
        1 => 4,  // Low E
        2 => 9,  // A
        3 => 2,  // D
        4 => 7,  // G
        5 => 11, // B
        6 => 4,  // High E
    ];

    private static array $noteSemitones = [
        'C' => 0, 'C#' => 1, 'Db' => 1,
        'D' => 2, 'D#' => 3, 'Eb' => 3,
        'E' => 4,
        'F' => 5, 'F#' => 6, 'Gb' => 6,
        'G' => 7, 'G#' => 8, 'Ab' => 8,
        'A' => 9, 'A#' => 10, 'Bb' => 10,
        'B' => 11,
    ];

    private static array $semitoneNotesSharp = [
        0 => 'C', 1 => 'C#', 2 => 'D', 3 => 'D#', 4 => 'E', 5 => 'F',
        6 => 'F#', 7 => 'G', 8 => 'G#', 9 => 'A', 10 => 'A#', 11 => 'B',
    ];

    private static array $semitoneNotesFlat = [
        0 => 'C', 1 => 'Db', 2 => 'D', 3 => 'Eb', 4 => 'E', 5 => 'F',
        6 => 'Gb', 7 => 'G', 8 => 'Ab', 9 => 'A', 10 => 'Bb', 11 => 'B',
    ];

    private static array $flatRoots = ['F', 'Bb', 'Eb', 'Ab', 'Db', 'Gb'];

    private static array $rootStringToNumber = [
        'roote' => 1, 'roota' => 2, 'rootd' => 3, 'rootg' => 4,
        'rootb' => 5, 'roothighe' => 6,
    ];

    private static array $inversionBassIndex = [
        'root' => 0, 'inv1' => 1, 'inv2' => 2, 'inv3' => 3,
    ];

    private static array $genericIntervalLabels = [
        0 => 'R', 1 => 'b9', 2 => '9', 3 => '#9', 4 => '3', 5 => '11',
        6 => '#11', 7 => '5', 8 => 'b13', 9 => '13', 10 => 'b7', 11 => '7',
    ];

    private static function getQualityIntervals(): array
    {
        return [
            'maj7'  => [0 => 'R', 4 => '3', 7 => '5', 11 => '7'],
            'maj6'  => [0 => 'R', 4 => '3', 7 => '5', 9 => '6'],
            'm7'    => [0 => 'R', 3 => 'b3', 7 => '5', 10 => 'b7'],
            'm6'    => [0 => 'R', 3 => 'b3', 7 => '5', 9 => '6'],
            '7'     => [0 => 'R', 4 => '3', 7 => '5', 10 => 'b7'],
            'dom7'  => [0 => 'R', 4 => '3', 7 => '5', 10 => 'b7'],
            'm7b5'  => [0 => 'R', 3 => 'b3', 6 => 'b5', 10 => 'b7'],
            'o7'    => [0 => 'R', 3 => 'b3', 6 => 'b5', 9 => 'bb7'],
            'mMaj7' => [0 => 'R', 3 => 'b3', 7 => '5', 11 => '7'],
            'aug7'  => [0 => 'R', 4 => '3', 8 => '#5', 10 => 'b7'],
            '7sus4' => [0 => 'R', 5 => '4', 7 => '5', 10 => 'b7'],
            'maj'   => [0 => 'R', 4 => '3', 7 => '5'],
            'min'   => [0 => 'R', 3 => 'b3', 7 => '5'],
            'aug'   => [0 => 'R', 4 => '3', 8 => '#5'],
            'dim'   => [0 => 'R', 3 => 'b3', 6 => 'b5'],
            'sus4'  => [0 => 'R', 5 => '4', 7 => '5'],
            'sus2'  => [0 => 'R', 2 => '2', 7 => '5'],
            'add9'  => [0 => 'R', 2 => '9', 4 => '3', 7 => '5'],
            '5'     => [0 => 'R', 7 => '5'],
        ];
    }

    /**
     * Compute interval_labels and notes for this diagram.
     *
     * @return array{interval_labels: string, notes: string}
     */
    public function computeIntervalsAndNotes(): array
    {
        $result = ['interval_labels' => '', 'notes' => ''];

        $data = json_decode($this->diagram_data ?? '{}', true);
        if (! $data || empty($data['positions'])) {
            return $result;
        }

        $quality    = $this->quality ?? '';
        $inversion  = $this->inversion ?? 'root';
        $rootStrId  = $this->root_string ?? '';

        // Build per-string fret lookup
        $stringFrets = [];
        foreach ($data['positions'] ?? [] as $pos) {
            $stringFrets[(int) $pos['string']] = (int) $pos['fret'];
        }
        foreach ($data['open'] ?? [] as $s) {
            $s = (int) $s;
            if (! isset($stringFrets[$s])) {
                $stringFrets[$s] = 0;
            }
        }
        $muted = array_map('intval', $data['muted'] ?? []);

        // Find root string number and fret
        $rootStringNum = self::$rootStringToNumber[$rootStrId] ?? null;
        $rootFret = ($rootStringNum !== null && isset($stringFrets[$rootStringNum]))
            ? $stringFrets[$rootStringNum]
            : null;

        // Derive chord root
        $qualityMaps = self::getQualityIntervals();
        $imap = $qualityMaps[$quality] ?? null;
        $rootSemitone = null;

        if ($rootFret === null || $rootStringNum === null) {
            // Root string muted — fall back to root_note field
            $rootNote = $this->root_note ?? '';
            if (! isset(self::$noteSemitones[$rootNote])) {
                return $result;
            }
            $rootSemitone = self::$noteSemitones[$rootNote];
        } else {
            $bassSemitone = (self::$tuning[$rootStringNum] + $rootFret) % 12;
            $rootSemitone = $bassSemitone;

            if ($inversion !== 'root' && $imap) {
                $intervalsOrdered = array_keys($imap);
                sort($intervalsOrdered);
                $invIndex = self::$inversionBassIndex[$inversion] ?? 0;
                if (isset($intervalsOrdered[$invIndex])) {
                    $bassInterval = $intervalsOrdered[$invIndex];
                    $rootSemitone = ($bassSemitone - $bassInterval + 12) % 12;
                }
            }
        }

        // Choose sharp/flat: flat-family roots OR minor/dominant qualities always use flats.
        static $flatQualities = ['min', 'm7', 'm6', 'm7b5', 'o7', 'mMaj7', 'dom7', 'maj6', 'aug7'];
        $rootNoteName = self::$semitoneNotesSharp[$rootSemitone];
        $useFlats = in_array($rootNoteName, self::$flatRoots)
            || in_array($quality, $flatQualities);
        if (! $useFlats) {
            $flatName = self::$semitoneNotesFlat[$rootSemitone];
            if (in_array($flatName, self::$flatRoots)) {
                $useFlats = true;
            }
        }
        $noteMap = $useFlats ? self::$semitoneNotesFlat : self::$semitoneNotesSharp;

        // Build 6-string arrays (s=1 Low E through s=6 High E)
        $labels = [];
        $notes  = [];

        for ($s = 1; $s <= 6; $s++) {
            if (in_array($s, $muted) && ! isset($stringFrets[$s])) {
                $labels[] = 'x';
                $notes[]  = 'x';
            } elseif (isset($stringFrets[$s])) {
                $fret = $stringFrets[$s];
                $noteSemitone = (self::$tuning[$s] + $fret) % 12;
                $intervalSemitone = ($noteSemitone - $rootSemitone + 12) % 12;

                $notes[] = $noteMap[$noteSemitone];
                $labels[] = ($imap && isset($imap[$intervalSemitone]))
                    ? $imap[$intervalSemitone]
                    : self::$genericIntervalLabels[$intervalSemitone];
            } else {
                $labels[] = 'x';
                $notes[]  = 'x';
            }
        }

        return [
            'interval_labels' => implode(',', $labels),
            'notes'           => implode(',', $notes),
        ];
    }

    /**
     * Recompute intervals for ALL diagrams in the DB.
     */
    public static function recomputeAllIntervals(): array
    {
        $diagrams = self::all();
        $updated = 0;
        $skipped = 0;
        $errors  = [];

        foreach ($diagrams as $diagram) {
            $computed = $diagram->computeIntervalsAndNotes();

            if (empty($computed['interval_labels']) && empty($computed['notes'])) {
                $skipped++;
                $errors[] = "#{$diagram->id} ({$diagram->slug}): missing data";
                continue;
            }

            $diagram->update([
                'interval_labels' => $computed['interval_labels'],
                'notes'           => $computed['notes'],
            ]);
            $updated++;
        }

        return compact('updated', 'skipped', 'errors');
    }
}
