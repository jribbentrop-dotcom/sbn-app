<?php

namespace App\Helpers;

/**
 * Chord Name Styling
 *
 * Parses a chord symbol string (e.g. "F#m7b5/E") and returns HTML
 * with semantic spans for professional typography:
 *
 *   .sbn-chord-root        — Root note letter (A–G)
 *   .sbn-chord-accidental  — Sharp/flat after root (♯, ♭)
 *   .sbn-chord-quality     — Quality indicator (m, dim, aug, sus, etc.)
 *   .sbn-chord-ext         — Extensions & alterations (7, 9, b5, #11, add9…)
 *   .sbn-chord-bass        — Slash bass note (/E, /Bb)
 *
 * Usage in Blade:  {!! \App\Helpers\ChordName::format('Am7b5/G') !!}
 * Usage in Blade:  {!! chord('Am7b5/G') !!}   (via global helper)
 */
class ChordName
{
    /**
     * Accidental display map: input chars → unicode symbols.
     */
    private static array $accidentals = [
        '#'  => '♯',
        '♯'  => '♯',
        'b'  => '♭',
        '♭'  => '♭',
    ];

    /**
     * Quality tokens that appear immediately after the root+accidental.
     * Ordered longest-first so greedy match works correctly.
     */
    private static array $qualities = [
        'min',  'maj',  'dim',  'aug',
        'sus4', 'sus2', 'sus',
        'add',
        'm',
    ];

    /**
     * Format a chord symbol string into styled HTML.
     *
     * @param  string  $chord  Raw chord string, e.g. "F#m7b5/Eb"
     * @return string  HTML with span wrappers
     */
    public static function format(string $chord): string
    {
        $chord = trim($chord);
        if ($chord === '') return '';

        $pos = 0;
        $len = mb_strlen($chord);
        $html = '';

        // --- 1. Root note (A–G) ---
        $root = mb_substr($chord, $pos, 1);
        if (! preg_match('/^[A-Ga-g]$/', $root)) {
            // Not a chord symbol — return as-is
            return htmlspecialchars($chord, ENT_QUOTES, 'UTF-8');
        }
        $root = strtoupper($root);
        $html .= '<span class="sbn-chord-root">' . $root . '</span>';
        $pos++;

        // --- 2. Root accidental (# or b, single char) ---
        if ($pos < $len) {
            $next = mb_substr($chord, $pos, 1);
            if (isset(self::$accidentals[$next])) {
                $html .= '<span class="sbn-chord-accidental">' . self::$accidentals[$next] . '</span>';
                $pos++;
            }
        }

        // --- 3. Split off bass note (everything after /) ---
        $remaining = mb_substr($chord, $pos);
        $bassHtml  = '';

        $slashPos = mb_strpos($remaining, '/');
        if ($slashPos !== false) {
            $bassRaw   = mb_substr($remaining, $slashPos + 1);
            $remaining = mb_substr($remaining, 0, $slashPos);
            $bassHtml  = self::formatBass($bassRaw);
        }

        // --- 4. Quality ---
        $qualityFound = '';
        $remainingLower = strtolower($remaining);

        foreach (self::$qualities as $q) {
            if (str_starts_with($remainingLower, $q)) {
                $qualityFound = mb_substr($remaining, 0, mb_strlen($q));
                $remaining = mb_substr($remaining, mb_strlen($q));
                break;
            }
        }

        if ($qualityFound !== '') {
            // Suppress bare "maj" — pure major chord needs no quality label
            $isBareM = strtolower($qualityFound) === 'maj' && $remaining === '';
            if (!$isBareM) {
                $display = match (strtolower($qualityFound)) {
                    'min' => 'm',
                    default => $qualityFound,
                };
                $html .= '<span class="sbn-chord-quality">' . htmlspecialchars($display) . '</span>';
            }
        }

        // --- 5. Extensions & alterations (everything left) ---
        if ($remaining !== '') {
            // Replace text accidentals with unicode in extensions
            $extDisplay = str_replace(
                ['#', 'b'],
                ['♯', '♭'],
                $remaining
            );
            // But protect "add" from becoming "a♭♭" — restore known words
            $extDisplay = preg_replace('/a♭♭/', 'add', $extDisplay);

            $html .= '<span class="sbn-chord-ext">' . htmlspecialchars($extDisplay, ENT_QUOTES, 'UTF-8') . '</span>';
        }

        // --- 6. Bass note ---
        $html .= $bassHtml;

        return $html;
    }

    /**
     * Wrap a full chord symbol in .sbn-chord-symbol span.
     */
    public static function styled(string $chord): string
    {
        return '<span class="sbn-chord-symbol">' . self::format($chord) . '</span>';
    }

    /**
     * Normalize a chord token to its canonical text form.
     * Strips bare "maj" from major triads (Gmaj → G, F#MAJ → F#) while
     * preserving "maj" inside extended qualities (Gmaj7, Cmaj9 stay intact).
     */
    public static function normalize(string $chord): string
    {
        $chord = trim($chord);
        if ($chord === '') return $chord;

        // Split off bass
        $bass = '';
        $slashIdx = strpos($chord, '/');
        if ($slashIdx !== false) {
            $bass  = substr($chord, $slashIdx);
            $chord = substr($chord, 0, $slashIdx);
        }

        // Match root + accidental + the rest
        if (! preg_match('/^([A-Ga-g])([#b♯♭]?)(.*)$/u', $chord, $m)) {
            return $chord . $bass;
        }
        $root = strtoupper($m[1]);
        $acc  = $m[2];
        $rest = $m[3];

        // Drop bare "maj" (case-insensitive) when nothing follows it.
        if (preg_match('/^maj$/i', $rest)) {
            $rest = '';
        }

        return $root . $acc . $rest . $bass;
    }

    /**
     * Format the bass note portion of a slash chord.
     */
    private static function formatBass(string $bass): string
    {
        $bass = trim($bass);
        if ($bass === '') return '';

        $html = '<span class="sbn-chord-bass">/';

        // Bass root
        $root = strtoupper(mb_substr($bass, 0, 1));
        $html .= $root;

        // Bass accidental
        if (mb_strlen($bass) > 1) {
            $acc = mb_substr($bass, 1, 1);
            if (isset(self::$accidentals[$acc])) {
                $html .= '<span class="sbn-bass-accidental">' . self::$accidentals[$acc] . '</span>';
            }
        }

        $html .= '</span>';
        return $html;
    }
}
