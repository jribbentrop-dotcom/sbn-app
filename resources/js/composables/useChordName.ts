const QUALITY_MAP: Record<string, [string, string]> = {
    'maj':   ['', ''],
    'min':   ['m', ''],
    'aug':   ['aug', ''],
    'dim':   ['°',   ''],
    '5':     ['',    '5'],
    'sus4':  ['sus', '4'],
    'sus2':  ['sus', '2'],
    'add9':  ['',    'add9'],
    'maj7':  ['maj', '7'],
    'm7':    ['m',   '7'],
    'dom7':  ['',    '7'],
    'm7b5':  ['m',   '7♭5'],
    'o7':    ['°',   '7'],
    'maj6':  ['maj', '6'],
    'm6':    ['m',   '6'],
    'mMaj7': ['m',   'maj7'],
    'aug7':  ['aug', '7'],
    '7sus4': ['',    '7sus4'],
};

interface ChordNameData {
    root_note?: string | null;
    quality?: string | null;
    extensions?: string | null;
    bass_note?: string | null;
    transposed_from?: any;
}

function wrapAccidentals(str: string): string {
    return str
        .replace(/♭/g, '<span class="sbn-chord-accidental">♭</span>')
        .replace(/♯/g, '<span class="sbn-chord-accidental">♯</span>');
}

export function formatChordNameHtml(chord: ChordNameData, showRoot = true): string {
    const quality   = chord.quality   ?? '';
    const extension = chord.extensions ?? '';
    const doShowRoot = showRoot || !!chord.transposed_from;
    const rootRaw   = doShowRoot ? (chord.root_note ?? '') : '';
    const root      = wrapAccidentals(rootRaw.replace(/#/g, '♯').replace(/b/g, '♭'));

    const [qual, core] = QUALITY_MAP[quality] ?? ['', quality];
    const ext  = wrapAccidentals(extension.replace(/#/g, '♯').replace(/b(?=[0-9])/g, '♭'));
    const bass = wrapAccidentals(((chord.bass_note ?? '') as string).replace(/#/g, '♯').replace(/b/g, '♭'));

    let html = '<span class="sbn-chord-symbol">';
    if (root) html += `<span class="sbn-chord-root">${root}</span>`;
    if (qual) html += `<span class="sbn-chord-quality">${qual}</span>`;
    if (core) html += `<span class="sbn-chord-ext">${wrapAccidentals(core)}</span>`;
    if (ext)  html += `<span class="sbn-chord-ext sbn-chord-ext--extra">(${ext})</span>`;
    if (bass) html += `<span class="sbn-chord-bass">/${bass}</span>`;
    html += '</span>';
    return html;
}
