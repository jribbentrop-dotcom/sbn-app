export function formatChordHtml(name) {
    if (typeof window.sbnFormatChordHtml === 'function') {
        return window.sbnFormatChordHtml(name);
    }
    if (typeof window.sbnFormatChord === 'function') {
        return window.sbnFormatChord(name);
    }
    if (!name) return '?';
    const m = name.match(/^([A-G][#b]?)(.*)$/);
    if (!m) return name;
    let root = m[1].replace('#','♯').replace('b','♭');
    let qual = m[2], bass = '';
    const si = qual.indexOf('/');
    if (si >= 0) { bass = '/' + qual.slice(si+1).replace('#','♯').replace('b','♭'); qual = qual.slice(0,si); }
    return root + '<sup>' + qual + '</sup>' + bass;
}

export function renderDiagramSVG(voicing) {
    if (typeof window.sbnRenderDiagramSVG === 'function') {
        return window.sbnRenderDiagramSVG(voicing);
    }
    return '';
}
