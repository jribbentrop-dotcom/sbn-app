<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChordDiagramAlias extends Model
{
    protected $table = 'sbn_chord_diagram_aliases';

    protected $guarded = ['id'];

    public $timestamps = false;

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    public function diagram()
    {
        return $this->belongsTo(ChordDiagram::class, 'diagram_id');
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Build the display name from alias fields.
     */
    public static function buildAltName(string $rootNote, string $quality, ?string $extensions = null, ?string $bassNote = null): string
    {
        $name = $rootNote . $quality;
        if ($extensions) {
            $name .= $extensions;
        }
        if ($bassNote) {
            $name .= '/' . $bassNote;
        }
        return $name;
    }

    /**
     * Compute interval labels for an alias using the parent diagram's fret data
     * but with the alias's root note and quality.
     */
    public static function computeAliasIntervals(ChordDiagram $diagram, string $altRootNote, string $altQuality): array
    {
        // Clone the diagram's relevant fields into a temporary model
        $fake = new ChordDiagram();
        $fake->root_note    = $altRootNote;
        $fake->quality      = $altQuality;
        $fake->root_string  = 'custom';   // Force root_note fallback
        $fake->inversion    = 'root';
        $fake->diagram_data = $diagram->diagram_data;
        $fake->start_fret   = $diagram->start_fret;

        return $fake->computeIntervalsAndNotes();
    }
}
