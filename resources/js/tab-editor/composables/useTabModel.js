/**
 * SBN Tab Editor — Working Model
 *
 * Converts the flat `parsed.melody` array (from MusicXML parser)
 * into a structured TabModel suitable for editing.
 *
 * Key difference from Alpine's _tabBuild():
 *   Notes at the same tick+voice are grouped into one TabEvent
 *   from the start, not flattened then re-grouped.
 *
 * The model is reactive — Vue components re-render when it changes.
 */

import { ref, computed, watch } from 'vue';
import {
    TICKS, LAYOUT,
    durationToTicks, flagCount, baseDuration,
    generateId, ticksPerMeasure as calcTicksPerMeasure,
} from '../utils/constants.js';

function cloneChordVoicings(src) {
    return JSON.parse(JSON.stringify(src && typeof src === 'object' ? src : {}));
}

export function useTabModel(melody, sections, timeSignature, repeatMarkers, voltaEndings, chordVoicings, lineBreaks) {

    // ── Core state ─────────────────────────────────────────

    const model = ref(null);             // TabModel object — deep reactive
    /** Consumed by patchStructure() when Alpine dispatches a pre-mutation hint (_fromAlpine). */
    const pendingStructureHint = ref(null);
    const hasData = computed(() => {
        if (!melody.value || !melody.value.length) return false;
        return melody.value.some(n =>
            n.string !== null && n.string !== undefined &&
            n.fret !== null && n.fret !== undefined
        );
    });

    // ── Build model from melody ────────────────────────────

    function buildModel() {
        const mel = melody.value;
        // Allow chord-only build path (Phase 9 viewer): if there's no melody but
        // sections define a measure structure, fall through with an empty melody
        // — the section-extension loop and chord-extraction passes below will
        // populate measures from sections.value.
        const hasSections = (sections.value || []).some(s => (s.measures || []).length > 0);
        if ((!mel || !mel.length) && !hasSections) {
            model.value = null;
            return;
        }

        const timeSig  = timeSignature.value || '4/4';
        const tpm      = calcTicksPerMeasure(timeSig);

        // ── Pass 1: Build event objects, grouped by tick+voice ──

        const eventsByKey = new Map();   // key: "tick-voice" → TabEvent
        const allNotes    = [];          // flat list for beam/tie passes

        mel.forEach((note, idx) => {
            const measureIdx    = Math.floor(note.tick / tpm);
            const tickInMeasure = note.tick % tpm;
            const ticks         = note.ticks || durationToTicks(note.duration);
            const voice         = note.voice || 1;
            const key           = `${note.tick}-${voice}`;

            if (note.isRest) {
                // Rests are always standalone events
                const restEvent = {
                    id:            generateId(),
                    tick:          note.tick,
                    tickInMeasure,
                    measureIdx,
                    duration:      note.duration || 'q',
                    ticks,
                    voice,
                    isRest:        true,
                    notes:         [],
                    tieStart:      false,
                    tieStop:       false,
                    stemDir:       null,
                    flagCount:     0,
                    beam1:         null,
                    beam2:         null,
                    beamStart:     false,
                    beamEnd:       false,
                    beamContinue:  false,
                    beamWith:      null,
                    tupletActual:  note.tupletActual  || null,
                    tupletNormal:  note.tupletNormal  || null,
                    tupletType:    note.tupletType    || null,
                    tupletBracket: note.tupletBracket || false,
                    noBeamBar:     false,
                    xPos:          tickInMeasure / tpm,
                    originalIdx:   idx,
                };
                allNotes.push(restEvent);
                return;
            }

            // Pitched note — group into event
            let event = eventsByKey.get(key);
            if (!event) {
                event = {
                    id:            generateId(),
                    tick:          note.tick,
                    tickInMeasure,
                    measureIdx,
                    duration:      note.duration || 'q',
                    ticks,
                    voice,
                    isRest:        false,
                    notes:         [],
                    tieStart:      note.tieStart || false,
                    tieStop:       note.tieStop  || false,
                    stemDir:       null,
                    flagCount:     flagCount(ticks),
                    beam1:         note.beam1 || null,
                    beam2:         note.beam2 || null,
                    beamStart:     false,
                    beamEnd:       false,
                    beamContinue:  false,
                    beamWith:      null,
                    tupletActual:  note.tupletActual || null,
                    tupletNormal:  note.tupletNormal || null,
                    tupletType:    note.tupletType   || null,
                    tupletBracket: note.tupletBracket || false,
                    noBeamBar:     false,
                    xPos:          tickInMeasure / tpm,
                    originalIdx:   idx,
                };
                eventsByKey.set(key, event);
                allNotes.push(event);
            }

            // Only add chord notes that aren't the first — the first created the event
            // Transfer beam/tuplet data from chord notes to the lead event
            if (note.beam1 && !event.beam1) event.beam1 = note.beam1;
            if (note.beam2 && !event.beam2) event.beam2 = note.beam2;
            if (note.tupletActual && !event.tupletActual) {
                event.tupletActual  = note.tupletActual;
                event.tupletNormal  = note.tupletNormal;
                event.tupletType    = note.tupletType;
            }
            // Always propagate bracket flag — the <notations> block can appear
            // on any chord note, not necessarily the first one processed.
            if (note.tupletBracket) event.tupletBracket = true;
            if (note.tieStart) event.tieStart = true;
            if (note.tieStop)  event.tieStop  = true;

            // Merge max flag count
            const fc = flagCount(ticks);
            if (fc > event.flagCount) event.flagCount = fc;

            event.notes.push({
                string:   note.string,
                fret:     note.fret,
                pitch:    note.pitch || null,
                octave:   note.octave || null,
                tieStart: note.tieStart || false,
                tieStop:  note.tieStop  || false,
            });
        });

        // Sort notes within each event: high string (1) at top → low string (6) at bottom
        for (const event of eventsByKey.values()) {
            event.notes.sort((a, b) => a.string - b.string);
        }

        // ── Pass 2: Compute beams ──────────────────────────────

        computeBeams(allNotes);

        // ── Pass 3: Compute ties ───────────────────────────────

        computeTies(allNotes);

        // ── Pass 4: Stem direction (single voice = always down) ──

        const voices = new Set(allNotes.map(n => n.voice));
        const multiVoice = voices.size > 1;
        allNotes.forEach(n => {
            if (n.isRest) return;
            n.stemDir = multiVoice ? (n.voice === 1 ? 'up' : 'down') : 'down';
        });

        // ── Pass 5: Group into measures ────────────────────────

        const measureMap = {};
        allNotes.forEach(ev => {
            if (!measureMap[ev.measureIdx]) measureMap[ev.measureIdx] = [];
            measureMap[ev.measureIdx].push(ev);
        });

        // Build measures array
        const maxMeasure = Math.max(...Object.keys(measureMap).map(Number), 0);
        const measures = [];
        for (let i = 0; i <= maxMeasure; i++) {
            const events = measureMap[i] || [];
            events.sort((a, b) => a.tick - b.tick || a.voice - b.voice);
            measures.push({
                index:       i,
                events,
                repeatStart: repeatMarkers.value?.[i]?.start || false,
                repeatEnd:   repeatMarkers.value?.[i]?.end   || false,
                volta:       null,
                voltaStart:  false,
                voltaEnd:    false,
                chordNames:  [],
            });
        }

        // Extend measures to cover any bars the chord grid has added beyond
        // the last note-containing measure (e.g. a newly added empty bar).
        const totalFromSections = (sections.value || []).reduce(
            (sum, sec) => sum + (sec.measures || []).length, 0
        );
        for (let i = measures.length; i < totalFromSections; i++) {
            measures.push({
                index:       i,
                events:      [],
                repeatStart: repeatMarkers.value?.[i]?.start || false,
                repeatEnd:   repeatMarkers.value?.[i]?.end   || false,
                volta:       null,
                voltaStart:  false,
                voltaEnd:    false,
                chordNames:  [],
            });
        }

        // ── Populate volta endings ─────────────────────────────

        const ve = voltaEndings.value;
        if (ve && Object.keys(ve).length) {
            let activeVolta = null;
            for (let mi = 0; mi < measures.length; mi++) {
                const entry = ve[mi.toString()];
                if (entry?.type === 'start') {
                    activeVolta = { number: entry.number, text: entry.text || `${entry.number}.` };
                    measures[mi].voltaStart = true;
                }
                if (activeVolta) {
                    measures[mi].volta = { ...activeVolta };
                }
                if (entry?.type === 'stop') {
                    measures[mi].voltaEnd = true;
                    activeVolta = null;
                }
            }
            // If a volta was never explicitly closed (common for final volta),
            // mark the last measure that carried it as voltaEnd.
            if (activeVolta !== null) {
                for (let mi = measures.length - 1; mi >= 0; mi--) {
                    if (measures[mi].volta) {
                        measures[mi].voltaEnd = true;
                        break;
                    }
                }
            }
            }

        // ── Extract chord names, offsets, and durations from section data ──────

        const secs = sections.value || [];
        let chordOffset = 0;
        secs.forEach(sec => {
            const secMeasures = sec.measures || [];
            secMeasures.forEach((sm, li) => {
                const globalIdx = chordOffset + li;
                if (globalIdx >= measures.length) return;

                // Extract chord names — handles various formats from Alpine
                const chords = sm.chords || sm.chord_names || [];
                if (Array.isArray(chords) && chords.length) {
                    measures[globalIdx].chordNames = chords.map(c =>
                        typeof c === 'string' ? c : (c.chordName || c.name || c.chord || '')
                    ).filter(Boolean);

                    // Extract per-chord beat offsets and durations from parser data.
                    // chord.beatInMeasure: float quarter-beats from measure start (parser-derived).
                    // chord.beats: float duration in quarter beats (parser-derived).
                    // If missing (shortcode path, manual edits) fall back to even distribution.
                    const bpm = tpm / 480;
                    const hasOffsets = chords.some(c => typeof c === 'object' && c.beatInMeasure != null);

                    if (hasOffsets) {
                        measures[globalIdx].chordOffsets = chords.map(c =>
                            typeof c === 'object' && c.beatInMeasure != null ? c.beatInMeasure : 0
                        );
                        measures[globalIdx].chordBeats = chords.map(c =>
                            typeof c === 'object' && c.beats != null ? c.beats : bpm / chords.length
                        );
                    } else {
                        // Even distribution fallback
                        const slotBeats = bpm / chords.length;
                        measures[globalIdx].chordOffsets = chords.map((_, i) => i * slotBeats);
                        measures[globalIdx].chordBeats   = chords.map(() => slotBeats);
                    }
                } else if (typeof chords === 'string' && chords) {
                    measures[globalIdx].chordNames   = [chords];
                    const bpm = tpm / 480;
                    measures[globalIdx].chordOffsets = [0];
                    measures[globalIdx].chordBeats   = [bpm];
                } else {
                    measures[globalIdx].chordNames   = [];
                    measures[globalIdx].chordOffsets = [];
                    measures[globalIdx].chordBeats   = [];
                }
            });
            chordOffset += secMeasures.length;
        });

        // ── Build section slices ───────────────────────────────
        // (secs already declared above for chord name extraction)
        const sectionModels = [];
        let globalOffset = 0;

        if (secs.length) {
            secs.forEach((sec, idx) => {
                const count = (sec.measures || []).length;
                // Ingest lineBreaks from the bridge ref if not present on the section object
                const lb = sec.lineBreaks || lineBreaks?.value?.[sec.id] || lineBreaks?.value?.[idx];
                sectionModels.push({
                    id:         sec.id || '',
                    name:       sec.name || '',
                    lineBreaks: lb?.length ? [...lb] : null,
                    measures:   measures.slice(globalOffset, globalOffset + count),
                });
                globalOffset += count;
            });
        } else {
            // Fallback: single implicit section
            sectionModels.push({
                id:         '',
                name:       '',
                lineBreaks: null,
                measures,
            });
        }

        model.value = {
            timeSignature: timeSig,
            ticksPerMeasure: tpm,
            sections: sectionModels,
            chordVoicings: cloneChordVoicings(chordVoicings?.value),
        };
    }

    // ── Beam computation ───────────────────────────────────

    function computeBeams(events) {
        // Only process non-rest events with flagCount > 0
        const beamable = events.filter(e => !e.isRest && e.flagCount > 0);
        if (!beamable.length) return;

        // Check if we have MusicXML beam data
        const hasXmlBeams = beamable.some(e => e.beam1);

        if (hasXmlBeams) {
            beamsFromXml(beamable);
        } else {
            beamsHeuristic(beamable);
        }

        // Link unbeamed tuplet groups (e.g. quarter triplets).
        // Quarter triplets have tupletActual=3 but no <beam> elements in MusicXML,
        // so beamsFromXml silently skips them. We link them here into synthetic
        // beamWith groups so renderBeams can find them and draw the bracket/"3".
        // All events are passed (not just beamable) because quarter triplets have
        // baseDuration(320)=240 → flagCount=1 → they ARE in beamable. But we
        // also pass the full list so rests/longer notes don't accidentally block grouping.
        linkUnbeamedTuplets(events);
    }

    function linkUnbeamedTuplets(events) {
        // Link tuplet groups not captured by the beam pass (no beamWith set).
        //
        // MusicXML puts <tuplet type="start"> on the first note and
        // <tuplet type="stop"> on the last. Middle notes carry no <tuplet> element,
        // and may also have tupletActual=null (parser omission).
        //
        // Groups can contain notes of mixed duration (e.g. half+quarter in a 3:2
        // quarter-triplet bracket). The only reliable delimiters are start/stop tags.
        //
        // Strategy:
        //   · Include rests — a rest can carry tuplet type="start".
        //   · Walk sorted by tick within each measure+voice.
        //   · A 'start' event with tupletActual=3 opens a group.
        //   · Accumulate ALL subsequent events (any duration) until tupletType='stop'.
        //   · On close, propagate tupletActual + tupletBracket to all members.
        //   · If no 'stop' is seen but a new 'start' arrives, close the current group.

        const groups = {};
        events.forEach(e => {
            if (e.beamWith) return;  // already linked by beam pass
            const key = `${e.measureIdx}-${e.voice}`;
            if (!groups[key]) groups[key] = [];
            groups[key].push(e);
        });

        for (const group of Object.values(groups)) {
            group.sort((a, b) => a.tick - b.tick);
            let tupGroup = [];

            const closeGroup = () => {
                if (tupGroup.length >= 2) {
                    // Get tupletActual/Normal from the first member that has them —
                    // the start event may be a rest with no time-modification from the parser.
                    const anchor = tupGroup.find(m => m.tupletActual) || tupGroup[0];
                    const ta = anchor.tupletActual || 3;
                    const tn = anchor.tupletNormal || 2;
                    tupGroup.forEach(m => {
                        m.beamWith      = tupGroup;
                        m.noBeamBar     = true;
                        m.tupletBracket = true;  // unbeamed groups always need bracket
                        if (!m.tupletActual) m.tupletActual = ta;
                        if (!m.tupletNormal) m.tupletNormal = tn;
                    });
                }
                tupGroup = [];
            };

            group.forEach(e => {
                if (e.tupletType === 'start') {
                    if (tupGroup.length) closeGroup();
                    tupGroup = [e];
                } else if (tupGroup.length) {
                    tupGroup.push(e);
                    if (e.tupletType === 'stop') closeGroup();
                }
            });

            closeGroup(); // flush any unclosed group
        }
    }

    function beamsFromXml(events) {
        // Group by measure+voice, then apply beam1 tags
        const groups = {};
        events.forEach(e => {
            const key = `${e.measureIdx}-${e.voice}`;
            if (!groups[key]) groups[key] = [];
            groups[key].push(e);
        });

        for (const group of Object.values(groups)) {
            group.sort((a, b) => a.tick - b.tick);
            let beamGroup = [];

            const abandonOpenGroup = () => {
                // A new 'begin' (or loop end) before a matching 'end' means the
                // previous begin was an orphan — clear its provisional flag so
                // the note renders as a stand-alone flagged note, not mid-beam.
                if (beamGroup.length === 1) beamGroup[0].beamStart = false;
                beamGroup = [];
            };

            group.forEach(e => {
                if (e.beam1 === 'begin') {
                    if (beamGroup.length) abandonOpenGroup();
                    beamGroup = [e];
                    e.beamStart = true;
                } else if (e.beam1 === 'continue' && beamGroup.length) {
                    beamGroup.push(e);
                    e.beamContinue = true;
                } else if (e.beam1 === 'end' && beamGroup.length) {
                    beamGroup.push(e);
                    e.beamEnd = true;
                    // Link all members
                    beamGroup.forEach(m => { m.beamWith = beamGroup; });
                    beamGroup = [];
                }
            });
            abandonOpenGroup();
        }
    }

    function beamsHeuristic(events) {
        // Group by measure+voice+beat
        const timeSig = timeSignature.value || '4/4';
        const [, beatTypeStr] = timeSig.split('/');
        const beatType = parseInt(beatTypeStr) || 4;
        const ticksPerBeat = TICKS.perBeat * (4 / beatType);

        const groups = {};
        events.forEach(e => {
            const beat = Math.floor(e.tickInMeasure / ticksPerBeat);
            const key = `${e.measureIdx}-${e.voice}-${beat}`;
            if (!groups[key]) groups[key] = [];
            groups[key].push(e);
        });

        for (const group of Object.values(groups)) {
            if (group.length < 2) continue;
            group.sort((a, b) => a.tick - b.tick);
            group[0].beamStart = true;
            group[group.length - 1].beamEnd = true;
            for (let i = 1; i < group.length - 1; i++) {
                group[i].beamContinue = true;
            }
            group.forEach(m => { m.beamWith = group; });
        }
    }

    // ── Tie computation ────────────────────────────────────

    function computeTies(events) {
        // Link tie pairs per-note: for each note with tieStart, find the next
        // event in the same voice that has a note on the same string with tieStop.
        const byVoice = {};
        events.forEach(e => {
            if (e.isRest) return;
            const v = e.voice || 1;
            if (!byVoice[v]) byVoice[v] = [];
            byVoice[v].push(e);
        });

        for (const voiceEvents of Object.values(byVoice)) {
            voiceEvents.sort((a, b) => a.tick - b.tick);
            for (let i = 0; i < voiceEvents.length; i++) {
                const ev = voiceEvents[i];
                ev.notes.forEach(note => {
                    if (!note.tieStart) return;
                    // Find the next event with a note on the same string that has tieStop
                    for (let j = i + 1; j < voiceEvents.length; j++) {
                        const targetNote = voiceEvents[j].notes.find(
                            n => n.string === note.string && n.tieStop
                        );
                        if (targetNote) {
                            note.tieEndEvent = voiceEvents[j];
                            note.tieEndNote = targetNote;
                            targetNote.tieStartEvent = ev;
                            targetNote.tieStartNote = note;
                            break;
                        }
                    }
                });
            }
        }

        // Derive event-level flags (true if ANY note has a tie)
        events.forEach(ev => {
            if (ev.isRest) return;
            ev.tieStart = ev.notes.some(n => n.tieStart);
            ev.tieStop  = ev.notes.some(n => n.tieStop);
        });
    }

    // ── Patch chord names in-place (no event rebuild) ───────
    // Used when the chord grid updates but tab edits must be preserved.

    function patchChordNames() {
        if (!model.value) return;
        const secs = sections.value || [];
        // Build a flat list of Alpine section measures in global order
        const alpineMeasures = [];
        secs.forEach(sec => (sec.measures || []).forEach(m => alpineMeasures.push(m)));

        // Walk model sections and patch chordNames by global index
        model.value.sections.forEach(modelSec => {
            modelSec.measures.forEach(m => {
                const sm = alpineMeasures[m.index];
                if (!sm) return;
                const chords = sm.chords || sm.chord_names || [];
                if (Array.isArray(chords)) {
                    m.chordNames = chords.map(c =>
                        typeof c === 'string' ? c : (c.chordName || c.name || c.chord || '')
                    ).filter(Boolean);
                } else if (typeof chords === 'string' && chords) {
                    m.chordNames = [chords];
                } else {
                    m.chordNames = [];
                }
            });
        });
    }

    function patchChordVoicings() {
        if (!model.value) return;
        model.value.chordVoicings = cloneChordVoicings(chordVoicings?.value);
    }

    /**
     * Shift Name@global.ci keys (same rules as Alpine _reindexVoicings).
     */
    function _reindexChordVoicingKeys(cv, atGlobalIdx, delta) {
        const re = /^(.+)@(\d+)\.(\d+)$/;
        const toRename = [];
        for (const key of Object.keys(cv)) {
            const m = re.exec(key);
            if (!m) continue;
            const gi = parseInt(m[2], 10);
            if (gi >= atGlobalIdx) toRename.push({ key, name: m[1], gi, ci: m[3] });
        }
        toRename.sort((a, b) => (delta > 0 ? b.gi - a.gi : a.gi - b.gi));
        for (const { key, name, gi, ci } of toRename) {
            const newKey = name + '@' + (gi + delta) + '.' + ci;
            if (newKey !== key) {
                cv[newKey] = cv[key];
                delete cv[key];
            }
        }
    }

    /**
     * Apply structural voicing key ops to the live model (before syncing to Alpine).
     */
    function applyChordVoicingOps(detail = {}) {
        if (!model.value) return;
        const cv = model.value.chordVoicings = model.value.chordVoicings || {};

        if (detail.voicingDeletes?.length) {
            const sorted = [...detail.voicingDeletes].sort((a, b) => b.gi - a.gi);
            for (const { gi, names } of sorted) {
                (names || []).forEach((name, ci) => {
                    if (name) delete cv[name + '@' + gi + '.' + ci];
                });
                _reindexChordVoicingKeys(cv, gi + 1, -1);
            }
        }
        if (detail.voicingReindexAt != null && detail.voicingDelta) {
            _reindexChordVoicingKeys(cv, detail.voicingReindexAt, detail.voicingDelta);
        }
    }

    // ── Structural sync ─────────────────────────────────────
    //
    // Called by the sections watcher instead of patchChordNames().
    // Detects whether the measure count has changed; if so, rebuilds
    // the section/measure layout without destroying tab event data.

    function patchStructure() {
        if (!model.value) return;

        // ── Bug 2 fix: consume snapshot-restore guard deterministically ─────
        // deserializeModel() sets _restoringSnapshot=true and leaves it set.
        // We clear it here so timing never depends on Promise/microtask ordering.
        if (_restoringSnapshot) {
            _restoringSnapshot = false;
            patchChordNames();
            patchChordVoicings();
            return;
        }

        // ── Consume the structural hint if one is pending ──────────────
        // When the tab editor triggered the change, the bridge recorded exactly
        // what operation is coming. We use that to do a surgical splice on the
        // tab model before the positional rebuild, so measure data stays correct.
        const hint = pendingStructureHint?.value ?? null;
        if (hint) {
            pendingStructureHint.value = null;  // consume immediately
            _applySurgicalHint(hint);
        }

        const secs = sections.value || [];
        const alpineCount = secs.reduce((n, sec) => n + (sec.measures || []).length, 0);
        const tabCount    = model.value.sections.reduce((n, s) => n + s.measures.length, 0);

        if (alpineCount === tabCount) {
            // Same measure count — but check section boundaries too.
            // deleteSection (merge) and splitSection change which section owns
            // which measures without changing total count. If boundaries differ,
            // we must reassign measures to sections before patching chord names.
            const tabBoundaries    = model.value.sections.map(s => s.measures.length);
            const alpineBoundaries = secs.map(s => (s.measures || []).length);
            const boundariesMatch  =
                tabBoundaries.length === alpineBoundaries.length &&
                tabBoundaries.every((c, i) => c === alpineBoundaries[i]);

            if (!boundariesMatch) {
                // Rebuild section layout from flat measure list, preserving all tab data.
                const flat = model.value.sections.flatMap(s => s.measures);
                _rebuildSectionsFromFlat(flat);
            }
            patchChordNames();
            patchChordVoicings();
            return;
        }

        // Counts still differ after surgical apply (e.g. chord-grid-initiated
        // change with no hint) — fall back to positional rebuild.
        //
        // KNOWN LIMITATION: positional rebuild is only correct for
        // append/delete-at-end. Mid-structure edits shift tab data by one
        // position. Proper fix: always send a hint (v2, deferred).
        const tpm = model.value.ticksPerMeasure;
        _rebuildSections(secs, tpm);
        patchChordNames();
        patchChordVoicings();
    }

    /**
     * Apply a surgical insert or delete to the tab model using the hint
     * from pendingStructureHint, before the positional count-check runs.
     *
     * After this runs, alpineCount === tabCount so patchStructure() takes
     * the fast path (patchChordNames only, no positional rebuild).
     */
    function _applySurgicalHint(hint) {
        const tpm    = model.value.ticksPerMeasure;
        const flat   = model.value.sections.flatMap(s => s.measures);
        const { action, measureIndex } = hint;

        if (action === 'insertBarAfter' || action === 'insertBarBefore') {
            const insertAt = action === 'insertBarAfter' ? measureIndex + 1 : measureIndex;
            // Splice an empty measure into the flat list at insertAt
            flat.splice(insertAt, 0, _makeEmptyMeasure(insertAt));
            // Re-index everything from insertAt onwards
            for (let i = insertAt; i < flat.length; i++) {
                flat[i].index = i;
                flat[i].events.forEach(ev => {
                    ev.measureIdx = i;
                    ev.tick = i * tpm + ev.tickInMeasure;
                });
            }
            // Rebuild sections from the updated flat list
            _rebuildSectionsFromFlat(flat);

        } else if (action === 'insertBarsBefore') {
            const insertAt = measureIndex;
            const count = Math.max(0, Number(hint.count) || 0);
            for (let i = 0; i < count; i++) {
                flat.splice(insertAt + i, 0, _makeEmptyMeasure(insertAt + i));
            }
            for (let i = insertAt; i < flat.length; i++) {
                flat[i].index = i;
                flat[i].events.forEach(ev => {
                    ev.measureIdx = i;
                    ev.tick = i * tpm + ev.tickInMeasure;
                });
            }
            _rebuildSectionsFromFlat(flat);

        } else if (action === 'deleteBar') {
            flat.splice(measureIndex, 1);
            for (let i = measureIndex; i < flat.length; i++) {
                flat[i].index = i;
                flat[i].events.forEach(ev => {
                    ev.measureIdx = i;
                    ev.tick = i * tpm + ev.tickInMeasure;
                });
            }
            _rebuildSectionsFromFlat(flat);

        } else if (action === 'deleteSelection') {
            const indices = (hint.selectedIndices || [measureIndex])
                .slice()
                .sort((a, b) => b - a);  // descending so splices don't shift earlier indices
            indices.forEach(i => flat.splice(i, 1));
            // Re-index all remaining measures
            flat.forEach((m, i) => {
                m.index = i;
                m.events.forEach(ev => {
                    ev.measureIdx = i;
                    ev.tick = i * tpm + ev.tickInMeasure;
                });
            });
            _rebuildSectionsFromFlat(flat);

        } else if (action === 'moveMeasure') {
            const fromIdx = hint.fromMeasureIndex;
            const toIdx = hint.toMeasureIndex;
            if (fromIdx !== toIdx && fromIdx >= 0 && toIdx >= 0 && fromIdx < flat.length && toIdx < flat.length) {
                const [moved] = flat.splice(fromIdx, 1);
                flat.splice(toIdx, 0, moved);
                // Re-index all measures
                flat.forEach((m, i) => {
                    m.index = i;
                    m.events.forEach(ev => {
                        ev.measureIdx = i;
                        ev.tick = i * tpm + ev.tickInMeasure;
                    });
                });
                _rebuildSectionsFromFlat(flat);
            }

        } else if (action === 'splitSection') {
            const sectionIndex = hint.sectionIndex;
            const measureIndex = hint.measureIndex; // global measure index where split occurs
            if (sectionIndex >= 0 && sectionIndex < model.value.sections.length) {
                const sec = model.value.sections[sectionIndex];
                const splitAt = measureIndex - sec.measures[0].index; // convert to local index
                if (splitAt > 0 && splitAt < sec.measures.length) {
                    // Create new section with measures after split point
                    const newSec = {
                        id: hint.newSectionId || '',
                        name: hint.newSectionName || '',
                        lineBreaks: null,
                        measures: sec.measures.splice(splitAt),
                    };
                    // Reset line breaks for both sections
                    _resetUniformLineBreaks(sec);
                    _resetUniformLineBreaks(newSec);
                    // Insert new section after current
                    model.value.sections.splice(sectionIndex + 1, 0, newSec);
                    // Re-index globally
                    _reindexGlobalMeasures();
                }
            }

        } else if (action === 'deleteSection') {
            const sectionIndex = hint.sectionIndex;
            if (sectionIndex >= 0 && sectionIndex < model.value.sections.length && model.value.sections.length > 1) {
                // Remove the section
                model.value.sections.splice(sectionIndex, 1);
                // Re-index globally
                _reindexGlobalMeasures();
            }
        }
    }

    /**
     * Rebuild model.value.sections using the current Alpine section boundaries
     * but sourcing measure objects from the already-correct flat list.
     * Used after surgical insert/delete has already fixed the flat array.
     */
    function _rebuildSectionsFromFlat(flat) {
        const secs = sections.value || [];
        const newSections = [];
        let gi = 0;
        secs.forEach(sec => {
            const sectionMeasures = [];
            (sec.measures || []).forEach(() => {
                if (gi < flat.length) sectionMeasures.push(flat[gi]);
                gi++;
            });
            newSections.push({
                id:         sec.id   || null,
                name:       sec.name || sec.id || null,
                lineBreaks: sec.lineBreaks?.length ? [...sec.lineBreaks] : null,
                measures:   sectionMeasures,
            });
        });
        model.value.sections = newSections;
    }

    /**
     * Rebuild model.value.sections to match Alpine's section/measure layout,
     * preserving existing tab measures by position.
     *
     * @param {Array}  secs - sections.value (Alpine's authoritative structure)
     * @param {number} tpm  - ticks per measure
     */
    function _rebuildSections(secs, tpm) {
        const oldMeasures = model.value.sections.flatMap(s => s.measures);
        const newSections = [];
        let globalIdx = 0;

        secs.forEach(sec => {
            const sectionMeasures = [];

            (sec.measures || []).forEach(() => {
                if (globalIdx < oldMeasures.length) {
                    // Reuse existing tab measure at this position; update its index.
                    const m = oldMeasures[globalIdx];
                    m.index = globalIdx;
                    m.events.forEach(ev => {
                        ev.measureIdx = globalIdx;
                        ev.tick = globalIdx * tpm + ev.tickInMeasure;
                    });
                    sectionMeasures.push(m);
                } else {
                    // New measure beyond the old count — create empty.
                    sectionMeasures.push(_makeEmptyMeasure(globalIdx));
                }
                globalIdx++;
            });

            // Measures beyond globalIdx (i.e. deleted bars) are simply omitted.

            newSections.push({
                id:         sec.id   || null,
                name:       sec.name || sec.id || null,
                lineBreaks: sec.lineBreaks?.length ? [...sec.lineBreaks] : null,
                measures:   sectionMeasures,
            });
        });

        model.value.sections = newSections;
    }

    /**
     * Create an empty tab measure (no events, no chord names).
     * Matches the shape produced by buildModel() for empty bars.
     */
    function _makeEmptyMeasure(index) {
        const tpm  = model.value?.ticksPerMeasure ?? 1920;
        const tick = index * tpm;
        const wholeRest = {
            id:            generateId(),
            tick,
            tickInMeasure: 0,
            measureIdx:    index,
            duration:      'w',
            ticks:         tpm,
            voice:         1,
            isRest:        true,
            notes:         [],
            tieStart:      false,
            tieStop:       false,
            stemDir:       null,
            flagCount:     0,
            beam1:         null,
            beam2:         null,
            beamStart:     false,
            beamEnd:       false,
            beamContinue:  false,
            beamWith:      null,
            tupletActual:  null,
            tupletNormal:  null,
            tupletType:    null,
            tupletBracket: false,
            noBeamBar:     false,
            xPos:          0,
            originalIdx:   null,
        };
        return {
            index,
            events:      [wholeRest],
            repeatStart: false,
            repeatEnd:   false,
            volta:       null,
            voltaStart:  false,
            voltaEnd:    false,
            chordNames:  [],
        };
    }

    // ── Snapshot restore guard ──────────────────────────────
    // When Alpine restores a structural undo/redo snapshot, it dispatches
    // sbn-tab-restore-snapshot instead of sbn-chords-changed. We restore the
    // model directly and must suppress the sections watcher from triggering
    // patchStructure() on the next tick (Alpine also restores parsed.sections,
    // which eventually flows through handleChordsChanged → sections.value update).
    let _restoringSnapshot = false;

    // ── Serialize / Deserialize ──────────────────────────────
    //
    // Used by the cross-domain structural undo: Alpine captures a tab model
    // snapshot before structural mutations (insert/delete/move bar) and
    // restores it atomically on undo/redo, bypassing patchStructure().
    //
    // The snapshot format is designed as a proto-Score-Model: it captures
    // enough state to fully reconstruct the tab model without re-parsing
    // melody data or re-running buildModel().

    /**
     * Serialize the current model into a plain JSON-safe object.
     * Strips circular references and non-serializable state.
     */
    function serializeModel() {
        if (!model.value) return null;

        // Deep clone and strip circular references
        const clone = JSON.parse(JSON.stringify(model.value, (key, value) => {
            // Strip known circular reference properties
            if (key === 'beamWith') return undefined;
            if (key === 'tieEndEvent') return undefined;
            if (key === 'tieEndNote') return undefined;
            if (key === 'tieStartEvent') return undefined;
            if (key === 'tieStartNote') return undefined;
            // Keep all other values
            return value;
        }));

        return clone;
    }

    /**
     * Restore a previously serialized snapshot directly onto model.value.
     * Re-links beamWith groups and tie pairs after restoring.
     */
    function deserializeModel(snapshot) {
        if (!snapshot) return;

        _restoringSnapshot = true;

        model.value = {
            timeSignature:   snapshot.timeSignature,
            ticksPerMeasure: snapshot.ticksPerMeasure,
            chordVoicings:   cloneChordVoicings(snapshot.chordVoicings),
            sections: snapshot.sections.map(sec => ({
                id:   sec.id,
                name: sec.name,
                lineBreaks: sec.lineBreaks?.length ? [...sec.lineBreaks] : null,
                measures: sec.measures.map(m => ({
                    ...m,
                    events: m.events.map(ev => ({
                        ...ev,
                        beamWith: null,
                        notes: ev.notes.map(n => ({ ...n })),
                    })),
                })),
            })),
        };

        // Re-link beamWith and ties within each measure
        const allEvents = [];
        model.value.sections.forEach(sec => {
            sec.measures.forEach(m => {
                _relinkBeamWith(m.events);
                allEvents.push(...m.events);
            });
        });

        // Re-link cross-measure ties
        computeTies(allEvents.filter(e => !e.isRest));

        // _restoringSnapshot stays true — patchStructure() will consume
        // and clear it deterministically when the sections watcher fires.
        // This avoids the microtask-ordering race of Promise.resolve().
    }

    /**
     * Re-link beamWith arrays within a set of events.
     * Same logic as useUndo.js relinkBeamWith but also handles
     * unbeamed tuplet groups via linkUnbeamedTuplets.
     */
    function _relinkBeamWith(events) {
        // First: re-link from XML beam data AND heuristic data
        const groups = {};
        events.forEach(ev => {
            if (ev.isRest || (!ev.beamStart && !ev.beamContinue && !ev.beamEnd)) return;
            const key = `${ev.measureIdx}-${ev.voice}`;
            if (!groups[key]) groups[key] = [];
            groups[key].push(ev);
        });
        for (const group of Object.values(groups)) {
            group.sort((a, b) => a.tick - b.tick);
            let beamGroup = [];
            group.forEach(ev => {
                if (ev.beamStart) { beamGroup = [ev]; }
                else if (ev.beamContinue && beamGroup.length) { beamGroup.push(ev); }
                else if (ev.beamEnd && beamGroup.length) {
                    beamGroup.push(ev);
                    beamGroup.forEach(m => { m.beamWith = beamGroup; });
                    beamGroup = [];
                }
            });
        }

        // Then: link unbeamed tuplet groups (quarter triplets etc.)
        linkUnbeamedTuplets(events);
    }

    // ── Structural mutations (Phase A: tab view mutates Vue model) ──
    //
    // Callers sync parsed.sections + voicing keys in Alpine via
    // sbn-tab-sections-sync (see TabEditor.vue). Chord-grid edits still flow
    // Alpine → Vue through patchStructure().

    function _resetUniformLineBreaks(sec, barsPerRow = 4) {
        if (!sec?.measures?.length) return;
        const total = sec.measures.length;
        sec.lineBreaks = [];
        for (let i = 0; i < total; i += barsPerRow) {
            sec.lineBreaks.push(Math.min(barsPerRow, total - i));
        }
    }

    function _smartDistributeLineBreaks(sec, targetBarsPerRow = 5) {
        if (!sec?.measures?.length) return;
        const total = sec.measures.length;
        
        // If we have existing line breaks, preserve the original layout
        if (sec.lineBreaks?.length) {
            // Calculate original bars per row from existing breaks
            const originalBarsPerRow = Math.round(total / sec.lineBreaks.length);
            
            // Try to maintain original distribution if reasonable
            const barsPerRow = (originalBarsPerRow > 0 && originalBarsPerRow <= 8) ? originalBarsPerRow : targetBarsPerRow;
            
            sec.lineBreaks = [];
            for (let i = 0; i < total; i += barsPerRow) {
                sec.lineBreaks.push(Math.min(barsPerRow, total - i));
            }
        } else {
            // No existing breaks - create even distribution
            _resetUniformLineBreaks(sec, targetBarsPerRow);
        }
    }

    /**
     * Force a specific layout density for a section.
     * This is called by the +/- buttons in the Vue editor.
     */
    function setBarsPerRow(si, count) {
        const sec = model.value?.sections[si];
        if (!sec) return;

        const barsPerRow = Math.max(1, Math.min(12, count));
        const total = sec.measures.length;
        if (total === 0) return;

        const newBreaks = [];
        for (let i = 0; i < total; i += barsPerRow) {
            newBreaks.push(Math.min(barsPerRow, total - i));
        }

        sec.lineBreaks = newBreaks;
    }

    /** Helper to detect current density before structural mutations */
    function _getCurrentBarsPerRow(sec) {
        if (!sec?.lineBreaks?.length) return 5;
        // Use the first row's count as the baseline for "bars per row"
        return sec.lineBreaks[0];
    }

    function insertMeasureAfter(si, mi) {
        const sec = model.value.sections[si];
        if (!sec) return;

        const insertAt = mi + 1;
        const newMeasure = _makeEmptyMeasure(sec.measures.length);

        sec.measures.splice(insertAt, 0, newMeasure);

        // Use smart distribution to maintain proper bar layout
        _smartDistributeLineBreaks(sec, _getCurrentBarsPerRow(sec));

        _reindexGlobalMeasures();
    }

    function insertMeasureBefore(si, mi) {
        const sec = model.value.sections[si];
        if (!sec) return;

        const insertAt = mi;
        const newMeasure = _makeEmptyMeasure(sec.measures.length);

        sec.measures.splice(insertAt, 0, newMeasure);

        // Use smart distribution to maintain proper bar layout
        _smartDistributeLineBreaks(sec, _getCurrentBarsPerRow(sec));

        _reindexGlobalMeasures();
    }

    function deleteMeasure(si, mi) {
        const sec = model.value.sections[si];
        if (!sec || sec.measures.length <= 1) return;

        sec.measures.splice(mi, 1);

        // Use smart distribution to maintain proper bar layout
        _smartDistributeLineBreaks(sec, _getCurrentBarsPerRow(sec));

        _reindexGlobalMeasures();
    }

    function addMeasureToSection(si) {
        if (!model.value) return;
        const sec = model.value.sections[si];
        if (!sec) return;
        insertMeasureAfter(si, sec.measures.length - 1);
    }

    function addSection() {
        if (!model.value) return;
        const currentIds = model.value.sections.map(s => s.id).filter(id => id && id.length === 1);
        let nextId = 'A';
        const letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        if (currentIds.length > 0) {
            const lastId = currentIds[currentIds.length - 1];
            const idx = letters.indexOf(lastId);
            if (idx >= 0 && idx < letters.length - 1) {
                nextId = letters[idx + 1];
            }
        }
        
        const newSec = {
            id: nextId,
            name: '',
            lineBreaks: [4],
            measures: [
                _makeEmptyMeasure(0)
            ]
        };
        model.value.sections.push(newSec);
        _reindexGlobalMeasures();
    }

    function deleteSection(si) {
        if (!model.value || model.value.sections.length <= 1) return;
        if (si >= 0 && si < model.value.sections.length) {
            model.value.sections.splice(si, 1);
            _reindexGlobalMeasures();
        }
    }

    function renameSection(si, newName) {
        if (!model.value?.sections?.[si]) return;
        model.value.sections[si].name = newName;
    }

    function splitSection(si, ri) {
        if (!model.value) return;
        const sec = model.value.sections[si];
        if (!sec || !sec.lineBreaks) return;
        
        let splitAtMi = 0;
        for (let i = 0; i <= ri; i++) {
            if (i < sec.lineBreaks.length) {
                splitAtMi += sec.lineBreaks[i];
            }
        }
        
        if (splitAtMi <= 0 || splitAtMi >= sec.measures.length) return;
        
        const currentIds = model.value.sections.map(s => s.id).filter(id => id && id.length === 1);
        let nextId = 'A';
        const letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        if (currentIds.length > 0) {
            const lastId = currentIds[currentIds.length - 1];
            const idx = letters.indexOf(lastId);
            if (idx >= 0 && idx < letters.length - 1) {
                nextId = letters[idx + 1];
            }
        }

        const newSec = {
            id: nextId,
            name: '',
            lineBreaks: null,
            measures: sec.measures.splice(splitAtMi),
        };
        _smartDistributeLineBreaks(sec, _getCurrentBarsPerRow(sec));
        _smartDistributeLineBreaks(newSec, _getCurrentBarsPerRow(sec));
        model.value.sections.splice(si + 1, 0, newSec);
        _reindexGlobalMeasures();
    }

    /**
     * Map global measure index → { si, mi } on the live model (after prior splices).
     */
    function _globalToLocal(gi) {
        let g = 0;
        for (let si = 0; si < model.value.sections.length; si++) {
            const len = model.value.sections[si].measures.length;
            if (gi < g + len) return { si, mi: gi - g };
            g += len;
        }
        return null;
    }

    /**
     * Delete several measures by global index (tab context menu / Delete key).
     * Same rules as the chord grid: never remove the last bar in a section;
     * splices in descending global order so local indices stay valid.
     */
    function deleteMeasuresByGlobalIndices(globalIndices) {
        const sorted = [...new Set(globalIndices)].sort((a, b) => b - a);
        const touched = new Set();

        for (const gi of sorted) {
            const coord = _globalToLocal(gi);
            if (!coord) continue;
            const sec = model.value.sections[coord.si];
            if (sec.measures.length <= 1) continue;
            touched.add(coord.si);
            sec.measures.splice(coord.mi, 1);
        }

        touched.forEach(si => {
            const sec = model.value.sections[si];
            if (sec?.lineBreaks?.length) _smartDistributeLineBreaks(sec, _getCurrentBarsPerRow(sec));
        });
        _reindexGlobalMeasures();
    }

    function moveMeasure(si, fromMi, toMi) {
        const sec = model.value.sections[si];
        if (!sec || fromMi === toMi) return;

        const [moved] = sec.measures.splice(fromMi, 1);
        sec.measures.splice(toMi, 0, moved);

        if (sec.lineBreaks) _smartDistributeLineBreaks(sec, _getCurrentBarsPerRow(sec));

        _reindexGlobalMeasures();
    }

    /**
     * Re-index all measures globally after structural changes.
     * Updates measure.index, event.measureIdx, and event.tick.
     */
    function _reindexGlobalMeasures() {
        const tpm = model.value.ticksPerMeasure;
        let globalIdx = 0;

        model.value.sections.forEach(sec => {
            sec.measures.forEach(m => {
                m.index = globalIdx;
                m.events.forEach(ev => {
                    ev.measureIdx = globalIdx;
                    ev.tick = globalIdx * tpm + ev.tickInMeasure;
                });
                globalIdx++;
            });
        });
    }

    /**
     * Build parsed.sections-shaped data for Alpine (chords + beats + lineBreaks).
     * Used after tab-initiated structural edits and for sbn-tab-sections-sync.
     */
    function exportAlpineSections() {
        if (!model.value) return [];
        const ts = model.value.timeSignature || '4/4';
        const tsBeats = parseInt(ts.split('/')[0], 10) || 4;

        return model.value.sections.map(sec => ({
            id:         sec.id || '',
            name:       sec.name || sec.id || '',
            rhythmSlug: null,
            tonality:   '',
            lineBreaks: sec.lineBreaks?.length ? [...sec.lineBreaks] : null,
            measures:   sec.measures.map(m => {
                const names = (m.chordNames || []).filter(n => n != null && n !== '');
                if (!names.length) {
                    return { chords: [{ name: '', beats: tsBeats }] };
                }
                const beatsEach = tsBeats / names.length;
                return {
                    chords: names.map((name, i) => ({
                        name,
                        beats:         m.chordBeats?.[i]   ?? beatsEach,
                        beatInMeasure: m.chordOffsets?.[i] ?? (i * beatsEach),
                    })),
                };
            }),
        }));
    }

    // ── Watch for data changes and rebuild ──────────────────

    // Full rebuild only when melody/structure changes.
    watch([melody, timeSignature, repeatMarkers, voltaEndings], () => {
        buildModel();
    }, { deep: false });

    /**
     * Phase A Reactive Watchers:
     * We stop watching bridge 'sections' to prevent reactivity loops.
     * Vue now owns the structure and layout.
     */

    // Watch for voicing changes (from Alpine picker)
    watch(chordVoicings, () => {
        if (model.value) {
            patchChordVoicings();
            patchStructure(); // check for hints
        }
    }, { deep: false });

    // Watch for structural hints arriving through the bridge
    watch(pendingStructureHint, (hint) => {
        if (hint && model.value) {
            patchStructure();
        }
    });

    return {
        model,
        hasData,
        buildModel,
        patchChordNames,
        patchStructure,
        serializeModel,
        deserializeModel,
        pendingStructureHint,
        insertMeasureAfter,
        insertMeasureBefore,
        deleteMeasure,
        deleteMeasuresByGlobalIndices,
        moveMeasure,
        addMeasureToSection,
        addSection,
        deleteSection,
        renameSection,
        splitSection,
        setBarsPerRow,
        exportAlpineSections,
        cloneChordVoicings,
        patchChordVoicings,
        applyChordVoicingOps,
    };
}
