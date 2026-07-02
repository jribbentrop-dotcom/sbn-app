<?php

namespace App\Services;

/**
 * ChordFretString — the single PHP authority for the SBN fret-string ↔
 * diagram_data conversion. Mirrors `resources/js/utils/fretString.ts`
 * (keep the two in sync).
 *
 * A **fret string** is exactly 6 characters, one per diagram string 1→6 (low E
 * → high e in SBN's chord-diagram convention):
 *   - 'x' (or 'X') → muted string
 *   - '0'          → open string
 *   - '1'–'9', 'a'–'f' → fret number in **hex** (so frets 10–15 stay single-char)
 *
 * `diagram_data` is the object form stored on chord diagrams:
 *   ['positions' => [['string' => int, 'fret' => int]], 'barres' => [],
 *    'muted' => int[], 'open' => int[]]
 *
 * Historically this conversion was copy-pasted across five sites (see
 * SBN-Leadsheet-Reference §10). Everything below is the shared implementation.
 */
class ChordFretString
{
    /**
     * Decode a single fret-string character to a fret number.
     * Returns null for muted ('x'/'X') or unparseable input. Open is 0.
     */
    public static function parseFretChar(?string $ch): ?int
    {
        if ($ch === null || $ch === 'x' || $ch === 'X' || $ch === '') {
            return null;
        }
        $n = intval($ch, 16); // handles '0'-'9' and 'a'-'f'
        return $n >= 0 ? $n : null;
    }

    /**
     * Encode a fret number (or 'x') to a single fret-string character.
     * Frets >= 10 become hex 'a'-'f'.
     *
     * @param int|string $fret  a fret number, or 'x' for muted
     */
    public static function fretToChar($fret): string
    {
        return $fret === 'x' ? 'x' : dechex((int) $fret);
    }

    /**
     * Convert diagram_data (array) → 6-char fret string.
     * Precedence per string: open → position → muted (muted wins if a string is
     * listed in more than one bucket, matching the JS order of overwrites).
     */
    public static function diagramDataToFretString(array $data): string
    {
        $frets = ['x', 'x', 'x', 'x', 'x', 'x'];

        foreach ($data['open'] ?? [] as $s) {
            if ($s >= 1 && $s <= 6) {
                $frets[$s - 1] = 0;
            }
        }
        foreach ($data['positions'] ?? [] as $pos) {
            $s = $pos['string'] ?? null;
            if ($s !== null && $s >= 1 && $s <= 6) {
                $frets[$s - 1] = $pos['fret'];
            }
        }
        foreach ($data['muted'] ?? [] as $s) {
            if ($s >= 1 && $s <= 6) {
                $frets[$s - 1] = 'x';
            }
        }

        return implode('', array_map([self::class, 'fretToChar'], $frets));
    }

    /**
     * Convert a 6-char fret string → diagram_data (array).
     * `barres` is always empty — a flat fret string carries no barre grouping.
     */
    public static function fretStringToDiagramData(string $frets): array
    {
        $positions = [];
        $open      = [];
        $muted     = [];

        for ($i = 0; $i < 6; $i++) {
            $stringNum = $i + 1;
            $fret      = self::parseFretChar($frets[$i] ?? 'x');

            if ($fret === null) {
                $muted[] = $stringNum;
            } elseif ($fret === 0) {
                $open[] = $stringNum;
            } else {
                $positions[] = ['string' => $stringNum, 'fret' => $fret];
            }
        }

        return [
            'positions' => $positions,
            'barres'    => [],
            'muted'     => $muted,
            'open'      => $open,
        ];
    }
}
