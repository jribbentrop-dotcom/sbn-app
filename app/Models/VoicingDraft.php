<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VoicingDraft extends Model
{
    protected $table = 'sbn_voicing_drafts';

    protected $guarded = ['id'];

    protected $casts = [
        'position' => 'integer',
    ];

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeDismissed($query)
    {
        return $query->where('status', 'dismissed');
    }

    public function scopeForLeadsheet($query, ?int $leadsheetId)
    {
        return $leadsheetId ? $query->where('leadsheet_id', $leadsheetId) : $query;
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Mark this draft as dismissed.
     */
    public function dismiss(): bool
    {
        return $this->update([
            'status'     => 'dismissed',
            'updated_at' => now(),
        ]);
    }

    /**
     * Mark this draft as promoted and record the new diagram ID.
     */
    public function markPromoted(int $diagramId): bool
    {
        return $this->update([
            'status'     => 'promoted',
            'notes'      => 'Promoted to diagram #' . $diagramId,
            'updated_at' => now(),
        ]);
    }

    /**
     * Convert fret_string + position + fingers into diagram_data JSON array.
     */
    public function toDiagramData(): array
    {
        $positions = [];
        $muted     = [];
        $open      = [];

        $chars       = str_split($this->fret_string ?? '');
        $fingerChars = $this->fingers ? str_split($this->fingers) : [];

        foreach ($chars as $i => $char) {
            $string = $i + 1;

            if ($char === 'x' || $char === 'X') {
                $muted[] = $string;
            } elseif ($char === '0') {
                $open[] = $string;
            } else {
                // Fret strings are stored as single-char hex (a=10 ... f=15) for high positions.
                $fret = ctype_xdigit((string) $char) ? hexdec((string) $char) : intval($char);
                $finger = isset($fingerChars[$i]) ? intval($fingerChars[$i]) : null;
                if ($finger === 0) $finger = null;

                $positions[] = [
                    'string' => $string,
                    'fret'   => $fret,
                    'finger' => $finger,
                ];
            }
        }

        return [
            'positions' => $positions,
            'barres'    => [],
            'muted'     => $muted,
            'open'      => $open,
        ];
    }

    /**
     * Detect the likely root string from the fret string.
     * Returns the lowest-pitched (leftmost) sounding string identifier.
     */
    public function detectRootString(): string
    {
        $map = [
            1 => 'roote', 2 => 'roota', 3 => 'rootd',
            4 => 'rootg', 5 => 'rootb', 6 => 'roothighe',
        ];

        $chars = str_split($this->fret_string ?? '');
        foreach ($chars as $i => $char) {
            if ($char !== 'x' && $char !== 'X') {
                return $map[$i + 1] ?? 'roota';
            }
        }

        return 'roota';
    }

    /**
     * Get stats for the overview display.
     */
    public static function getStats(): array
    {
        return [
            'total_pending'   => self::pending()->count(),
            'total_dismissed' => self::dismissed()->count(),
        ];
    }
}
