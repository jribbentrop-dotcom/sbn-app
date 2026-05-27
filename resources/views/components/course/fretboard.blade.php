{{--
    Course Fretboard component.

    Renders a <sbn-fretboard slug="..."> tag embedded in lesson content.
    The surrounding JS (chords.js) hydrates any [data-fretboard] element
    on DOMContentLoaded, so no per-component JS needed here.

    Usage in lesson content: <sbn-fretboard slug="am7-drop2-voice-leading">
--}}
@props(['fretboard'])

@if($fretboard)
<div class="sbn-fretboard-wrap theme-{{ $fretboard->theme }}"
     data-fretboard="{{ json_encode([
         'voicings'         => $fretboard->voicings ?? [],
         'display_mode'     => $fretboard->display_mode,
         'theme'            => $fretboard->theme,
         'fret_count'       => $fretboard->fret_count,
         'start_fret'       => $fretboard->start_fret,
         'show_guide_tones' => $fretboard->show_guide_tones,
         'show_rh_fingers'  => $fretboard->show_rh_fingers,
     ]) }}">
</div>
@else
<div class="sbn-callout sbn-callout-warn" style="font-size:13px;">
    ⚠️ Fretboard not found.
</div>
@endif
