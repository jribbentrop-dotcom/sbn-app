/**
 * SBN Tab Editor — Voicing Picker Store (Phase B Step 5)
 *
 * Ports Alpine's voicingPicker state and methods verbatim into a Vue composable.
 * Module-level singleton — only one picker in the app at a time.
 *
 * Initialise once from TabEditor.vue:
 *   const picker = useVoicingPickerStore(model, { wrapCommand }, { applyTabFrets: onVoicingApplied });
 *   provide('voicingPicker', picker);
 *
 * Consume via inject in any component:
 *   const picker = inject('voicingPicker');
 *   picker.open / picker.openForChord(name, gi, ci) / picker.applyVoicing(v) / …
 */

import { reactive } from 'vue';

// ── Module-level dependencies — set by first call with args ──────────────────
let _model         = null;
let _undo          = null;
let _applyTabFrets = null;

// ── Singleton reactive state (mirrors Alpine's voicingPicker object) ──────────
const store = reactive({
    open:        false,
    chordName:   '',
    voicingKey:  null,
    loading:     false,
    results:     [],
    hasExisting: false,

    // Parsed chord components used to build filter defaults
    root: '', quality: '', extension: '', bassNote: '', inversion: '',

    // Filter option lists returned by the API
    filters: { voicing_categories: [], root_strings: [], extensions: [], inversions: [] },

    // Currently-applied filter values
    activeFilters: {
        voicing_category: 'all',
        root_string:      'all',
        extension:        '',
        inversion:        'all',
    },

    // Extension stepper
    extensionCycles: {
        '9':  ['b9', '9', '#9'],
        '11': ['11', '#11'],
        '13': ['b13', '13'],
    },
    extensionGroup: '',
    extensionIdx:   -1,

    // Tab-source context (set when opened from a tab chord click)
    _tabSource:     null,   // { chordName, voicingKey, currentFrets, currentPosition, globalMeasureIndex, chordIndex }
    _tabMatchIndex: -1,     // index in results[] that matches currentFrets, or -1
});

// ── Private helpers ───────────────────────────────────────────────────────────

/**
 * Client-side chord parser — mirrors server-side parseChordName.
 * Verbatim port of Alpine's parseChordForPicker.
 */
