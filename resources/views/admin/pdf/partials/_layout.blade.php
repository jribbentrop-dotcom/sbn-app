<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>{{ $title ?? 'PDF' }} — Soul Bossa Nova</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300..700;1,9..40,300..700&family=Crimson+Text:ital,wght@0,400;0,600;1,400&family=Fraunces:ital,opsz,wght@0,9..144,300..900;1,9..144,300..900&display=swap" rel="stylesheet">

<style>
@font-face {
    font-family: 'Bravura';
    src: url('{{ asset('fonts/Bravura.woff2') }}') format('woff2'),
         url('{{ asset('fonts/Bravura.woff') }}') format('woff'),
         url('{{ asset('fonts/Bravura.otf') }}') format('opentype');
    font-weight: normal; font-style: normal;
}

.sbn-chord-symbol { display: inline-block; font-family: var(--font-chord); font-size: 1.05em; color: var(--sbn-chord-color, var(--clr-text, #2c3e50)); font-weight: 600; white-space: nowrap; vertical-align: baseline; }
.sbn-chord-root { font-weight: 700; font-size: 1.1em; }
.sbn-chord-quality { font-style: normal !important; font-weight: 400; }
.sbn-chord-accidental { font-size: 0.95em; position: relative; top: -0.05em; }
.sbn-chord-ext { font-size: 0.75em; font-weight: 600; line-height: 0; vertical-align: super; }
.sbn-chord-bass { font-size: 0.9em; font-weight: 400; color: inherit; margin-left: 1px; vertical-align: baseline; }
.sbn-bass-accidental { font-size: 0.85em; }

:root {
    --clr-bg:          #f8f9fb;
    --clr-white:       #ffffff;
    --clr-text:        #2c3e50;
    --clr-text-dim:    #5a5a5a;
    --clr-text-muted:  #8896a4;
    --clr-accent:      #f39c12;
    --clr-accent-dim:  #e67e22;
    --clr-red:         #e74c3c;
    --clr-border:      #e2e8f0;
    --clr-gradient:    linear-gradient(135deg, #f39c12 0%, #e74c3c 100%);
    --font-body:    'DM Sans', system-ui, sans-serif;
    --font-chord:   'Crimson Text', Georgia, serif;
    --font-display: 'Fraunces', Georgia, serif;
    --radius-sm: 6px;
    --radius: 10px;
}

@page { size: A4 portrait; margin: 0; }
* { box-sizing: border-box; margin: 0; padding: 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
body { font-family: var(--font-body); font-size: 10pt; color: var(--clr-text); background: #fff; }

.pdf-page { page-break-after: always; break-after: page; position: relative; width: 210mm; height: 297mm; overflow: hidden; }
.pdf-page:last-child { page-break-after: avoid; }

.badge { display: inline-flex; align-items: center; justify-content: center; border-radius: 50%; background: var(--clr-gradient); color: #fff; font-family: var(--font-body); font-weight: 700; }
.badge--lg { width: 30mm; height: 30mm; font-size: 22pt; box-shadow: 0 4pt 14pt rgba(231,76,60,0.25); }
.badge--md { width: 17mm; height: 17mm; font-size: 13pt; }
.badge--sm { width: 16pt; height: 16pt; font-size: 7.5pt; }

/* ── COVER ── */
.cover { padding: 22mm 20mm 16mm; display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; }
.cover__logo { display: block; max-width: 64mm; height: auto; margin: 0 auto 16pt; }
.cover__eyebrow { font-size: 9pt; font-weight: 700; letter-spacing: 0.14em; text-transform: uppercase; color: var(--clr-accent-dim); margin-bottom: 10pt; }
.cover__title { font-family: var(--font-display); font-weight: 800; font-size: 42pt; line-height: 1.05; color: var(--clr-text); margin-bottom: 8pt; }
.cover__subtitle { font-family: var(--font-chord); font-style: italic; font-size: 14pt; color: var(--clr-text-dim); margin-bottom: 32pt; }
.cover__hook { font-size: 12pt; line-height: 1.6; color: var(--clr-text); max-width: 360pt; margin-bottom: 36pt; }
.cover__facts { display: flex; flex-direction: column; gap: 10pt; max-width: 400pt; text-align: left; }
.cover__fact { font-size: 10pt; line-height: 1.5; color: var(--clr-text-dim); padding-left: 14pt; border-left: 2.5pt solid var(--clr-accent); }
.cover__footer { position: absolute; bottom: 12mm; left: 0; right: 0; text-align: center; font-size: 8pt; color: var(--clr-text-muted); letter-spacing: 0.05em; }

/* ── THEORY PAGE ── */
.theory { padding: 22mm 22mm 16mm; }
.theory__eyebrow { font-size: 8pt; font-weight: 700; letter-spacing: 0.12em; text-transform: uppercase; color: var(--clr-text-muted); border-bottom: 1.5pt solid var(--clr-border); padding-bottom: 8pt; margin-bottom: 22pt; }
.theory__title { font-family: var(--font-display); font-weight: 700; font-size: 22pt; color: var(--clr-text); margin-bottom: 18pt; }
.theory__body p { font-size: 10.5pt; line-height: 1.75; color: var(--clr-text); margin-bottom: 12pt; }
.theory__body strong { font-weight: 700; }
.theory__footer { position: absolute; bottom: 10mm; left: 22mm; right: 22mm; display: flex; justify-content: space-between; font-size: 7.5pt; color: var(--clr-text-muted); border-top: 0.75pt solid var(--clr-border); padding-top: 6pt; }

/* ── ITEM PAGE ── */
.item { padding: 20mm 18mm 16mm 18mm; }
.item__eyebrow { font-size: 8pt; font-weight: 700; letter-spacing: 0.12em; text-transform: uppercase; color: var(--clr-text-muted); border-bottom: 1.5pt solid var(--clr-border); padding-bottom: 8pt; margin-bottom: 18pt; display: flex; justify-content: space-between; }
.item__head { display: flex; align-items: center; gap: 16pt; margin-bottom: 14pt; }
.item__title { font-family: var(--font-display); font-weight: 700; font-size: 21pt; color: var(--clr-text); }
.item__layout { position: relative; padding-right: calc(38mm + 12mm); }
.item__main { min-width: 0; }
.item__intro { display: flex; align-items: center; gap: 18pt; margin-bottom: 10pt; }
.item__lede { font-family: var(--font-chord); font-style: italic; font-size: 12.5pt; line-height: 1.6; color: var(--clr-text-dim); margin: 0; }
.item__diagram-feature { flex-shrink: 0; width: 96pt; display: flex; flex-direction: column; align-items: center; gap: 0; }
.item__diagram-feature svg { width: 100%; display: block; }
.item__diagram-chordname { font-size: 13pt; line-height: 1; margin: 0; }
.item__body { font-size: 10.5pt; line-height: 1.7; color: var(--clr-text); }
.item__body p + p { margin-top: 10pt; }
.item__section-label { font-size: 8pt; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; color: var(--clr-accent-dim); margin: 10pt 0 4pt; }
.pattern-steps { display: flex; flex-direction: column; gap: 24pt; margin-top: 16pt; }
.pattern-step { display: flex; gap: 12pt; align-items: flex-start; }
.pattern-step__num { flex-shrink: 0; width: 18pt; height: 18pt; border-radius: 50%; background: var(--clr-accent); color: #fff; font-size: 9pt; font-weight: 700; display: flex; align-items: center; justify-content: center; margin-top: 1pt; }
.pattern-step__body { flex: 1; }
.pattern-step__label { font-size: 10pt; font-weight: 700; color: var(--clr-text); margin-bottom: 7pt; }
.pattern-step__meta { font-size: 8pt; color: var(--clr-text-muted); margin-bottom: 7pt; }
.rhythm-grid { display: flex; flex-direction: column; gap: 4pt; max-width: 300pt; }
.rg-row { display: flex; align-items: center; gap: 6pt; }
.rg-rowlabel { width: 38pt; font-size: 7pt; font-weight: 700; color: var(--clr-text-muted); text-align: right; flex-shrink: 0; }
.rg-cells { display: flex; gap: 3pt; flex: 1; }
.rg-cell { flex: 1; height: 15pt; border-radius: 3pt; background: var(--clr-border); }
.rg-row--labels .rg-cell { background: transparent; display: flex; align-items: center; justify-content: center; font-size: 8pt; font-weight: 700; color: var(--clr-text-muted); height: 11pt; }
.rg-cell.rg-rest { height: 4pt; align-self: center; background: var(--clr-border); }
.rg-cell.rg-hit { height: 15pt; background: var(--clr-accent); opacity: 0.85; }
.rg-cell--thumb { height: 8pt; }
.rg-cell--thumb.rg-rest { height: 2pt; }
.rg-cell--thumb.rg-hit { height: 8pt; background: var(--clr-text-dim); opacity: 0.7; }
.pattern-tab { }
.pattern-tab svg { width: 100%; display: block; }
.pattern-tab svg text[font-style="italic"] { font-style: normal !important; font-family: 'Crimson Text', Georgia, serif; font-size: 13pt !important; font-weight: 600; fill: var(--clr-text) !important; }
.pattern-tab svg text[dominant-baseline="central"] { paint-order: stroke fill; stroke: #fff; stroke-width: 3px; stroke-linejoin: round; }
.item__margin { position: absolute; top: 0; right: 0; width: 38mm; display: flex; flex-direction: column; gap: 16pt; background: #faf8f5; padding: 8pt; border-radius: 6pt; }
.item__margin-identity { text-align: center; padding-bottom: 14pt; border-bottom: 1pt solid var(--clr-border); }
.item__margin-chordname { font-size: 16pt; margin-bottom: 7pt; line-height: 1; }
.item__voicing-pill { display: inline-block; background: var(--clr-accent); color: #fff; font-size: 7pt; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; padding: 3pt 10pt; border-radius: 10pt; }
.item__margin-label { font-size: 7pt; font-weight: 700; letter-spacing: 0.12em; text-transform: uppercase; color: var(--clr-text-muted); margin-bottom: 4pt; }
.item__margin-note { font-size: 9pt; line-height: 1.5; color: var(--clr-text-dim); }
.item__margin-note strong { color: var(--clr-text); }
.item__intervals { text-align: center; }
.item__interval-row { display: flex; justify-content: center; align-items: center; gap: 4pt; margin-top: 4pt; flex-wrap: nowrap; }
.item__interval-pill { display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; min-width: 15pt; height: 15pt; padding: 0 3pt; border-radius: 7.5pt; font-family: var(--font-body); font-size: 7pt; font-weight: 700; color: #fff; line-height: 1; }
.item__interval-pill--root    { background: #16a34a; }
.item__interval-pill--third   { background: #2563eb; }
.item__interval-pill--fifth   { background: #6b7280; }
.item__interval-pill--seventh { background: #d97706; }
.item__interval-pill--ext     { background: #7c3aed; }
.item__footer { position: absolute; bottom: 10mm; left: 18mm; right: 18mm; display: flex; justify-content: space-between; font-size: 7.5pt; color: var(--clr-text-muted); border-top: 0.75pt solid var(--clr-border); padding-top: 6pt; }

/* ── EXAMPLE PAGE ── */
.example { padding: 20mm 18mm 16mm 18mm; }
.example__eyebrow { font-size: 8pt; font-weight: 700; letter-spacing: 0.12em; text-transform: uppercase; color: var(--clr-text-muted); margin-bottom: 6pt; }
.example__title { font-family: var(--font-display); font-weight: 700; font-size: 24pt; color: var(--clr-text); margin-bottom: 4pt; }
.example__sub { font-family: var(--font-chord); font-style: italic; font-size: 11pt; color: var(--clr-text-dim); margin-bottom: 18pt; }
.example__legend { display: flex; gap: 10pt; align-items: center; margin-bottom: 22pt; padding: 10pt 14pt; border: 1pt solid var(--clr-border); border-radius: var(--radius); background: var(--clr-white); flex-wrap: wrap; }
.example__legend-title { font-size: 7.5pt; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; color: var(--clr-text-muted); margin-right: 4pt; }
.chip { display: inline-flex; align-items: center; gap: 7pt; font-size: 8.5pt; font-weight: 600; color: var(--clr-text-dim); }
.example__notation-wrap { margin-bottom: 14pt; }
.example__notation-wrap svg { width: 100%; display: block; }
.example__note { font-size: 9.5pt; line-height: 1.6; color: var(--clr-text-dim); border-left: 2.5pt solid var(--clr-accent); padding-left: 10pt; margin-top: 16pt; }
.example__footer { position: absolute; bottom: 10mm; left: 18mm; right: 18mm; display: flex; justify-content: space-between; font-size: 7.5pt; color: var(--clr-text-muted); border-top: 0.75pt solid var(--clr-border); padding-top: 6pt; }

@yield('extra-styles')
</style>

<script>
(function () {
    'use strict';
    const ACCIDENTALS = { '#': '♯', '♯': '♯', 'b': '♭', '♭': '♭' };
    const QUALITIES = ['min', 'maj', 'dim', 'aug', 'sus4', 'sus2', 'sus', 'add', 'm'];
    function esc(s) { const d = document.createElement('span'); d.textContent = s; return d.innerHTML; }
    function formatBass(bass) {
        bass = bass.trim(); if (!bass) return '';
        let html = '<span class="sbn-chord-bass">/';
        html += bass[0].toUpperCase();
        if (bass.length > 1 && ACCIDENTALS[bass[1]]) html += '<span class="sbn-bass-accidental">' + ACCIDENTALS[bass[1]] + '</span>';
        html += '</span>'; return html;
    }
    window.sbnFormatChord = function (chord) {
        chord = (chord || '').trim(); if (!chord) return '';
        let pos = 0, html = '';
        const root = chord[pos];
        if (!/^[A-Ga-g]$/.test(root)) return esc(chord);
        html += '<span class="sbn-chord-root">' + root.toUpperCase() + '</span>'; pos++;
        if (pos < chord.length && ACCIDENTALS[chord[pos]]) { html += '<span class="sbn-chord-accidental">' + ACCIDENTALS[chord[pos]] + '</span>'; pos++; }
        let remaining = chord.slice(pos), bassHtml = '';
        const slashIdx = remaining.indexOf('/');
        if (slashIdx !== -1) { bassHtml = formatBass(remaining.slice(slashIdx + 1)); remaining = remaining.slice(0, slashIdx); }
        const remainingLower = remaining.toLowerCase();
        let qualityLen = 0;
        for (const q of QUALITIES) {
            if (remainingLower.startsWith(q)) {
                qualityLen = q.length;
                const afterQuality = remaining.slice(qualityLen);
                const isBareM = q === 'maj' && !afterQuality;
                if (!isBareM) { let display = q === 'min' ? 'm' : remaining.slice(0, qualityLen); html += '<span class="sbn-chord-quality">' + esc(display) + '</span>'; }
                break;
            }
        }
        remaining = remaining.slice(qualityLen);
        if (remaining) { let extDisplay = remaining.replace(/#/g, '♯').replace(/b/g, '♭'); extDisplay = extDisplay.replace(/a♭♭/g, 'add'); html += '<span class="sbn-chord-ext">' + esc(extDisplay) + '</span>'; }
        html += bassHtml; return html;
    };
    window.sbnStyledChord = function (chord) { return '<span class="sbn-chord-symbol">' + sbnFormatChord(chord) + '</span>'; };
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-chord]').forEach(function (el) {
            el.innerHTML = sbnFormatChord(el.dataset.chord);
        });
    });
})();
</script>
</head>
<body>

@yield('pages')

</body>
</html>
