<?php

use App\Helpers\ChordName;

if (! function_exists('chord')) {
    /**
     * Format a chord symbol with professional typography.
     *
     * Returns styled HTML (use with {!! !!} in Blade, not {{ }}).
     *
     * Usage:
     *   {!! chord('Am7b5/G') !!}
     *   {!! chord('F#m') !!}
     *   {!! chord('Dbmaj7') !!}
     *
     * @param  string  $chord  Raw chord string
     * @param  bool    $wrap   Wrap in .sbn-chord-symbol span (default: true)
     * @return string  HTML
     */
    function chord(string $chord, bool $wrap = true): string
    {
        return $wrap
            ? ChordName::styled($chord)
            : ChordName::format($chord);
    }
}