function _parseChordForPicker(chordName) {
    const m = chordName.match(/^([A-G][#b]?)(.*?)(?:\/([A-G][#b]?))?$/);
    if (!m) return { root: '', quality: 'maj', extension: '', bassNote: '', inversion: '' };
    let root = m[1], body = m[2] || '', bassNote = m[3] || '';

    const shorthands = {
        '9': ['dom7','9'], '11': ['dom7','11'], '13': ['dom7','13'],
        '7b9': ['dom7','b9'], '7#9': ['dom7','#9'], '7b13': ['dom7','b13'], '7#11': ['dom7','#11'],
        'maj9': ['maj7','9'], 'maj11': ['maj7','11'], 'maj13': ['maj7','13'], 'maj7#11': ['maj7','#11'],
        'M9': ['maj7','9'], 'M11': ['maj7','11'], 'M13': ['maj7','13'],
        'Δ9': ['maj7','9'], '△9': ['maj7','9'],
        'm9': ['m7','9'], 'm11': ['m7','11'], 'm13': ['m7','13'],
        'min9': ['m7','9'], 'min11': ['m7','11'], 'min13': ['m7','13'],
        '-9': ['m7','9'], '-11': ['m7','11'], '-13': ['m7','13'],
        'ø9': ['m7b5','9'],
    };

    let extension = '';
    const parenMatch = body.match(/^(.+?)\(([^)]+)\)$/);
    if (parenMatch) { body = parenMatch[1]; extension = parenMatch[2]; }

    if (!extension && shorthands[body]) {
        return { root, quality: shorthands[body][0], extension: shorthands[body][1], bassNote, inversion: '' };
    }

    const qualMap = [
        ['m7b5','m7b5'], ['m7♭5','m7b5'], ['ø7','m7b5'], ['ø','m7b5'],
        ['mMaj7','mMaj7'], ['mmaj7','mMaj7'], ['mM7','mMaj7'],
        ['maj7','maj7'], ['M7','maj7'], ['Δ7','maj7'], ['△7','maj7'],
        ['maj6','maj6'], ['M6','maj6'], ['6','maj6'],
        ['min7','m7'], ['m7','m7'], ['min6','m6'], ['m6','m6'],
        ['dom7','dom7'], ['7','dom7'], ['7sus4','7sus4'],
        ['aug7','aug7'], ['aug','aug'], ['+7','aug7'], ['+','aug'],
        ['dim7','o7'], ['o7','o7'], ['°7','o7'], ['dim','dim'], ['°','dim'],
        ['sus4','sus4'], ['sus2','sus2'], ['sus','sus4'], ['add9','add9'],
        ['maj','maj'], ['min','min'], ['m','min'], ['-','min'],
    ];
    const caseSensitiveOnly = new Set(['M', 'M7', 'M6']);

    for (const [pat, canon] of qualMap) {
        if (body === pat) return { root, quality: canon, extension, bassNote, inversion: '' };
        if (!caseSensitiveOnly.has(pat) && body.toLowerCase() === pat.toLowerCase()) {
            return { root, quality: canon, extension, bassNote, inversion: '' };
        }
    }

    if (!extension) {
        for (const [pat, canon] of qualMap) {
            if (body.startsWith(pat)) {
                const trail = body.slice(pat.length);
                if (trail && /^[b#]?\d/.test(trail)) {
                    return { root, quality: canon, extension: trail, bassNote, inversion: '' };
                }
            }
        }
    }

    if (body === '') return { root, quality: 'maj', extension, bassNote, inversion: '' };
    return { root, quality: body, extension, bassNote, inversion: '' };
}

function _inferInversion(bassNote, root, quality) {
    if (!bassNote) return '';
    const semi = { 'C':0,'C#':1,'Db':1,'D':2,'D#':3,'Eb':3,'E':4,'F':5,'F#':6,'Gb':6,'G':7,'G#':8,'Ab':8,'A':9,'A#':10,'Bb':10,'B':11 };
    const rootS = semi[root], bassS = semi[bassNote];
    if (rootS === undefined || bassS === undefined) return '';
    const interval = ((bassS - rootS) + 12) % 12;
    const inv = { 3: 'inv1', 4: 'inv1', 7: 'inv2', 10: 'inv3', 11: 'inv3' };
    return inv[interval] || '';
}

function _diagramDataToFrets(dd) {
    if (!dd) return '';
    const result = ['x','x','x','x','x','x'];
    if (dd.open)      dd.open.forEach(s      => { if (s >= 1 && s <= 6) result[s-1] = '0'; });
    if (dd.positions) dd.positions.forEach(p => {
        if (p.string >= 1 && p.string <= 6 && p.fret > 0)
            result[p.string-1] = p.fret <= 9 ? String(p.fret) : p.fret.toString(16);
    });
    if (dd.barres) dd.barres.forEach(b => {
        const from = Math.min(b.fromString, b.toString);
        const to   = Math.max(b.fromString, b.toString);
        for (let s = from; s <= to; s++) {
            if (s >= 1 && s <= 6 && result[s-1] === 'x')
                result[s-1] = b.fret <= 9 ? String(b.fret) : b.fret.toString(16);
        }
    });
    return result.join('');
}

function _diagramDataToFingers(dd) {
    if (!dd) return '000000';
    const result = ['0','0','0','0','0','0'];
    if (dd.positions) dd.positions.forEach(p => {
        if (p.string >= 1 && p.string <= 6 && p.finger > 0)
            result[p.string-1] = String(p.finger);
    });
    if (dd.barres) dd.barres.forEach(b => {
        const from = Math.min(b.fromString, b.toString);
        const to   = Math.max(b.fromString, b.toString);
        const finger = b.finger > 0 ? String(b.finger) : '1';
        for (let s = from; s <= to; s++) {
            if (s >= 1 && s <= 6) result[s-1] = finger;
        }
    });
    return result.join('');
}

// ── API call ──────────────────────────────────────────────────────────────────

async function _fetchVoicings() {
    store.loading = true;
    try {
        const params = new URLSearchParams({
            root:             store.root,
            quality:          store.quality,
            extension:        store.activeFilters.extension || '',
            inversion:        store.activeFilters.inversion || 'all',
            voicing_category: store.activeFilters.voicing_category || 'all',
            root_string:      store.activeFilters.root_string || 'all',
            bass_note:        store.bassNote || '',
        });
        const tokenMeta = document.querySelector('meta[name=csrf-token]');
        const resp = await fetch(
            '/api/admin/leadsheets/search-voicings-advanced?' + params.toString(),
            {
                headers: {
                    'X-CSRF-TOKEN': tokenMeta ? tokenMeta.content : '',
                    'Accept':       'application/json',
                },
            },
        );
        const data = await resp.json();
        if (data.success && Array.isArray(data.results)) {
            store.results = data.results.map(v => {
                let frets = '', fingers = '000000', pos = parseInt(v.start_fret) || 1;
                if (v.diagram_data) {
                    try {
                        const dd = typeof v.diagram_data === 'string'
                            ? JSON.parse(v.diagram_data)
                            : v.diagram_data;
                        frets   = _diagramDataToFrets(dd);
                        fingers = _diagramDataToFingers(dd);
                    } catch (_) { /* ignore malformed */ }
                }
                return {
                    frets,
                    fingers,
                    position:         pos,
                    id:               v.id ?? null,
                    name:             v.name || '',
                    dim_name:         (v.dim_inversion || v.alias_match) ? (v.name || '') : '',
                    voicing_category: v.voicing_category,
                    inversion:        v.inversion,
                    root_string:      v.root_string,
                    popularity:       v.popularity || 0,
                    rootless:         v.rootless ?? false,
                    alias_match:      v.alias_match ?? false,
                    dim_inversion:    v.dim_inversion ?? false,
                    alias_root:       v.alias_root ?? '',
                    alias_quality:    v.alias_quality ?? '',
                };
            }).filter(v => v.frets);
        }
        if (data.filters) store.filters = data.filters;
    } catch (e) {
        console.warn('[SBN VoicingPicker] fetch failed:', e);
    }
    store.loading = false;

    // Find which result matches the current tab frets (tab-source path)
    if (store._tabSource && store._tabSource.currentFrets) {
        store._tabMatchIndex = store.results.findIndex(r => r.frets === store._tabSource.currentFrets);
    } else {
        store._tabMatchIndex = -1;
    }
}

// ── Core open ─────────────────────────────────────────────────────────────────

async function _openPicker(chordName, voicingKey) {
    const lookupKey = voicingKey || chordName;
    const parsed    = _parseChordForPicker(chordName);
    const inversion = _inferInversion(parsed.bassNote, parsed.root, parsed.quality);
    const cv        = _model?.value?.chordVoicings;

    store.open        = true;
    store.chordName   = chordName;
    store.voicingKey  = voicingKey;
    store.loading     = true;
    store.results     = [];
    store.hasExisting = !!(cv && (cv[lookupKey] || cv[chordName]));
    store.root        = parsed.root;
    store.quality     = parsed.quality;
    store.extension   = parsed.extension;
    store.bassNote    = parsed.bassNote;
    store.inversion   = inversion || '';
    store.activeFilters = {
        voicing_category: 'all',
        root_string:      'all',
        extension:        parsed.extension || '',
        inversion:        inversion ? inversion : 'all',
    };
    store.extensionGroup = '';
    store.extensionIdx   = -1;

    if (parsed.extension) {
        for (const [grp, cycle] of Object.entries(store.extensionCycles)) {
            const idx = cycle.indexOf(parsed.extension);
            if (idx !== -1) {
                store.extensionGroup = grp;
                store.extensionIdx   = idx;
                break;
            }
        }
    }

    await _fetchVoicings();
}

// ── Public API ────────────────────────────────────────────────────────────────

/**
 * Open picker for a chord-grid card click.
 * gi / ci are the global measure index and chord-slot index.
 * Pass gi=null for a global (name-only) lookup (e.g. from the overview).
 */
function openForChord(name, gi, ci) {
    // Clear tab context to prevent bleed from tab view
    store._tabSource = null;
    store._tabMatchIndex = -1;
    const voicingKey = (gi != null) ? `${name}@${gi}.${ci}` : null;
    _openPicker(name, voicingKey);
}

/**
 * Open picker triggered from a tab-view chord click.
 * src = { chordName, voicingKey, currentFrets, currentPosition, globalMeasureIndex, chordIndex }
 */
function openForTab(src) {
    store._tabSource = src;
    _openPicker(src.chordName, src.voicingKey);
}

/**
 * Build the display chord name reflecting current filter state (extension / inversion).
 * Verbatim port of Alpine's pickerDisplayName().
 */
function pickerDisplayName() {
    const qDisplay = {
        'maj7':'maj7','dom7':'7','m7':'m7','m7b5':'m7b5','o7':'dim7',
        'mMaj7':'mMaj7','maj6':'6','m6':'m6','aug7':'aug7','aug':'aug',
        'dim':'dim','sus4':'sus4','sus2':'sus2','7sus4':'7sus4',
        'add9':'add9','maj':'','min':'m','5':'5',
    };
    let name = store.root + (qDisplay[store.quality] !== undefined ? qDisplay[store.quality] : store.quality);
    const ext = store.activeFilters.extension;
    if (ext) name += '(' + ext + ')';
    const inv = store.activeFilters.inversion;
    if (inv && inv !== 'all' && inv !== 'root') {
        const semi       = { 'C':0,'C#':1,'Db':1,'D':2,'D#':3,'Eb':3,'E':4,'F':5,'F#':6,'Gb':6,'G':7,'G#':8,'Ab':8,'A':9,'A#':10,'Bb':10,'B':11 };
        // Inversion bass is spelled by the unified enharmonic core (key-aware) at
        // the end of this function — emit a neutral sharp here and let it re-spell.
        const semiToNote = ['C','C#','D','D#','E','F','F#','G','G#','A','A#','B'];
        const invIntervals = {
            'maj7':{'inv1':4,'inv2':7,'inv3':11},'dom7':{'inv1':4,'inv2':7,'inv3':10},
            'm7':{'inv1':3,'inv2':7,'inv3':10},'m6':{'inv1':3,'inv2':7,'inv3':9},
            'maj6':{'inv1':4,'inv2':7,'inv3':9},'m7b5':{'inv1':3,'inv2':6,'inv3':10},
            'o7':{'inv1':3,'inv2':6,'inv3':9},'mMaj7':{'inv1':3,'inv2':7,'inv3':11},
            'aug7':{'inv1':4,'inv2':8,'inv3':10},
            'maj':{'inv1':4,'inv2':7},'min':{'inv1':3,'inv2':7},
            'aug':{'inv1':4,'inv2':8},'dim':{'inv1':3,'inv2':6},
            'sus4':{'inv1':5,'inv2':7},'sus2':{'inv1':2,'inv2':7},
        };
        const rootSemi  = semi[store.root];
        const intervals = invIntervals[store.quality];
        if (rootSemi !== undefined && intervals && intervals[inv] !== undefined) {
            name += '/' + semiToNote[(rootSemi + intervals[inv]) % 12];
        }
    } else if (store.bassNote) {
        name += '/' + store.bassNote;
    }
    return spellName(name);
}

/**
 * Re-spell a chord name's root + bass through the app's unified enharmonic core.
 * Key-aware: the song key (when known) drives flats vs sharps; otherwise the
 * chord's own root+quality lean decides. Mirrors HarmonicContext::reSpellChordName.
 */
function spellName(name) {
    if (!name || typeof window === 'undefined' || typeof window.sbnSpellChordName !== 'function') {
        return name;
    }
    const key = _model?.value?.songKey || '';
    return window.sbnSpellChordName(name, key);
}

function isVoicingSelected(v) {
    const lookupKey = store.voicingKey || store.chordName;
    const cv        = _model?.value?.chordVoicings;
    if (!cv) return false;
    const cur = cv[lookupKey] || cv[store.chordName];
    return !!(cur && v.frets === cur.frets && parseInt(v.position) === parseInt(cur.position));
}

/**
 * Apply a selected voicing to the model.
 * Pattern A: wraps chordNames rename + chordVoicings write in wrapCommand.
 * Then calls _applyTabFrets to write frets into the tab notation model.
 */
function applyVoicing(v) {
    if (!_model?.value) return;
    const oldName   = store.chordName;
    // Use the result's own name for dim inversions / dom alias readings (they each
    // carry a distinct chord name in v.dim_name). Fall back to pickerDisplayName()
    // for regular voicings where the name tracks the active filter state.
    const newName   = v.dim_name ? spellName(v.dim_name) : pickerDisplayName();
    const assignKey = store.voicingKey || oldName;
    const keyMatch  = (store.voicingKey || '').match(/^.+@(\d+)\.(\d+)$/);

    // Collect measure indices to snapshot (for chordNames undo coverage)
    const affectedGis = [];
    if (keyMatch) {
        affectedGis.push(parseInt(keyMatch[1]));
    } else if (newName !== oldName) {
        // Global rename — snapshot every measure containing oldName
        let gi = 0;
        for (const sec of _model.value.sections) {
            for (const m of sec.measures) {
                if ((m.chordNames || []).includes(oldName)) affectedGis.push(gi);
                gi++;
            }
        }
    }

    // If opened from tab with no prior chord name, treat as a specific-slot assign
    // using tabSrc coordinates rather than a global rename.
    const tabSrcForAssign = (!keyMatch && store._tabSource && !oldName) ? store._tabSource : null;
    if (tabSrcForAssign) {
        affectedGis.push(tabSrcForAssign.globalMeasureIndex);
    }

    _undo.wrapCommand('Assign voicing', affectedGis, () => {
        if (!_model.value.chordVoicings) _model.value.chordVoicings = {};
        const cv = _model.value.chordVoicings;

        if (newName !== oldName) {
            if (keyMatch) {
                // Specific chord instance: rename slot + re-key voicing
                const gi = parseInt(keyMatch[1]);
                const ci = parseInt(keyMatch[2]);
                let g = 0;
                outer: for (const sec of _model.value.sections) {
                    for (const m of sec.measures) {
                        if (g === gi) {
                            if (m.chordNames && m.chordNames[ci] !== undefined)
                                m.chordNames[ci] = newName;
                            break outer;
                        }
                        g++;
                    }
                }
                const newKey = `${newName}@${keyMatch[1]}.${keyMatch[2]}`;
                if (cv[assignKey] !== undefined) delete cv[assignKey];
                cv[newKey] = { frets: v.frets, position: v.position, fingers: v.fingers ?? '000000' };
            } else if (tabSrcForAssign) {
                // Tab-originated assign with no prior chord name — write name into specific slot
                const gi = tabSrcForAssign.globalMeasureIndex;
                const ci = tabSrcForAssign.chordIndex;
                let g = 0;
                outer2: for (const sec of _model.value.sections) {
                    for (const m of sec.measures) {
                        if (g === gi) {
                            if (!m.chordNames) m.chordNames = [];
                            while (m.chordNames.length <= ci) m.chordNames.push('');
                            m.chordNames[ci] = newName;
                            break outer2;
                        }
                        g++;
                    }
                }
                const newKey = `${newName}@${gi}.${ci}`;
                cv[newKey] = { frets: v.frets, position: v.position, fingers: v.fingers ?? '000000' };
            } else {
                // Global rename: update all instances of oldName
                for (const sec of _model.value.sections) {
                    for (const m of sec.measures) {
                        if (m.chordNames) {
                            for (let ci = 0; ci < m.chordNames.length; ci++) {
                                if (m.chordNames[ci] === oldName) m.chordNames[ci] = newName;
                            }
                        }
                    }
                }
                if (cv[oldName] !== undefined) delete cv[oldName];
                cv[newName] = { frets: v.frets, position: v.position, fingers: v.fingers ?? '000000' };
            }
        } else if (tabSrcForAssign) {
            // Tab-originated, name unchanged (still empty or same) — just write the voicing key
            const gi = tabSrcForAssign.globalMeasureIndex;
            const ci = tabSrcForAssign.chordIndex;
            const newKey = newName ? `${newName}@${gi}.${ci}` : null;
            if (newKey) cv[newKey] = { frets: v.frets, position: v.position, fingers: v.fingers ?? '000000' };
        } else {
            // Name unchanged — straightforward assignment
            cv[assignKey] = { frets: v.frets, position: v.position, fingers: v.fingers ?? '000000' };
        }
    });

    // Keep picker open after selection
    // store.open = false;

    if (typeof window !== 'undefined') {
        window.dispatchEvent(new CustomEvent('sbn-tab-sections-sync'));
    }

    // Apply frets into the tab notation model (separate undo entry via onVoicingApplied)
    const tabSrc = store._tabSource;
    if (tabSrc) {
        _applyTabFrets?.({
            globalMeasureIndex: tabSrc.globalMeasureIndex,
            chordIndex:         tabSrc.chordIndex,
            chordName:          newName,
            frets:              v.frets,
            position:           v.position,
        });
        store._tabSource = null;
    } else if (keyMatch) {
        _applyTabFrets?.({
            globalMeasureIndex: parseInt(keyMatch[1]),
            chordIndex:         parseInt(keyMatch[2]),
            chordName:          newName,
            frets:              v.frets,
            position:           v.position,
            skipIfTabExists:    true,
        });
    }

    if (typeof window.sbnToast === 'function') {
        window.sbnToast(
            'Voicing assigned' + (newName !== oldName ? ' — chord renamed to ' + newName : ''),
            'success',
        );
    }
}

function removeVoicing() {
    const removeKey = store.voicingKey || store.chordName;
    if (_model?.value?.chordVoicings) delete _model.value.chordVoicings[removeKey];
    store.open = false;
    if (typeof window !== 'undefined') {
        window.dispatchEvent(new CustomEvent('sbn-tab-sections-sync'));
    }
    if (typeof window.sbnToast === 'function') window.sbnToast('Voicing removed', 'success');
}

/**
 * Remove a voicing from the overview / all voicings section.
 * Called when user right-clicks a voicing card and selects "Remove voicing".
 */
function removeVoicingByName(chordName) {
    if (!_model?.value?.chordVoicings) return false;
    const cv = _model.value.chordVoicings;

    // Find and remove the voicing for this chord name
    if (cv[chordName]) {
        _undo?.wrapCommand?.('Remove voicing', [], () => {
            delete cv[chordName];
        });

        if (typeof window !== 'undefined') {
            window.dispatchEvent(new CustomEvent('sbn-tab-sections-sync'));
        }
        if (typeof window.sbnToast === 'function') {
            window.sbnToast(`Removed ${chordName} voicing`, 'success');
        }
        return true;
    }
    return false;
}

function clearAllVoicings() {
    if (!_model?.value?.chordVoicings) return;
    _undo?.wrapCommand?.('Clear all voicings', [], () => {
        _model.value.chordVoicings = {};
    });
    if (typeof window !== 'undefined') {
        window.dispatchEvent(new CustomEvent('sbn-tab-sections-sync'));
    }
}

function cleanUnusedVoicings() {
    if (!_model?.value?.chordVoicings) return 0;

    const cv = _model.value.chordVoicings;
    const usedBaseNames = new Set();
    const usedPositionalKeys = new Set();

    let gi = 0;
    for (const sec of (_model.value.sections || [])) {
        for (const m of (sec.measures || [])) {
            const names = m.chordNames || [];
            for (let ci = 0; ci < names.length; ci++) {
                const name = names[ci];
                if (!name) continue;
                usedBaseNames.add(name);
                usedPositionalKeys.add(`${name}@${gi}.${ci}`);
            }
            gi++;
        }
    }

    const keysToDelete = [];
    for (const key of Object.keys(cv)) {
        if (key.includes('@')) {
            if (!usedPositionalKeys.has(key)) keysToDelete.push(key);
            continue;
        }
        if (!usedBaseNames.has(key)) keysToDelete.push(key);
    }

    if (!keysToDelete.length) {
        if (typeof window.sbnToast === 'function') window.sbnToast('No unused voicings found', 'success');
        return 0;
    }

    _undo?.wrapCommand?.('Clean unused voicings', [], () => {
        keysToDelete.forEach(k => { delete cv[k]; });
    });

    if (typeof window !== 'undefined') {
        window.dispatchEvent(new CustomEvent('sbn-tab-sections-sync'));
    }
    if (typeof window.sbnToast === 'function') {
        window.sbnToast(`Removed ${keysToDelete.length} unused voicing${keysToDelete.length !== 1 ? 's' : ''}`, 'success');
    }

    return keysToDelete.length;
}

function togglePickerFilter(type, value) {
    const af = store.activeFilters;
    if (type === 'voicing_category') {
        af.voicing_category = af.voicing_category === value ? 'all' : value;
    } else if (type === 'root_string') {
        af.root_string = af.root_string === value ? 'all' : value;
    }
    _fetchVoicings();
}

function stepExtension(dir) {
    const groups = ['9', '11', '13'];
    const cycles = store.extensionCycles;

    if (!store.extensionGroup) {
        store.extensionGroup = groups[0];
        store.extensionIdx   = dir > 0 ? 0 : cycles[groups[0]].length - 1;
    } else {
        const cycle  = cycles[store.extensionGroup];
        let newIdx   = store.extensionIdx + dir;
        const gi     = groups.indexOf(store.extensionGroup);
        if (newIdx < -1) {
            if (gi > 0) {
                store.extensionGroup = groups[gi - 1];
                store.extensionIdx   = cycles[store.extensionGroup].length - 1;
            } else {
                store.extensionGroup = ''; store.extensionIdx = -1;
                store.activeFilters.extension = '';
                _fetchVoicings(); return;
            }
        } else if (newIdx >= cycle.length) {
            if (gi < groups.length - 1) {
                store.extensionGroup = groups[gi + 1];
                store.extensionIdx   = 0;
            } else {
                store.extensionGroup = ''; store.extensionIdx = -1;
                store.activeFilters.extension = '';
                _fetchVoicings(); return;
            }
        } else if (newIdx === -1) {
            store.extensionGroup = ''; store.extensionIdx = -1;
            store.activeFilters.extension = '';
            _fetchVoicings(); return;
        } else {
            store.extensionIdx = newIdx;
        }
    }
    store.activeFilters.extension = cycles[store.extensionGroup][store.extensionIdx];
    _fetchVoicings();
}

function clearExtension() {
    store.extensionGroup = ''; store.extensionIdx = -1;
    store.activeFilters.extension = '';
    _fetchVoicings();
}

function stepInversion(dir) {
    const available = ['all', ...store.filters.inversions];
    let idx = available.indexOf(store.activeFilters.inversion);
    if (idx === -1) idx = 0;
    store.activeFilters.inversion = available[(idx + dir + available.length) % available.length];
    _fetchVoicings();
}

function getInversionLabel() {
    const inv = store.activeFilters.inversion;
    if (!inv || inv === 'all') return 'All';
    const labels = { root: 'Root', inv1: '1st', inv2: '2nd', inv3: '3rd', rootless: 'Rootless' };
    return labels[inv] || inv;
}

function hasActiveFilters() {
    const af = store.activeFilters;
    return af.voicing_category !== 'all' || af.root_string !== 'all' ||
           af.extension !== (store.extension || '') ||
           af.inversion !== (store.inversion ? store.inversion : 'all');
}

function resetPickerFilters() {
    store.activeFilters = {
        voicing_category: 'all',
        root_string:      'all',
        extension:        store.extension || '',
        inversion:        store.inversion ? store.inversion : 'all',
    };
    if (store.extension) {
        for (const [grp, cycle] of Object.entries(store.extensionCycles)) {
            const idx = cycle.indexOf(store.extension);
            if (idx !== -1) { store.extensionGroup = grp; store.extensionIdx = idx; break; }
        }
    } else {
        store.extensionGroup = ''; store.extensionIdx = -1;
    }
    _fetchVoicings();
}

function close() {
    store.open        = false;
    store._tabSource  = null;
    store._tabMatchIndex = -1;
}

/**
 * Directly apply a voicing from external sources (e.g. Research Hints).
 */
function applyVoicingWithFrets(chord, frets, position) {
    if (store.open && (store.chordName === chord || store.voicingKey)) {
        // Apply to the active picker target
        applyVoicing({ frets, position });
    } else {
        // Apply globally to chordVoicings
        if (!_model?.value) return;
        _undo.wrapCommand('Apply voicing hint', [], () => {
            if (!_model.value.chordVoicings) _model.value.chordVoicings = {};
            _model.value.chordVoicings[chord] = { frets, position: position || 1, fingers: '000000' };
        });
        if (typeof window !== 'undefined') {
            window.dispatchEvent(new CustomEvent('sbn-tab-sections-sync'));
        }
        if (typeof window.sbnToast === 'function') {
            window.sbnToast(`Voicing for ${chord} updated from research`, 'success');
        }
    }
}

// Attach all methods directly to the reactive store object so consumers can
// do picker.openForChord() without a separate method lookup.
Object.assign(store, {
    openForChord,
    openForTab,
    applyVoicing,
    applyVoicingWithFrets,
    removeVoicing,
    removeVoicingByName,
    cleanUnusedVoicings,
    clearAllVoicings,
    togglePickerFilter,
    stepExtension,
    clearExtension,
    stepInversion,
    getInversionLabel,
    pickerDisplayName,
    isVoicingSelected,
    hasActiveFilters,
    resetPickerFilters,
    close,
});

// ── Export ────────────────────────────────────────────────────────────────────

/**
 * @param {import('vue').Ref}  model   — model ref from useTabModel (pass on first call)
 * @param {object}             undo    — { wrapCommand } from useUndo
 * @param {object}             options — { applyTabFrets } — callback for tab fret writes
 */
export function useVoicingPickerStore(model, undo, options = {}) {
    if (model !== undefined) {
        _model         = model;
        _undo          = undo;
        _applyTabFrets = options.applyTabFrets ?? null;
    }
    return store;
}
