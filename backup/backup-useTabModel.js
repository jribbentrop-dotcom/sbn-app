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

export function useTabModel(melody, sections, timeSignature, repeatMarkers, voltaEndings, pendingStructureHint) {

    // ── Core state ─────────────────────────────────────────

    const model = ref(null);             // TabModel object — deep reactive
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
        if (!mel || !mel.length) {
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

        // ── Extract chord names from section data ────────────────

        const secs = sections.value || [];
        let chordOffset = 0;
        secs.forEach(sec => {
            const secMeasures = sec.measures || [];
            secMeasures.forEach((sm, li) => {
                const globalIdx = chordOffset + li;
                if (globalIdx < measures.length) {
                    // Extract chord names — handles various formats from Alpine
                    const chords = sm.chords || sm.chord_names || [];
                    if (Array.isArray(chords)) {
                        measures[globalIdx].chordNames = chords.map(c =>
                            typeof c === 'string' ? c : (c.chordName || c.name || c.chord || '')
                        ).filter(Boolean);
                    } else if (typeof chords === 'string' && chords) {
                        measures[globalIdx].chordNames = [chords];
                    } else {
                        measures[globalIdx].chordNames = [];
                    }
                }
            });
            chordOffset += secMeasures.length;
        });

        // ── Build section slices ───────────────────────────────
        // (secs already declared above for chord name extraction)
        const sectionModels = [];
        let globalOffset = 0;

        if (secs.length) {
            secs.forEach(sec => {
                const count = (sec.measures || []).length;
                sectionModels.push({
                    id:       sec.id || '',
                    name:     sec.name || '',
                    measures: measures.slice(globalOffset, globalOffset + count),
                });
                globalOffset += count;
            });
        } else {
            // Fallback: single implicit section
            sectionModels.push({
                id:       '',
                name:     '',
                measures,
            });
        }

        model.value = {
            timeSignature: timeSig,
            ticksPerMeasure: tpm,
            sections: sectionModels,
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

            group.forEach(e => {
                if (e.beam1 === 'begin') {
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
            patchChordNames(); // still sync chord names from the restored sections
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
                id:       sec.id   || null,
                name:     sec.name || sec.id || null,
                measures: sectionMeasures,
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
                id:       sec.id   || null,
                name:     sec.name || sec.id || null,
                measures: sectionMeasures,
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
     * Strips circular references (beamWith) and non-serializable state.
     */
    function serializeModel() {
        if (!model.value) return null;

        return {
            timeSignature:   model.value.timeSignature,
            ticksPerMeasure: model.value.ticksPerMeasure,
            sections: model.value.sections.map(sec => ({
                id:   sec.id,
                name: sec.name,
                measures: sec.measures.map(m => ({
                    index:       m.index,
                    chordNames:  m.chordNames ? [...m.chordNames] : [],
                    repeatStart: m.repeatStart,
                    repeatEnd:   m.repeatEnd,
                    volta:       m.volta ? { ...m.volta } : null,
                    voltaStart:  m.voltaStart,
                    voltaEnd:    m.voltaEnd,
                    events: m.events.map(ev => ({
                        ...ev,
                        beamWith: null,  // strip circular ref
                        notes: ev.notes.map(n => ({
                            ...n,
                            // Strip cross-event tie references (circular)
                            tieEndEvent:   undefined,
                            tieEndNote:    undefined,
                            tieStartEvent: undefined,
                            tieStartNote:  undefined,
                        })),
                    })),
                })),
            })),
        };
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
            sections: snapshot.sections.map(sec => ({
                id:   sec.id,
                name: sec.name,
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
        // First: re-link from XML beam data
        const groups = {};
        events.forEach(ev => {
            if (ev.isRest || !ev.beam1) return;
            const key = `${ev.measureIdx}-${ev.voice}`;
            if (!groups[key]) groups[key] = [];
            groups[key].push(ev);
        });
        for (const group of Object.values(groups)) {
            group.sort((a, b) => a.tick - b.tick);
            let beamGroup = [];
            group.forEach(ev => {
                if (ev.beam1 === 'begin')    { beamGroup = [ev]; }
                else if (ev.beam1 === 'continue' && beamGroup.length) { beamGroup.push(ev); }
                else if (ev.beam1 === 'end'  && beamGroup.length) {
                    beamGroup.push(ev);
                    beamGroup.forEach(m => { m.beamWith = beamGroup; });
                    beamGroup = [];
                }
            });
        }

        // Then: link unbeamed tuplet groups (quarter triplets etc.)
        linkUnbeamedTuplets(events);
    }

    // ── Watch for data changes and rebuild ──────────────────

    // Full rebuild only when melody/structure changes.
    watch([melody, timeSignature, repeatMarkers, voltaEndings], () => {
        buildModel();
    }, { deep: false });

    // Structural + chord-name sync — preserves unsaved tab edits.
    // Replaces the previous patchChordNames()-only watcher.
    //
    // NOTE: Do NOT guard with `if (_restoringSnapshot) return` here.
    // patchStructure() handles the _restoringSnapshot flag internally —
    // it consumes and clears the flag, then syncs chord names before
    // returning. If the watcher returns early, patchStructure() never
    // runs, the flag never resets, and ALL subsequent chord changes
    // are silently dropped forever.
    watch(sections, () => {
        if (!model.value) {
            buildModel();
            return;
        }
        patchStructure();
    }, { deep: false });

    return {
        model,
        hasData,
        buildModel,
        patchChordNames,
        patchStructure,
        serializeModel,
        deserializeModel,
    };
}
