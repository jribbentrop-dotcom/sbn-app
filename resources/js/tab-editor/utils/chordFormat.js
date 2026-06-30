export function formatChordHtml(name) {
    if (typeof window.sbnFormatChordHtml === 'function') {
        return window.sbnFormatChordHtml(name);
    }
    if (typeof window.sbnFormatChord === 'function') {
        return window.sbnFormatChord(name);
    }
    if (!name) return '';
    const m = name.match(/^([A-G][#b♯♭]?)(.*)$/);
    if (!m) return name;
    let root = m[1].replace('#','♯').replace(/b(?=[^0-9]|$)/,'♭');
    let qual = m[2], bass = '';
    const si = qual.indexOf('/');
    if (si >= 0) { bass = '/' + qual.slice(si+1).replace('#','♯').replace('b','♭'); qual = qual.slice(0,si); }
    // Suppress bare "maj" — pure major needs no quality label
    if (qual.toLowerCase() === 'maj') qual = '';
    // Replace "min" → "m"
    if (qual.toLowerCase() === 'min') qual = 'm';
    // Internal "dom" quality → conventional dominant spelling:
    //   dom7 → 7, dom7(9) → 7(9), dom9 → 9, dom13 → 13, bare dom → 7.
    qual = qual.replace(/^dom7/i, '7').replace(/^dom(\d)/i, '$1').replace(/^dom(?=\(|$)/i, '7');
    // Unicode accidentals in extensions
    let ext = qual.replace(/#/g,'♯').replace(/b(?=[0-9])/g,'♭');
    return root + (ext ? '<sup>' + ext + '</sup>' : '') + bass;
}

export function renderDiagramSVG(voicing) {
    if (typeof window.sbnRenderDiagramSVG === 'function') {
        return window.sbnRenderDiagramSVG(voicing);
    }
    return '';
}
