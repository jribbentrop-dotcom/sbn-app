/**
 * SBN Leadsheet Admin JavaScript
 * File: assets/js/admin-leadsheet.js
 * 
 * Handles MusicXML parsing and shortcode generation in WordPress admin
 */

(function($) {
    'use strict';

    // =============================================================================
    // MUSICXML PARSER (same logic as standalone converter)
    // =============================================================================

    class MusicXMLParser {
        constructor(xmlString) {
            const parser = new DOMParser();
            this.doc = parser.parseFromString(xmlString, 'text/xml');
            this.divisions = 1;
        }

        parse() {
            const result = {
                title: this.getTitle(),
                composer: this.getComposer(),
                tempo: this.getTempo(),
                timeSignature: this.getTimeSignature(),
                key: this.getKey(),
                measures: [],
                chordVoicings: {},
                melody: []  // Simple array of note events with pitch/duration
            };

            const measures = this.doc.querySelectorAll('measure');
            const ticksPerBeat = 480; // Standard MIDI resolution (per quarter note)
            
            // Track the last chord for measures without explicit harmony
            let lastChord = null;
            
            // Calculate ticks per measure based on time signature
            // Time signature like 4/4, 3/4, 6/8, 2/2 etc.
            // The denominator tells us the beat unit: 4=quarter, 2=half, 8=eighth
            const timeSig = result.timeSignature.split('/');
            const beatsPerMeasure = parseInt(timeSig[0]) || 4;
            const beatType = parseInt(timeSig[1]) || 4;
            
            // ticksPerBeat is always based on quarter note (480)
            // If beat type is half note (2), each beat = 2 quarter notes = 960 ticks
            // If beat type is eighth (8), each beat = 0.5 quarter notes = 240 ticks
            const ticksPerBeatUnit = ticksPerBeat * (4 / beatType);
            const ticksPerMeasure = ticksPerBeatUnit * beatsPerMeasure;
            
            // --- Tab-to-Voicing extraction for files with no harmony elements ---
            const tabExtracted = this.extractVoicingsFromTab();
            let tabMeasureMap = {}; // measureIndex → tab-extracted measure data
            if (tabExtracted) {
                // Index tab measures by their measureNumber for fast lookup
                tabExtracted.measures.forEach(m => {
                    tabMeasureMap[m.measureNumber - 1] = m; // 0-indexed
                });
                // Merge voicings
                Object.assign(result.chordVoicings, tabExtracted.chordVoicings);
                console.log('[SBN Admin] Tab voicings merged:', Object.keys(tabExtracted.chordVoicings).length, 'entries');
                
                // Fire reverse-lookup to rename Tab1, Tab2 → real chord names
                // We do this asynchronously after parse() returns so the editor
                // renders immediately with placeholders, then updates.
                setTimeout(function() {
                    identifyTabVoicings(tabExtracted.chordVoicings);
                }, 0);
            }
            
            measures.forEach((measure, idx) => {
                const measureData = this.parseMeasure(measure, idx);
                const measureStartTick = idx * ticksPerMeasure;
                
                // Check for rehearsal marks / section labels in <direction>
                const directions = measure.querySelectorAll('direction');
                let rehearsalMark = null;
                
                directions.forEach(dir => {
                    const rehearsal = dir.querySelector('direction-type rehearsal');
                    if (rehearsal) {
                        rehearsalMark = rehearsal.textContent.trim();
                        console.log(`[SBN Admin] Found rehearsal mark "${rehearsalMark}" at measure ${idx + 1}`);
                    }
                });
                
                // Check for repeat signs and volta endings in barline
                const barlines = measure.querySelectorAll('barline');
                let hasRepeatBackward = false;
                let hasRepeatForward = false;
                let voltaEnding = null;
                
                barlines.forEach(barline => {
                    const repeat = barline.querySelector('repeat');
                    if (repeat) {
                        const direction = repeat.getAttribute('direction');
                        if (direction === 'backward') {
                            hasRepeatBackward = true;
                            console.log(`[SBN Admin] Found repeat backward at measure ${idx + 1}`);
                        }
                        if (direction === 'forward') {
                            hasRepeatForward = true;
                            console.log(`[SBN Admin] Found repeat forward at measure ${idx + 1}`);
                        }
                    }
                    
                    // Check for volta endings
                    const ending = barline.querySelector('ending');
                    if (ending) {
                        const endingNumber = ending.getAttribute('number');
                        const endingType = ending.getAttribute('type');
                        const endingText = ending.textContent || `${endingNumber}.`;
                        
                        if (!result.voltaEndings) result.voltaEndings = {};
                        
                        // A single measure can have both a volta start (left barline) and
                        // volta stop (right barline). Store as array of pending entries.
                        if (!result._pendingVoltaList) result._pendingVoltaList = [];
                        result._pendingVoltaList.push({
                            number: parseInt(endingNumber),
                            type: endingType,
                            text: endingText
                        });
                        
                        console.log(`[SBN Admin] Found volta ending ${endingNumber} (${endingType}) at XML measure ${idx + 1}`);
                    }
                });
                
                // If this measure has chords, use them and update lastChord
                if (measureData.chords.length > 0) {
                    lastChord = measureData.chords[measureData.chords.length - 1];
                    // Add repeat markers and rehearsal marks to measure data
                    measureData.repeatStart = hasRepeatForward;
                    measureData.repeatEnd = hasRepeatBackward;
                    measureData.rehearsalMark = rehearsalMark;
                    if (hasRepeatForward) console.log(`[SBN Admin] Set repeatStart on measure ${result.measures.length}`);
                    if (hasRepeatBackward) console.log(`[SBN Admin] Set repeatEnd on measure ${result.measures.length}`);
                    
                    // Resolve pending volta entries to pushed measure index
                    // A measure can have multiple volta entries (start on left barline, stop on right)
                    if (result._pendingVoltaList && result._pendingVoltaList.length > 0) {
                        if (!result.voltaEndings) result.voltaEndings = {};
                        const pushedIdx = result.measures.length;
                        result._pendingVoltaList.forEach(v => {
                            // Use a composite key: "idx" for start, "idx_stop" for stop on same measure
                            const key = result.voltaEndings[pushedIdx] ? pushedIdx + '_' + v.type : pushedIdx;
                            result.voltaEndings[key] = v;
                            console.log(`[SBN Admin] Resolved volta ${v.number} (${v.type}) to pushed measure key ${key}`);
                        });
                        result._pendingVoltaList = [];
                    }
                    
                    result.measures.push(measureData);
                    measureData.chords.forEach((chord, ci) => {
                        // Store voicing on the chord occurrence itself
                        // (chord.voicing is already set from parseFrame)
                        
                        // Build chordVoicings: first occurrence of each name = default
                        if (chord.voicing && !result.chordVoicings[chord.name]) {
                            result.chordVoicings[chord.name] = chord.voicing;
                        }
                    });
                } else if (tabMeasureMap[idx]) {
                    // No harmony but tab extraction found voicings for this measure
                    const tabMeasure = tabMeasureMap[idx];
                    tabMeasure.repeatStart = hasRepeatForward;
                    tabMeasure.repeatEnd = hasRepeatBackward;
                    tabMeasure.rehearsalMark = rehearsalMark;
                    tabMeasure._fromTab = true;
                    
                    lastChord = tabMeasure.chords[tabMeasure.chords.length - 1];
                    
                    // Resolve pending volta entries
                    if (result._pendingVoltaList && result._pendingVoltaList.length > 0) {
                        if (!result.voltaEndings) result.voltaEndings = {};
                        const pushedIdx = result.measures.length;
                        result._pendingVoltaList.forEach(v => {
                            const key = result.voltaEndings[pushedIdx] ? pushedIdx + '_' + v.type : pushedIdx;
                            result.voltaEndings[key] = v;
                        });
                        result._pendingVoltaList = [];
                    }
                    
                    result.measures.push(tabMeasure);
                    tabMeasure.chords.forEach((chord) => {
                        if (chord.voicing && !result.chordVoicings[chord.name]) {
                            result.chordVoicings[chord.name] = chord.voicing;
                        }
                    });
                } else if (lastChord) {
                    // No harmony in this measure - repeat the last chord
                    const continuedMeasure = {
                        chords: [{
                            name: lastChord.name,
                            beats: lastChord.beats,
                            voicing: lastChord.voicing
                        }],
                        notes: measureData.notes || [],
                        measureNumber: idx + 1,
                        repeatStart: hasRepeatForward,
                        repeatEnd: hasRepeatBackward,
                        rehearsalMark: rehearsalMark
                    };
                    
                    // Resolve pending volta entries
                    if (result._pendingVoltaList && result._pendingVoltaList.length > 0) {
                        if (!result.voltaEndings) result.voltaEndings = {};
                        const pushedIdx = result.measures.length;
                        result._pendingVoltaList.forEach(v => {
                            const key = result.voltaEndings[pushedIdx] ? pushedIdx + '_' + v.type : pushedIdx;
                            result.voltaEndings[key] = v;
                            console.log(`[SBN Admin] Resolved volta ${v.number} (${v.type}) to pushed measure key ${key} (continued)`);
                        });
                        result._pendingVoltaList = [];
                    }
                    
                    result.measures.push(continuedMeasure);
                } else if (measureData.notes && measureData.notes.length > 0) {
                    // No harmony, no tab voicings, no lastChord, but has notes
                    // (e.g. pickup bar with only melody) — push with placeholder
                    const melodyOnlyMeasure = {
                        chords: [{ name: '?', beats: beatsPerMeasure }],
                        notes: measureData.notes,
                        measureNumber: idx + 1,
                        repeatStart: hasRepeatForward,
                        repeatEnd: hasRepeatBackward,
                        rehearsalMark: rehearsalMark
                    };
                    result.measures.push(melodyOnlyMeasure);
                } else if (tabExtracted) {
                    // Tab-extraction mode: push empty measures to preserve alignment
                    // (rest-only bars, empty bars between voiced sections)
                    result.measures.push({
                        chords: [{ name: '—', beats: beatsPerMeasure }],
                        notes: measureData.notes || [],
                        measureNumber: idx + 1,
                        repeatStart: hasRepeatForward,
                        repeatEnd: hasRepeatBackward,
                        rehearsalMark: rehearsalMark
                    });
                }
                
                // Store repeat markers for later expansion
                if (hasRepeatForward) {
                    if (!result.repeatMarkers) result.repeatMarkers = [];
                    result.repeatMarkers.push({ type: 'start', measureIndex: result.measures.length - 1 });
                }
                if (hasRepeatBackward) {
                    if (!result.repeatMarkers) result.repeatMarkers = [];
                    result.repeatMarkers.push({ type: 'end', measureIndex: result.measures.length - 1 });
                }
            });
            
            // DON'T expand repeats for display - keep compact form with repeat signs
            // Repeat markers are already attached to measures and will be rendered as barlines
            
            // ---------------------------------------------------------------
            // Group measures into sections based on rehearsal marks
            // ---------------------------------------------------------------
            result.sections = [];
            let currentSection = null;
            const sectionLetters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
            let sectionCounter = 0;
            
            result.measures.forEach((measure, idx) => {
                // Start a new section if we find a rehearsal mark
                if (measure.rehearsalMark || idx === 0) {
                    if (currentSection) {
                        result.sections.push(currentSection);
                    }
                    
                    const mark = measure.rehearsalMark || '';
                    // Use the rehearsal mark as the section ID if it's a single letter
                    // Otherwise auto-assign A, B, C...
                    const isSingleLetter = /^[A-Z]$/i.test(mark);
                    const sectionId = isSingleLetter ? mark.toUpperCase() : sectionLetters[sectionCounter] || ('S' + sectionCounter);
                    
                    currentSection = {
                        id: sectionId,
                        name: isSingleLetter ? sectionId : (mark || sectionId),
                        measures: [],
                        rhythmSlug: null // Can be set in editor later
                    };
                    sectionCounter++;
                }
                
                if (currentSection) {
                    currentSection.measures.push(measure);
                }
            });
            
            // Push the final section
            if (currentSection && currentSection.measures.length > 0) {
                result.sections.push(currentSection);
            }
            
            console.log('[SBN Admin] Sections:', result.sections.map(s => `${s.id}:${s.name} (${s.measures.length} bars)`));
            
            // ---------------------------------------------------------------
            // Detect per-occurrence voicing overrides
            // Default voicing = first encountered for each chord name
            // Override = any occurrence with a different voicing
            // ---------------------------------------------------------------
            let globalMeasureIdx = 0;
            result.sections.forEach(section => {
                section.measures.forEach(measure => {
                    measure.chords.forEach((chord, ci) => {
                        if (!chord.voicing) return;
                        var defaultV = result.chordVoicings[chord.name];
                        if (!defaultV) return;
                        // Compare frets — if different from default, store override
                        if (chord.voicing.frets !== defaultV.frets || chord.voicing.position !== defaultV.position) {
                            var overrideKey = chord.name + '@' + globalMeasureIdx + '.' + ci;
                            result.chordVoicings[overrideKey] = chord.voicing;
                            console.log('[SBN Admin] Voicing override:', overrideKey, chord.voicing.frets, '(default:', defaultV.frets + ')');
                        }
                    });
                    globalMeasureIdx++;
                });
            });
            
            // Collect all notes from measures (not expanded)
            const allNotes = [];
            result.measures.forEach((measureData, measureIdx) => {
                const measureStartTick = measureIdx * ticksPerMeasure;
                
                if (measureData.notes && measureData.notes.length > 0) {
                    measureData.notes.forEach(note => {
                        const ticks = this.durationToTicks(note.durationName, ticksPerBeat);
                        
                        // Convert measureTick (in divisions) to absolute tick position
                        const absoluteTick = measureStartTick + (note.measureTick * ticksPerBeat);
                        
                        allNotes.push({
                            ...note,
                            tick: absoluteTick,
                            ticks: ticks,
                            measureIdx: measureIdx,
                            // Add repeat info from measure for rendering
                            measureRepeatStart: measureData.repeatStart || false,
                            measureRepeatEnd: measureData.repeatEnd || false
                        });
                        
                        // Debug: log first note of each measure with repeat markers
                        if ((measureData.repeatStart || measureData.repeatEnd) && note === measureData.notes[0]) {
                            console.log(`[SBN Admin] Note in measure ${measureIdx} has repeatStart=${measureData.repeatStart} repeatEnd=${measureData.repeatEnd}`);
                        }
                    });
                }
            });
            
            // Now build melody with correct tied durations
            // IMPORTANT: We need to include BOTH notes of a tie for proper tie rendering
            // The tieStart note draws the outgoing arc, the tieStop note draws the incoming arc
            for (let i = 0; i < allNotes.length; i++) {
                const note = allNotes[i];
                
                // Include rests in the melody for tab display
                if (note.isRest) {
                    result.melody.push({
                        tick: note.tick,
                        duration: note.durationName,
                        ticks: note.ticks,
                        voice: note.voice || 1,
                        isRest: true
                    });
                    continue;
                }
                
                // For tie START notes, we could optionally merge the duration
                // But for rendering, we need both notes with their original durations
                // The renderer will draw the tie curves based on tieStart/tieStop flags
                
                result.melody.push({
                    tick: note.tick,
                    pitch: note.pitch,
                    octave: note.octave,
                    duration: note.durationName,
                    ticks: note.ticks,
                    tieStart: note.tieStart,
                    tieStop: note.tieStop,
                    // Voice info for multi-voice support
                    voice: note.voice || 1,
                    // Tab info
                    string: note.string,
                    fret: note.fret,
                    // Flag for chord notes (multiple notes at same tick)
                    isChordNote: note.isChordNote || false,
                    isRest: false,
                    // Repeat markers for rendering
                    measureRepeatStart: note.measureRepeatStart || false,
                    measureRepeatEnd: note.measureRepeatEnd || false
                });
                
                // Debug: log when adding notes with repeat markers to melody
                if (note.measureRepeatStart || note.measureRepeatEnd) {
                    console.log(`[SBN Admin] Added to melody: measure ${note.measureIdx} repeatStart=${note.measureRepeatStart} repeatEnd=${note.measureRepeatEnd}`);
                }
            }

            return result;
        }
        
        // Convert duration name to ticks
        durationToTicks(durName, ticksPerBeat) {
            const isDotted = durName.endsWith('d');
            const baseDur = isDotted ? durName.slice(0, -1) : durName;
            
            // Map: w=whole, h=half, q=quarter, e=eighth, s=16th
            const durMap = {
                'w': ticksPerBeat * 4,
                'h': ticksPerBeat * 2,
                'q': ticksPerBeat,
                'e': ticksPerBeat / 2,
                's': ticksPerBeat / 4,
                't': ticksPerBeat / 8
            };
            
            let ticks = durMap[baseDur] || ticksPerBeat;
            if (isDotted) ticks *= 1.5;
            
            return ticks;
        }
        
        // Convert ticks back to duration name (for tied notes)
        ticksToDurationName(ticks, ticksPerBeat) {
            // Check for exact matches first (including dotted)
            const durationMap = [
                { ticks: ticksPerBeat * 6, name: 'wd' },    // dotted whole
                { ticks: ticksPerBeat * 4, name: 'w' },     // whole
                { ticks: ticksPerBeat * 3, name: 'hd' },    // dotted half
                { ticks: ticksPerBeat * 2, name: 'h' },     // half
                { ticks: ticksPerBeat * 1.5, name: 'qd' },  // dotted quarter
                { ticks: ticksPerBeat, name: 'q' },         // quarter
                { ticks: ticksPerBeat * 0.75, name: 'ed' }, // dotted eighth
                { ticks: ticksPerBeat / 2, name: 'e' },     // eighth
                { ticks: ticksPerBeat * 0.375, name: 'sd' },// dotted 16th
                { ticks: ticksPerBeat / 4, name: 's' },     // 16th
                { ticks: ticksPerBeat / 8, name: 't' }      // 32nd
            ];
            
            // Find closest match
            let closest = durationMap[0];
            let minDiff = Math.abs(ticks - closest.ticks);
            
            for (const dur of durationMap) {
                const diff = Math.abs(ticks - dur.ticks);
                if (diff < minDiff) {
                    minDiff = diff;
                    closest = dur;
                }
            }
            
            return closest.name;
        }

        getTitle() {
            const title = this.doc.querySelector('work-title');
            return title ? title.textContent.trim() : 'Untitled';
        }

        getComposer() {
            const composer = this.doc.querySelector('creator[type="composer"]');
            return composer ? composer.textContent.trim() : '';
        }

        getTempo() {
            const tempo = this.doc.querySelector('per-minute');
            return tempo ? parseInt(tempo.textContent) : 120;
        }

        getTimeSignature() {
            const beats = this.doc.querySelector('beats');
            const beatType = this.doc.querySelector('beat-type');
            if (beats && beatType) {
                return beats.textContent + '/' + beatType.textContent;
            }
            return '4/4';
        }

        getKey() {
            const fifths = this.doc.querySelector('fifths');
            if (!fifths) return 'C';

            const mode = this.doc.querySelector('mode');
            const isMinor = mode && mode.textContent.trim().toLowerCase() === 'minor';
            
            const keys     = ['C', 'G', 'D', 'A', 'E', 'B', 'F#', 'C#'];
            const keysFlat = ['C', 'F', 'Bb', 'Eb', 'Ab', 'Db', 'Gb', 'Cb'];
            // Relative minors: same fifths value, minor tonic
            const minorKeys     = ['Am', 'Em', 'Bm', 'F#m', 'C#m', 'G#m', 'D#m', 'A#m'];
            const minorKeysFlat = ['Am', 'Dm', 'Gm', 'Cm', 'Fm', 'Bbm', 'Ebm', 'Abm'];

            const val = parseInt(fifths.textContent);
            
            if (isMinor) {
                if (val >= 0) return minorKeys[val] || 'Am';
                return minorKeysFlat[Math.abs(val)] || 'Am';
            }

            if (val >= 0) return keys[val] || 'C';
            return keysFlat[Math.abs(val)] || 'C';
        }

        parseMeasure(measure, measureIndex) {
            const divisions = measure.querySelector('divisions');
            if (divisions) {
                this.divisions = parseInt(divisions.textContent);
            }

            const beats = measure.querySelector('beats');
            const beatsPerMeasure = beats ? parseInt(beats.textContent) : 4;

            const harmonies = measure.querySelectorAll('harmony');
            const chords = [];

            if (harmonies.length === 0 && measure.querySelectorAll('note').length === 0) {
                return { chords: [], notes: [], measureNumber: measureIndex + 1 };
            }

            const beatsPerChord = harmonies.length > 0 ? beatsPerMeasure / harmonies.length : beatsPerMeasure;

            harmonies.forEach((harmony) => {
                const chord = this.parseHarmony(harmony);
                chord.beats = beatsPerChord;
                chords.push(chord);
            });

            // NEW: Parse melody notes
            const notes = this.parseNotes(measure);

            return { chords, notes, measureNumber: measureIndex + 1 };
        }

        // NEW: Parse notes from a measure with multi-voice support
        parseNotes(measure) {
            const notes = [];
            
            // We need to iterate through children in order to handle <backup> and <forward>
            // which affect timing for multi-voice notation
            let currentTick = 0;  // Position within this measure
            let lastNoteTick = 0; // For chord notes (simultaneous with previous)
            
            // Get all child elements in document order
            const children = measure.children;
            
            for (let i = 0; i < children.length; i++) {
                const el = children[i];
                const tagName = el.tagName.toLowerCase();
                
                // Handle backup - rewind time (used before second voice)
                if (tagName === 'backup') {
                    const durationEl = el.querySelector('duration');
                    if (durationEl) {
                        const backupDuration = parseInt(durationEl.textContent) / this.divisions;
                        currentTick -= backupDuration;
                        if (currentTick < 0) currentTick = 0;
                    }
                    continue;
                }
                
                // Handle forward - advance time (used to skip rests in a voice)
                if (tagName === 'forward') {
                    const durationEl = el.querySelector('duration');
                    if (durationEl) {
                        const forwardDuration = parseInt(durationEl.textContent) / this.divisions;
                        currentTick += forwardDuration;
                    }
                    continue;
                }
                
                // Handle notes
                if (tagName === 'note') {
                    const noteEl = el;
                    
                    // Get voice number (defaults to 1)
                    const voiceEl = noteEl.querySelector('voice');
                    const voice = voiceEl ? parseInt(voiceEl.textContent) : 1;
                    
                    // Get duration for timing advancement
                    const duration = this.parseDuration(noteEl);
                    const durationName = this.getDurationName(noteEl);
                    
                    // Check if this is a chord note (simultaneous with previous)
                    const isChordNote = !!noteEl.querySelector('chord');
                    
                    // Determine tick position for this note
                    let noteTick;
                    if (isChordNote) {
                        // Chord notes share tick with previous note
                        noteTick = lastNoteTick;
                    } else {
                        noteTick = currentTick;
                        lastNoteTick = currentTick;
                        // Advance time for next note (unless it's a chord note)
                        currentTick += duration;
                    }
                    
                    // Skip rests but still track them for voice/timing
                    if (noteEl.querySelector('rest')) {
                        notes.push({
                            isRest: true,
                            duration: duration,
                            durationName: durationName,
                            voice: voice,
                            measureTick: noteTick
                        });
                        continue;
                    }
                    
                    const pitch = noteEl.querySelector('pitch');
                    if (!pitch) continue;
                    
                    const step = pitch.querySelector('step');
                    const octave = pitch.querySelector('octave');
                    const alter = pitch.querySelector('alter');
                    
                    if (!step || !octave) continue;
                    
                    // Get note name with accidental
                    let noteName = step.textContent;
                    if (alter) {
                        const alterVal = parseInt(alter.textContent);
                        if (alterVal === 1) noteName += '#';
                        else if (alterVal === -1) noteName += 'b';
                    }
                    
                    // Get lyrics if present
                    const lyricEl = noteEl.querySelector('lyric text');
                    const lyric = lyricEl ? lyricEl.textContent : null;
                    
                    // Check for tie
                    const tieStart = noteEl.querySelector('tie[type="start"]');
                    const tieStop = noteEl.querySelector('tie[type="stop"]');
                    
                    // Extract tablature data if present
                    const technical = noteEl.querySelector('notations technical');
                    let tabString = null;
                    let tabFret = null;
                    
                    if (technical) {
                        const stringEl = technical.querySelector('string');
                        const fretEl = technical.querySelector('fret');
                        if (stringEl) tabString = parseInt(stringEl.textContent);
                        if (fretEl) tabFret = parseInt(fretEl.textContent);
                    }
                    
                    notes.push({
                        pitch: noteName,
                        octave: parseInt(octave.textContent),
                        duration: duration,
                        durationName: durationName,
                        lyric: lyric,
                        tieStart: !!tieStart,
                        tieStop: !!tieStop,
                        isRest: false,
                        isChordNote: isChordNote,
                        // Voice info for multi-voice support
                        voice: voice,
                        measureTick: noteTick,  // Tick position within measure
                        // Tab data
                        string: tabString,
                        fret: tabFret
                    });
                }
            }
            
            return notes;
        }
        
        // Parse duration from note element
        parseDuration(noteEl) {
            const durationEl = noteEl.querySelector('duration');
            if (!durationEl) return 1;
            return parseInt(durationEl.textContent) / this.divisions;
        }
        
        // Get duration name (whole, half, quarter, eighth, 16th)
        getDurationName(noteEl) {
            const typeEl = noteEl.querySelector('type');
            if (!typeEl) return 'quarter';
            
            const type = typeEl.textContent;
            const dotEl = noteEl.querySelector('dot');
            const hasDot = !!dotEl;
            
            // Map MusicXML type to AlphaTeX duration
            const typeMap = {
                'whole': 'w',
                'half': 'h',
                'quarter': 'q',
                'eighth': 'e',
                '16th': 's',
                '32nd': 't',
                '64th': 'x'
            };
            
            let dur = typeMap[type] || 'q';
            if (hasDot) dur += 'd';
            
            return dur;
        }

        parseHarmony(harmony) {
            const rootStep = harmony.querySelector('root-step');
            const rootAlter = harmony.querySelector('root-alter');
            const kind = harmony.querySelector('kind');
            const bassStep = harmony.querySelector('bass-step');
            const bassAlter = harmony.querySelector('bass-alter');

            let chordName = rootStep ? rootStep.textContent : 'C';

            if (rootAlter) {
                const alter = parseInt(rootAlter.textContent);
                if (alter === 1) chordName += '#';
                else if (alter === -1) chordName += 'b';
            }

            if (kind) {
                const kindText = kind.getAttribute('text') || '';
                const kindValue = kind.textContent || '';
                
                // Map MusicXML kind values to chord symbols
                // The 'text' attribute can be misleading (e.g. "7" for diminished-seventh)
                // so we cross-reference with the semantic kind value
                const kindValueMap = {
                    'major': '',
                    'minor': 'm',
                    'augmented': 'aug',
                    'diminished': 'dim',
                    'dominant': '7',
                    'major-seventh': 'Maj7',
                    'minor-seventh': 'm7',
                    'diminished-seventh': '°7',
                    'augmented-seventh': 'aug7',
                    'half-diminished': 'm7b5',
                    'major-minor': 'mMaj7',
                    'major-sixth': '6',
                    'minor-sixth': 'm6',
                    'dominant-ninth': '9',
                    'major-ninth': 'Maj9',
                    'minor-ninth': 'm9',
                    'dominant-11th': '11',
                    'major-11th': 'Maj11',
                    'minor-11th': 'm11',
                    'dominant-13th': '13',
                    'major-13th': 'Maj13',
                    'minor-13th': 'm13',
                    'suspended-second': 'sus2',
                    'suspended-fourth': 'sus4',
                    'power': '5'
                };
                
                // Use semantic mapping if available, fall back to text attribute
                if (kindValue && kindValueMap.hasOwnProperty(kindValue)) {
                    chordName += kindValueMap[kindValue];
                } else if (kindText) {
                    chordName += kindText;
                }
            }

            // Parse degree elements for extensions like (b9), (13), (#11), etc.
            const degrees = harmony.querySelectorAll('degree');
            if (degrees.length > 0) {
                const useParentheses = kind && kind.getAttribute('parentheses-degrees') === 'yes';
                const extensions = [];
                
                degrees.forEach(degree => {
                    const degreeValue = degree.querySelector('degree-value');
                    const degreeAlter = degree.querySelector('degree-alter');
                    const degreeType = degree.querySelector('degree-type');
                    
                    if (degreeValue) {
                        let ext = '';
                        const value = degreeValue.textContent;
                        const alter = degreeAlter ? parseInt(degreeAlter.textContent) : 0;
                        const type = degreeType ? degreeType.textContent : 'add';
                        
                        // Add alteration prefix
                        if (alter === -1) ext += 'b';
                        else if (alter === 1) ext += '#';
                        
                        ext += value;
                        
                        // Handle subtract type (e.g., "no 5")
                        if (type === 'subtract') {
                            ext = 'no' + value;
                        }
                        
                        extensions.push(ext);
                    }
                });
                
                if (extensions.length > 0) {
                    if (useParentheses) {
                        chordName += '(' + extensions.join(',') + ')';
                    } else {
                        chordName += extensions.join('');
                    }
                }
            }

            if (bassStep) {
                let bass = bassStep.textContent;
                if (bassAlter) {
                    const alter = parseInt(bassAlter.textContent);
                    if (alter === 1) bass += '#';
                    else if (alter === -1) bass += 'b';
                }
                chordName += '/' + bass;
            }

            const frame = harmony.querySelector('frame');
            let voicing = null;

            if (frame) {
                voicing = this.parseFrame(frame);
            }

            return { name: chordName, voicing };
        }

        parseFrame(frame) {
            const frameStrings = frame.querySelector('frame-strings');
            const numStrings = frameStrings ? parseInt(frameStrings.textContent) : 6;

            const firstFret = frame.querySelector('first-fret');
            const position = firstFret ? parseInt(firstFret.textContent) : 1;

            const frets = Array(numStrings).fill('x');
            const fingers = Array(numStrings).fill('0');

            const frameNotes = frame.querySelectorAll('frame-note');
            frameNotes.forEach(note => {
                const string = note.querySelector('string');
                const fret = note.querySelector('fret');
                const fingering = note.querySelector('fingering');

                if (string && fret) {
                    const stringNum = parseInt(string.textContent);
                    const fretNum = parseInt(fret.textContent);
                    const idx = numStrings - stringNum;

                    // Use hex for frets > 9 (a=10, b=11, c=12, etc.)
                    if (fretNum === 0) {
                        frets[idx] = '0';
                    } else {
                        frets[idx] = fretNum <= 9 ? fretNum.toString() : fretNum.toString(16);
                    }

                    if (fingering) {
                        fingers[idx] = fingering.textContent;
                    }
                }
            });

            return {
                frets: frets.join(''),
                fingers: fingers.join(''),
                position: position
            };
        }

        // =================================================================
        // TAB-TO-VOICING EXTRACTION
        // For XML files with tab data but no <harmony>/<frame> elements,
        // detect chord shapes from simultaneous/arpeggiated tab notes.
        //
        // Two detection modes:
        //   1. Block chords (Wes Montgomery style): 3+ simultaneous notes
        //   2. Arpeggiated chords (bossa style): bass note on strings 4-6
        //      followed by 2+ upper-string notes within a 2-eighth-note window
        // =================================================================

        extractVoicingsFromTab() {
            const measures = this.doc.querySelectorAll('measure');
            if (!measures.length) return null;

            // Check if file has tab data at all
            const hasTab = this.doc.querySelector('notations technical string') &&
                           this.doc.querySelector('notations technical fret');
            if (!hasTab) return null;

            // Count measures with harmony vs tab-only to decide if extraction is needed.
            // If most measures already have harmony, the normal parser handles it.
            // But if only a few do (e.g. stray exports), run tab extraction for the rest.
            const harmonyCount = this.doc.querySelectorAll('harmony').length;
            const measuresWithHarmony = new Set();
            measures.forEach((m, i) => {
                if (m.querySelectorAll('harmony').length > 0) measuresWithHarmony.add(i);
            });
            if (measuresWithHarmony.size > measures.length * 0.5) {
                // More than half the measures have harmony — let the normal parser handle it
                return null;
            }

            console.log(`[SBN Tab→Voicing] ${measuresWithHarmony.size}/${measures.length} measures have harmony, extracting voicings from tab for the rest...`);

            // Read initial divisions from the document (constructor default is 1)
            const initialDivs = this.doc.querySelector('divisions');
            if (initialDivs) this.divisions = parseInt(initialDivs.textContent);

            let eighthTicks = this.divisions / 2; // ticks per eighth note
            let arpeggioWindow = eighthTicks * 2;  // 2 eighth notes lookahead

            const timeSig = this.getTimeSignature().split('/');
            const beatsPerMeasure = parseInt(timeSig[0]) || 4;

            const allMeasureVoicings = []; // array of arrays: per-measure voicing groups

            measures.forEach((measure, mIdx) => {
                // Re-read divisions if updated in this measure
                const divEl = measure.querySelector('divisions');
                if (divEl) {
                    this.divisions = parseInt(divEl.textContent);
                    eighthTicks = this.divisions / 2;
                    arpeggioWindow = eighthTicks * 2;
                }

                // Skip measures that already have harmony elements
                if (measuresWithHarmony.has(mIdx)) {
                    allMeasureVoicings.push([]);
                    return;
                }

                // Collect all tab notes with tick positions
                const noteEvents = this._collectTabNotes(measure);
                if (!noteEvents.length) {
                    allMeasureVoicings.push([]);
                    return;
                }

                // Group by tick position
                const byTick = {};
                noteEvents.forEach(n => {
                    if (!byTick[n.tick]) byTick[n.tick] = [];
                    byTick[n.tick].push(n);
                });
                const ticks = Object.keys(byTick).map(Number).sort((a, b) => a - b);

                // --- Phase 1: Find block chords (3+ simultaneous notes) ---
                const blockChords = [];
                const blockTicks = new Set();
                ticks.forEach(t => {
                    if (byTick[t].length >= 3) {
                        blockChords.push({ tick: t, notes: byTick[t] });
                        blockTicks.add(t);
                    }
                });

                // --- Phase 2: Arpeggio grip detection ---
                // For arpeggiated patterns (João Gilberto style) where
                // consecutive notes outline a chord grip:
                // - Each note on a different string
                // - Intervals > major 2nd (> 2 semitones) between consecutive notes
                // - Scan up to 6 notes ahead
                // - Max span: half a measure (to avoid crossing chord boundaries)
                // Runs before bass+upper detection so it can claim full grips first.
                const gripChords = [];
                const gripClaimedTicks = new Set([...blockTicks]);
                const halfMeasureTicks = this.divisions * beatsPerMeasure / 2;
                let ni = 0;
                while (ni < noteEvents.length) {
                    const startNote = noteEvents[ni];
                    if (gripClaimedTicks.has(startNote.tick)) { ni++; continue; }

                    const grip = [startNote];
                    const stringsUsed = new Set([startNote.string]);

                    for (let nj = ni + 1; nj < Math.min(ni + 6, noteEvents.length); nj++) {
                        const candidate = noteEvents[nj];
                        if (gripClaimedTicks.has(candidate.tick)) break;
                        // Must be a different string
                        if (stringsUsed.has(candidate.string)) break;
                        // Must be within half a measure of the grip start
                        if (candidate.tick - startNote.tick >= halfMeasureTicks) break;
                        // Interval > major 2nd between consecutive notes
                        const prevMidi = this._stringFretToMidi(grip[grip.length - 1].string, grip[grip.length - 1].fret);
                        const curMidi = this._stringFretToMidi(candidate.string, candidate.fret);
                        if (Math.abs(curMidi - prevMidi) <= 2) break;

                        grip.push(candidate);
                        stringsUsed.add(candidate.string);
                    }

                    if (grip.length >= 3) {
                        // Validate: reject grips assembled purely from small groups
                        // (double-stops or single notes) that have no bass string.
                        // These are typically melodic intervals, not chord shapes.
                        //
                        // Accept if:
                        //   - Any single tick in the grip has 3+ notes (block chord fragment)
                        //   - Any grip note is on a bass string (5 or 6) — suggests arpeggio
                        // Reject otherwise (likely melodic double-stops being merged).
                        const tickCounts = {};
                        let hasBass = false;
                        grip.forEach(n => {
                            tickCounts[n.tick] = (tickCounts[n.tick] || 0) + 1;
                            if (n.string >= 5) hasBass = true;
                        });
                        const maxSimultaneous = Math.max(...Object.values(tickCounts));

                        if (maxSimultaneous >= 3 || hasBass) {
                            gripChords.push({ tick: grip[0].tick, notes: grip, isGrip: true });
                            grip.forEach(n => gripClaimedTicks.add(n.tick));
                        }
                        ni += grip.length;
                    } else {
                        ni++;
                    }
                }

                // --- Phase 3: Find bass+upper arpeggiated chords ---
                // Bass note (string >= 4) followed by 2+ upper notes within window.
                // Only for notes not already claimed by blocks or grips.
                const arpeggioChords = [];
                ticks.forEach((t, ti) => {
                    if (gripClaimedTicks.has(t)) return;
                    const notes = byTick[t];
                    const bassNotes = notes.filter(n => n.string >= 4);
                    if (!bassNotes.length) return;
                    if (notes.length >= 3) return;

                    const upperNotes = [];
                    for (let j = ti + 1; j < ticks.length; j++) {
                        const t2 = ticks[j];
                        if (t2 - t > arpeggioWindow) break;
                        if (gripClaimedTicks.has(t2)) break;
                        byTick[t2].forEach(n => {
                            if (n.string <= 3) upperNotes.push(n);
                        });
                    }

                    if (upperNotes.length >= 2) {
                        const allNotes = [...bassNotes, ...upperNotes];
                        arpeggioChords.push({ tick: t, notes: allNotes, isArpeggio: true });
                    }
                });

                // --- Phase 4: Absorb lone bass notes into adjacent block chords ---
                // Pattern: bass note (string 4-6) on its own, followed within the
                // arpeggio window by a block chord that has no bass. The bass note
                // "belongs" to that block chord (bossa bass-then-strum pattern).
                blockChords.forEach(bc => {
                    const hasBass = bc.notes.some(n => n.string >= 5); // string 5-6 = A,E
                    if (hasBass) return; // already has a bass note

                    // Look backwards within window for a lone bass note
                    for (let ti = ticks.indexOf(bc.tick) - 1; ti >= 0; ti--) {
                        const t2 = ticks[ti];
                        if (bc.tick - t2 > arpeggioWindow) break;
                        if (blockTicks.has(t2)) break; // don't steal from other blocks
                        
                        const notes2 = byTick[t2];
                        // Must be 1-2 notes with at least one bass
                        if (notes2.length > 2) break;
                        const bassHere = notes2.filter(n => n.string >= 4);
                        if (!bassHere.length) continue;
                        
                        // Absorb: add bass notes to the block chord
                        bassHere.forEach(bn => {
                            // Only add if this string isn't already represented
                            if (!bc.notes.some(n => n.string === bn.string)) {
                                bc.notes.push(bn);
                            }
                        });
                        break; // only absorb from the closest preceding tick
                    }
                });

                // --- Phase 5: Deduplicate ---
                // Merge blocks, grips, and arpeggios, sort by tick
                let combined = [...blockChords, ...gripChords, ...arpeggioChords].sort((a, b) => a.tick - b.tick);

                // Build voicing objects from note groups
                const measureVoicings = combined.map(group => {
                    const voicing = this._notesToVoicing(group.notes);
                    const beat = group.tick / this.divisions + 1;
                    return {
                        tick: group.tick,
                        beat: beat,
                        voicing: voicing,
                        noteCount: group.notes.length,
                        isArpeggio: !!group.isArpeggio
                    };
                });

                // Deduplicate consecutive identical fret shapes within the same measure
                // (bossa style: same chord plucked repeatedly)
                // Also merge shapes that differ only by bass note presence:
                // e.g. x5756x → xx756x is the same chord, just without bass restrike
                // BUT: only merge if they are close together (within half a measure).
                // If the same shape appears on beats 1 and 3, keep both — that's a
                // deliberate repeated chord with specific rhythmic placement.
                const deduped = [];
                measureVoicings.forEach(mv => {
                    const last = deduped.length ? deduped[deduped.length - 1] : null;
                    // Don't merge shapes separated by more than half a measure
                    const tooFarApart = last && (mv.tick - last.tick >= halfMeasureTicks);
                    
                    if (!tooFarApart && last && last.voicing.frets === mv.voicing.frets) {
                        // Exact same shape, close together — skip
                        return;
                    }
                    // Check if shapes differ only on bass strings (positions 0-2 = strings 6-4)
                    // and the upper strings (positions 3-5) are identical
                    if (!tooFarApart && last) {
                        const lastF = last.voicing.frets;
                        const curF = mv.voicing.frets;
                        const lastUpper = lastF.substring(3);
                        const curUpper = curF.substring(3);
                        if (lastUpper === curUpper && lastUpper !== 'xxx') {
                            // Upper strings match — merge by keeping the fuller shape (more strings)
                            const lastBassCount = lastF.substring(0, 3).replace(/x/g, '').length;
                            const curBassCount = curF.substring(0, 3).replace(/x/g, '').length;
                            if (curBassCount > lastBassCount) {
                                // Current has more bass info, replace
                                deduped[deduped.length - 1] = mv;
                            }
                            // Either way, skip adding a new entry
                            return;
                        }
                    }
                    deduped.push(mv);
                });

                allMeasureVoicings.push(deduped);
            });

            // --- Phase 4: Build result ---
            // Generate placeholder chord names (Tab1, Tab2, ...) grouped by unique fret shape
            // These will later be matched against the voicing DB for real names
            const shapeToName = {};
            let shapeCounter = 1;

            const resultMeasures = [];
            const resultVoicings = {};

            allMeasureVoicings.forEach((measureVoicings, mIdx) => {
                if (!measureVoicings.length) return; // skip empty measures

                const chords = [];
                const totalBeats = beatsPerMeasure;

                measureVoicings.forEach((mv, vi) => {
                    // Calculate beats for this chord (from this tick to next, or end of measure)
                    const nextTick = (vi + 1 < measureVoicings.length)
                        ? measureVoicings[vi + 1].tick
                        : this.divisions * beatsPerMeasure;
                    const beats = (nextTick - mv.tick) / this.divisions;

                    // Assign a name based on the fret shape
                    let name = shapeToName[mv.voicing.frets];
                    if (!name) {
                        name = 'Tab' + shapeCounter++;
                        shapeToName[mv.voicing.frets] = name;
                    }

                    chords.push({
                        name: name,
                        beats: Math.max(beats, 0.5), // minimum half-beat
                        voicing: mv.voicing
                    });

                    // Store in voicings dict
                    if (!resultVoicings[name]) {
                        resultVoicings[name] = mv.voicing;
                    }
                });

                resultMeasures.push({
                    chords: chords,
                    notes: this.parseNotes(measures[mIdx]),
                    measureNumber: mIdx + 1,
                    _fromTab: true  // flag for editor to know this was auto-detected
                });
            });

            const totalBlocks = allMeasureVoicings.reduce((s, m) => s + m.length, 0);
            const uniqueShapes = Object.keys(shapeToName).length;
            console.log(`[SBN Tab→Voicing] Extracted ${totalBlocks} chord positions across ${resultMeasures.length} measures (${uniqueShapes} unique shapes)`);

            return {
                measures: resultMeasures,
                chordVoicings: resultVoicings,
                shapeToName: shapeToName
            };
        }

        /**
         * Collect all tab notes from a measure with tick positions.
         * Returns array of { tick, string, fret, pitch, octave, duration }
         */
        _collectTabNotes(measure) {
            const notes = [];
            let tick = 0;
            let lastTick = 0;

            const children = measure.children;
            for (let i = 0; i < children.length; i++) {
                const el = children[i];
                const tag = el.tagName.toLowerCase();

                if (tag === 'backup') {
                    const dur = el.querySelector('duration');
                    if (dur) tick -= parseInt(dur.textContent);
                    if (tick < 0) tick = 0;
                    continue;
                }
                if (tag === 'forward') {
                    const dur = el.querySelector('duration');
                    if (dur) tick += parseInt(dur.textContent);
                    continue;
                }
                if (tag !== 'note') continue;

                const isChord = !!el.querySelector('chord');
                const isRest = !!el.querySelector('rest');
                const durEl = el.querySelector('duration');
                const duration = durEl ? parseInt(durEl.textContent) : 0;

                let currentTick;
                if (isChord) {
                    currentTick = lastTick;
                } else {
                    currentTick = tick;
                    lastTick = tick;
                    tick += duration;
                }

                if (isRest) continue;

                const technical = el.querySelector('notations technical');
                if (!technical) continue;
                const sEl = technical.querySelector('string');
                const fEl = technical.querySelector('fret');
                if (!sEl || !fEl) continue;

                const pitch = el.querySelector('pitch');
                let pitchName = '';
                let octave = 0;
                if (pitch) {
                    const step = pitch.querySelector('step');
                    const oct = pitch.querySelector('octave');
                    const alter = pitch.querySelector('alter');
                    pitchName = step ? step.textContent : '';
                    if (alter) {
                        const a = parseInt(alter.textContent);
                        if (a === 1) pitchName += '#';
                        else if (a === -1) pitchName += 'b';
                    }
                    octave = oct ? parseInt(oct.textContent) : 0;
                }

                notes.push({
                    tick: currentTick,
                    string: parseInt(sEl.textContent),
                    fret: parseInt(fEl.textContent),
                    pitch: pitchName,
                    octave: octave,
                    duration: duration
                });
            }
            return notes;
        }

        /**
         * Convert an array of {string, fret} notes into a voicing object.
         * Returns { frets: "x32010", position: 1, fingers: "000000" }
         */
        _notesToVoicing(notes) {
            // Build fret map: string (1-6) → fret number
            // If a string appears multiple times, use the first occurrence
            const fretMap = {};
            notes.forEach(n => {
                if (n.string >= 1 && n.string <= 6 && fretMap[n.string] === undefined) {
                    fretMap[n.string] = n.fret;
                }
            });

            // Build frets string: index 0 = low E (string 6), index 5 = high e (string 1)
            const frets = Array(6).fill('x');
            for (let s = 1; s <= 6; s++) {
                if (fretMap[s] !== undefined) {
                    const f = fretMap[s];
                    if (f === 0) {
                        frets[6 - s] = '0';
                    } else {
                        frets[6 - s] = f <= 9 ? f.toString() : f.toString(16);
                    }
                }
            }

            // Calculate position (first fret for diagram display)
            const frettedValues = Object.values(fretMap).filter(f => f > 0);
            let position = 1;
            if (frettedValues.length) {
                const minFret = Math.min(...frettedValues);
                const maxFret = Math.max(...frettedValues);
                // If the span fits in 4 frets from minFret, use minFret as position
                // Otherwise use minFret anyway — the diagram will stretch
                if (minFret > 1) {
                    position = minFret;
                }
            }

            return {
                frets: frets.join(''),
                position: position,
                fingers: '000000'
            };
        }

        /**
         * Convert guitar string + fret to MIDI note number.
         * Standard tuning: E2=40, A2=45, D3=50, G3=55, B3=59, E4=64
         * String numbering: 1=high E, 6=low E (MusicXML convention)
         */
        _stringFretToMidi(string, fret) {
            const openStrings = { 1: 64, 2: 59, 3: 55, 4: 50, 5: 45, 6: 40 };
            return (openStrings[string] || 40) + fret;
        }
        
        /**
         * Expand repeat sections in the measure array
         * NOTE: Currently not used - we keep compact form with repeat signs
         * This could be used in the future for playback expansion
         */
        /*
        expandRepeats(measures, repeatMarkers) {
            // Find repeat pairs (start followed by end)
            const pairs = [];
            let lastStart = null;
            
            repeatMarkers.forEach(marker => {
                if (marker.type === 'start') {
                    lastStart = marker.measureIndex;
                } else if (marker.type === 'end' && lastStart !== null) {
                    pairs.push({ start: lastStart, end: marker.measureIndex });
                    lastStart = null;
                }
            });
            
            // If we have an end without a start, assume start is from beginning
            if (lastStart === null) {
                repeatMarkers.forEach(marker => {
                    if (marker.type === 'end') {
                        pairs.push({ start: 0, end: marker.measureIndex });
                    }
                });
            }
            
            // No pairs found, return original
            if (pairs.length === 0) return measures;
            
            // Expand the repeats (working backwards to maintain indices)
            pairs.reverse().forEach(pair => {
                // Extract the repeated section
                const repeatedSection = measures.slice(pair.start, pair.end + 1);
                
                // Deep copy the section (to avoid reference issues)
                const copiedSection = repeatedSection.map(m => ({
                    ...m,
                    chords: m.chords.map(c => ({...c})),
                    notes: m.notes ? m.notes.map(n => ({...n})) : [],
                    // Remove repeat markers from copied measures (except the last one gets end marker)
                    repeatStart: false,
                    repeatEnd: false
                }));
                
                // The last measure of the copied section should have repeatEnd marker
                if (copiedSection.length > 0) {
                    copiedSection[copiedSection.length - 1].repeatEnd = true;
                }
                
                // Insert the copy after the original
                measures.splice(pair.end + 1, 0, ...copiedSection);
            });
            
            return measures;
        }
        */
    }

    // =============================================================================
    // SHORTCODE GENERATOR
    // =============================================================================

    function escapeAttr(str) {
        if (!str) return '';
        return str.replace(/"/g, '\\"').replace(/\n/g, ' ');
    }

    function generateShortcode(parsed, options) {
        // Build attributes on a single line (WordPress standard)
        let attrs = [];
        attrs.push('title="' + escapeAttr(parsed.title) + '"');
        
        if (parsed.composer) {
            attrs.push('composer="' + escapeAttr(parsed.composer) + '"');
        }
        
        attrs.push('key="' + escapeAttr(parsed.key) + '"');
        attrs.push('tempo="' + (parseInt(parsed.tempo) || parseInt(options.tempo) || 120) + '"');
        attrs.push('time="' + escapeAttr(parsed.timeSignature) + '"');

        if (options.rhythm) {
            attrs.push('rhythm="' + escapeAttr(options.rhythm) + '"');
        }

        let shortcode = '[sbn_leadsheet ' + attrs.join(' ') + ']\n\n';

        // Check if we have named sections (not just the default single section)
        const hasSections = parsed.sections && parsed.sections.length > 0;
        const usesSectionMarkers = hasSections && (
            parsed.sections.length > 1 ||
            (parsed.sections[0].id && parsed.sections[0].id !== 'A') ||
            (parsed.sections[0].name && parsed.sections[0].name !== 'Main') ||
            parsed.sections[0].rhythmSlug ||
            parsed.sections[0].tonality
        );
        
        // Chord progression - group by 4 bars per line
        const barsPerRow = 4;
        
        if (usesSectionMarkers) {
            // Multi-section format with [A], [B], etc.
            parsed.sections.forEach(section => {
                // Section header line: [A label="Intro" rhythm="bossa" breaks="4,4,3" info="..."]
                let sectionLine = '[' + (section.id || 'A');
                if (section.name && section.name !== section.id) {
                    sectionLine += ' label="' + escapeAttr(section.name) + '"';
                }
                if (section.rhythmSlug) {
                    sectionLine += ' rhythm="' + escapeAttr(section.rhythmSlug) + '"';
                }
                if (section.lineBreaks && section.lineBreaks.length) {
                    sectionLine += ' breaks="' + section.lineBreaks.join(',') + '"';
                }
                if (section.info) {
                    sectionLine += ' info="' + escapeAttr(section.info) + '"';
                }
                if (section.tonality) {
                    sectionLine += ' tonality="' + escapeAttr(section.tonality) + '"';
                }

                sectionLine += ']\n';
                shortcode += sectionLine;
                
                // Measures for this section — use lineBreaks if available
                var measList = section.measures || [];
                if (section.lineBreaks && section.lineBreaks.length) {
                    var mPos = 0;
                    section.lineBreaks.forEach(rowLen => {
                        for (var i = 0; i < rowLen && mPos < measList.length; i++) {
                            const chordStr = measList[mPos].chords.map(c => c.name).join(' ');
                            shortcode += '| ' + chordStr.padEnd(12) + ' ';
                            mPos++;
                        }
                        shortcode += '|\n';
                    });
                    // Any remaining measures (shouldn't happen, but safety)
                    while (mPos < measList.length) {
                        const chordStr = measList[mPos].chords.map(c => c.name).join(' ');
                        shortcode += '| ' + chordStr.padEnd(12) + ' ';
                        mPos++;
                    }
                    if (mPos > 0 && measList.length > 0) {
                        // Ensure last line terminated
                        var lastChar = shortcode[shortcode.length - 1];
                        if (lastChar !== '\n') shortcode += '|\n';
                    }
                } else {
                    // Default: use global barsPerRow
                    let barCount = 0;
                    measList.forEach(measure => {
                        const chordStr = measure.chords.map(c => c.name).join(' ');
                        shortcode += '| ' + chordStr.padEnd(12) + ' ';
                        barCount++;
                        if (barCount % barsPerRow === 0) {
                            shortcode += '|\n';
                        }
                    });
                    if (barCount % barsPerRow !== 0) {
                        shortcode += '|\n';
                    }
                }
                shortcode += '\n';
            });
        } else {
            // Legacy flat format (single section or no sections)
            const measures = hasSections 
                ? parsed.sections.flatMap(s => s.measures || []) 
                : parsed.measures || [];
            
            let barCount = 0;
            measures.forEach(measure => {
                const chordStr = measure.chords.map(c => c.name).join(' ');
                shortcode += '| ' + chordStr.padEnd(12) + ' ';
                barCount++;
                if (barCount % barsPerRow === 0) {
                    shortcode += '|\n';
                }
            });
            if (barCount % barsPerRow !== 0) {
                shortcode += '|\n';
            }
        }

        // Voicings section - only if we have voicings
        if (Object.keys(parsed.chordVoicings).length > 0) {
            shortcode += '\n[sbn_voicings]\n';

            // Sort chord names for consistent output
            const sortedChords = Object.keys(parsed.chordVoicings).sort();
            
            sortedChords.forEach(name => {
                const voicing = parsed.chordVoicings[name];
                
                // Validate voicing data — skip corrupted entries
                if (!voicing || !voicing.frets || typeof voicing.frets !== 'string') return;
                var f = voicing.frets;
                // Frets must be exactly 6 chars, each x or hex digit
                if (f.length !== 6 || !/^[x0-9a-f]{6}$/i.test(f)) {
                    console.warn('[SBN VE] Skipping corrupted voicing:', name, f);
                    return;
                }
                
                shortcode += name + ': ' + f;
                
                if (voicing.position && voicing.position > 1) {
                    shortcode += ' @' + voicing.position;
                }
                
                if (voicing.fingers && voicing.fingers !== '000000' && voicing.fingers !== '') {
                    shortcode += ' (' + voicing.fingers + ')';
                }
                
                shortcode += '\n';
            });

            shortcode += '[/sbn_voicings]\n';
        }
        
        // Repeat markers section - collect from all sources
        const repeatMarkers = {};
        
        // From flat measures (legacy per-measure flags)
        if (parsed.measures) {
            parsed.measures.forEach((measure, idx) => {
                if (measure.repeatStart) repeatMarkers[idx] = { ...repeatMarkers[idx], start: true };
                if (measure.repeatEnd) repeatMarkers[idx] = { ...repeatMarkers[idx], end: true };
            });
        }
        
        // From sections (new format per-measure flags) - use global measure indices
        if (parsed.sections) {
            let globalIdx = 0;
            parsed.sections.forEach(section => {
                (section.measures || []).forEach(measure => {
                    if (measure.repeatStart) repeatMarkers[globalIdx] = { ...repeatMarkers[globalIdx], start: true };
                    if (measure.repeatEnd) repeatMarkers[globalIdx] = { ...repeatMarkers[globalIdx], end: true };
                    globalIdx++;
                });
            });
        }
        
        // From parsed.repeatMarkers object (from edit mode or re-parsed data)
        if (parsed.repeatMarkers && typeof parsed.repeatMarkers === 'object') {
            Object.keys(parsed.repeatMarkers).forEach(key => {
                const val = parsed.repeatMarkers[key];
                repeatMarkers[key] = { ...repeatMarkers[key], ...val };
            });
        }
        
        if (Object.keys(repeatMarkers).length > 0) {
            shortcode += '\n[sbn_repeats]\n';
            shortcode += JSON.stringify(repeatMarkers);
            shortcode += '\n[/sbn_repeats]\n';
        }
        
        // Volta endings section
        if (parsed.voltaEndings && Object.keys(parsed.voltaEndings).length > 0) {
            shortcode += '\n[sbn_endings]\n';
            shortcode += JSON.stringify(parsed.voltaEndings);
            shortcode += '\n[/sbn_endings]\n';
        }
        
        // Melody section - store as JSON for direct Tone.js playback
        if (options.includeMelody && parsed.melody && parsed.melody.length > 0) {
            shortcode += '\n[sbn_melody]\n';
            shortcode += JSON.stringify(parsed.melody);
            shortcode += '\n[/sbn_melody]\n';
        }
        
        // Song-level description
        const desc = ($('#sbnDescription').val() || '').trim();
        if (desc) {
            shortcode += '\n[sbn_info]\n[description]\n' + desc + '\n[/description]\n[/sbn_info]\n';
        }
        
        shortcode += '\n[/sbn_leadsheet]';

        return shortcode;
    }

    // =============================================================================
    // GLOBAL STATE
    // =============================================================================

    let currentParsed = null;
    let barsPerRow = 4;
    let collapsedSections = {};

    // =========================================================================
    // MULTI-SELECT & CLIPBOARD
    // =========================================================================

    // Selection state: array of {si, mi} objects (section index, measure index)
    let selectedMeasures = [];
    let lastClickedMeasure = null; // for shift-click range selection
    let clipboard = null; // { mode: 'copy'|'cut', measures: [...] }
    let pasteTarget = null; // { si, mi, ci } — chord-level paste target, set when a chord is clicked

    function clearSelection() {
        selectedMeasures = [];
        lastClickedMeasure = null;
        pasteTarget = null;
        $('.sbn-ve-measure').removeClass('is-selected is-sel-first is-sel-last');
        $('.sbn-ve-chord').removeClass('is-paste-target');
        updateClipboardToolbar();
    }

    function isMeasureSelected(si, mi) {
        return selectedMeasures.some(function(s) { return s.si === si && s.mi === mi; });
    }

    function toggleMeasureSelection(si, mi) {
        var idx = selectedMeasures.findIndex(function(s) { return s.si === si && s.mi === mi; });
        if (idx !== -1) {
            selectedMeasures.splice(idx, 1);
        } else {
            selectedMeasures.push({ si: si, mi: mi });
        }
    }

    function selectRange(fromSi, fromMi, toSi, toMi) {
        // Convert to global indices, select everything between
        var fromG = getGlobalMeasureIndex(fromSi, fromMi);
        var toG = getGlobalMeasureIndex(toSi, toMi);
        var minG = Math.min(fromG, toG);
        var maxG = Math.max(fromG, toG);
        selectedMeasures = [];
        var g = 0;
        currentParsed.sections.forEach(function(sec, si) {
            (sec.measures || []).forEach(function(m, mi) {
                if (g >= minG && g <= maxG) {
                    selectedMeasures.push({ si: si, mi: mi });
                }
                g++;
            });
        });
    }

    function applySelectionClasses() {
        $('.sbn-ve-measure').removeClass('is-selected is-sel-first is-sel-last');
        if (!selectedMeasures.length) return;

        // Sort by global index for first/last detection
        var sorted = selectedMeasures.slice().sort(function(a, b) {
            return getGlobalMeasureIndex(a.si, a.mi) - getGlobalMeasureIndex(b.si, b.mi);
        });

        sorted.forEach(function(sel, idx) {
            var $m = $('.sbn-ve-measure[data-section="' + sel.si + '"][data-measure="' + sel.mi + '"]');
            $m.addClass('is-selected');
            if (idx === 0) $m.addClass('is-sel-first');
            if (idx === sorted.length - 1) $m.addClass('is-sel-last');
        });
    }

    function getSelectedMeasureData() {
        // Return deep copies of the selected measures in global order
        var sorted = selectedMeasures.slice().sort(function(a, b) {
            return getGlobalMeasureIndex(a.si, a.mi) - getGlobalMeasureIndex(b.si, b.mi);
        });
        return sorted.map(function(sel) {
            return JSON.parse(JSON.stringify(currentParsed.sections[sel.si].measures[sel.mi]));
        });
    }

    function doCopy() {
        if (!selectedMeasures.length) { showToast('No measures selected', 'error'); return; }
        clipboard = { mode: 'copy', measures: getSelectedMeasureData() };
        updateClipboardToolbar();
        showToast(clipboard.measures.length + ' bar' + (clipboard.measures.length > 1 ? 's' : '') + ' copied', 'success');
    }

    function doCut() {
        if (!selectedMeasures.length) { showToast('No measures selected', 'error'); return; }
        clipboard = { mode: 'cut', measures: getSelectedMeasureData() };

        // Remove selected measures in reverse order to keep indices valid
        var sorted = selectedMeasures.slice().sort(function(a, b) {
            return getGlobalMeasureIndex(b.si, b.mi) - getGlobalMeasureIndex(a.si, a.mi);
        });
        sorted.forEach(function(sel) {
            var sec = currentParsed.sections[sel.si];
            if (sec.measures.length > 1) {
                // Adjust lineBreaks before splicing
                if (sec.lineBreaks && sec.lineBreaks.length) {
                    var pos = 0;
                    for (var ri = 0; ri < sec.lineBreaks.length; ri++) {
                        if (sel.mi < pos + sec.lineBreaks[ri]) {
                            sec.lineBreaks[ri] -= 1;
                            if (sec.lineBreaks[ri] <= 0) sec.lineBreaks.splice(ri, 1);
                            break;
                        }
                        pos += sec.lineBreaks[ri];
                    }
                }
                var gIdx = getGlobalMeasureIndex(sel.si, sel.mi);
                sec.measures.splice(sel.mi, 1);
                reindexMeasureData('delete', gIdx);
            } else if (currentParsed.sections.length > 1) {
                // Last measure in section — remove section
                currentParsed.sections.splice(sel.si, 1);
            }
            // else: don't delete the very last measure of the very last section
        });

        selectedMeasures = [];
        updateClipboardToolbar();
        fullRefresh();
        showToast(clipboard.measures.length + ' bar' + (clipboard.measures.length > 1 ? 's' : '') + ' cut', 'success');
    }

    function doPaste() {
        if (!clipboard || !clipboard.measures.length) { showToast('Nothing on clipboard', 'error'); return; }

        // ── Chord-level paste ──
        // If a specific chord position is targeted, paste clipboard chords INTO that measure
        // at that chord position, replacing the target chord's slot.
        if (pasteTarget) {
            var tSi = pasteTarget.si, tMi = pasteTarget.mi, tCi = pasteTarget.ci;
            var targetMeasure = currentParsed.sections[tSi].measures[tMi];
            if (!targetMeasure) { pasteTarget = null; return; }

            // Collect all chords from clipboard measures into a flat list
            var chordsToPaste = [];
            clipboard.measures.forEach(function(m) {
                (m.chords || []).forEach(function(c) {
                    chordsToPaste.push({ name: c.name, beats: 0 }); // beats recalculated below
                });
            });
            if (!chordsToPaste.length) return;

            // Replace the target chord with the pasted chords (each gets equal share of the target's beats)
            var targetBeats = targetMeasure.chords[tCi].beats;
            var beatsEach = targetBeats / chordsToPaste.length;
            chordsToPaste.forEach(function(c) { c.beats = beatsEach; });

            // Splice: remove the target chord, insert pasted chords in its place
            var spliceArgs = [tCi, 1].concat(chordsToPaste);
            Array.prototype.splice.apply(targetMeasure.chords, spliceArgs);

            pasteTarget = null;
            fullRefresh();
            showToast(chordsToPaste.length + ' chord' + (chordsToPaste.length > 1 ? 's' : '') + ' pasted', 'success');
            return;
        }

        // ── Measure-level paste ──
        // Determine paste target: after last selected measure, or end of last section
        var targetSi, targetMi;
        if (selectedMeasures.length) {
            var sorted = selectedMeasures.slice().sort(function(a, b) {
                return getGlobalMeasureIndex(b.si, b.mi) - getGlobalMeasureIndex(a.si, a.mi);
            });
            targetSi = sorted[0].si;
            targetMi = sorted[0].mi + 1; // insert after last selected
        } else {
            targetSi = currentParsed.sections.length - 1;
            targetMi = currentParsed.sections[targetSi].measures.length;
        }

        // Deep copy the clipboard measures
        var newMeasures = JSON.parse(JSON.stringify(clipboard.measures));

        // Insert into target section
        var sec = currentParsed.sections[targetSi];
        var gIdx = getGlobalMeasureIndex(targetSi, targetMi);
        for (var i = 0; i < newMeasures.length; i++) {
            sec.measures.splice(targetMi + i, 0, newMeasures[i]);
            reindexMeasureData('insert', gIdx + i);
        }

        // Adjust lineBreaks: add pasted bars to the row containing targetMi
        if (sec.lineBreaks && sec.lineBreaks.length) {
            var pos = 0;
            for (var ri = 0; ri < sec.lineBreaks.length; ri++) {
                if (targetMi <= pos + sec.lineBreaks[ri]) {
                    sec.lineBreaks[ri] += newMeasures.length;
                    break;
                }
                pos += sec.lineBreaks[ri];
                if (ri === sec.lineBreaks.length - 1) {
                    // Past the last row — extend it
                    sec.lineBreaks[ri] += newMeasures.length;
                }
            }
        }

        // Select the newly pasted measures
        selectedMeasures = [];
        for (var j = 0; j < newMeasures.length; j++) {
            selectedMeasures.push({ si: targetSi, mi: targetMi + j });
        }

        fullRefresh();
        applySelectionClasses();
        showToast(newMeasures.length + ' bar' + (newMeasures.length > 1 ? 's' : '') + ' pasted', 'success');
    }

    function updateClipboardToolbar() {
        var hasSel = selectedMeasures.length > 0;
        var hasCb = clipboard && clipboard.measures && clipboard.measures.length > 0;
        $('#sbnCopyBtn').prop('disabled', !hasSel);
        $('#sbnCutBtn').prop('disabled', !hasSel);
        $('#sbnPasteBtn').prop('disabled', !hasCb);
        $('#sbnSelCount').text(hasSel ? selectedMeasures.length + ' selected' : '');
    }

    // =============================================================================
    // MEASURE DATA INTEGRITY — reindex melody/repeats/voltas on add/delete
    // =============================================================================

    function getTicksPerMeasure(timeSignature) {
        var parts = (timeSignature || '4/4').split('/');
        var beats = parseInt(parts[0]) || 4;
        var beatType = parseInt(parts[1]) || 4;
        return 480 * (4 / beatType) * beats;
    }

    function reindexMeasureData(operation, globalIdx) {
        if (!currentParsed) return;
        var tpm = getTicksPerMeasure(currentParsed.timeSignature);

        if (currentParsed.melody && currentParsed.melody.length) {
            if (operation === 'delete') {
                currentParsed.melody = currentParsed.melody.filter(function(n) {
                    return Math.floor(n.tick / tpm) !== globalIdx;
                });
                currentParsed.melody.forEach(function(n) {
                    if (Math.floor(n.tick / tpm) > globalIdx) n.tick -= tpm;
                });
            } else {
                currentParsed.melody.forEach(function(n) {
                    if (Math.floor(n.tick / tpm) >= globalIdx) n.tick += tpm;
                });
            }
        }

        if (currentParsed.repeatMarkers) {
            var newRepeats = {};
            $.each(currentParsed.repeatMarkers, function(key, val) {
                var idx = parseInt(key);
                if (operation === 'delete') {
                    if (idx === globalIdx) return;
                    if (idx > globalIdx) idx--;
                } else {
                    if (idx >= globalIdx) idx++;
                }
                newRepeats[idx.toString()] = val;
            });
            currentParsed.repeatMarkers = newRepeats;
        }

        if (currentParsed.voltaEndings) {
            var newVoltas = {};
            $.each(currentParsed.voltaEndings, function(key, val) {
                var match = key.match(/^(\d+)(_\w+)$/);
                if (!match) { newVoltas[key] = val; return; }
                var idx = parseInt(match[1]), suffix = match[2];
                if (operation === 'delete') {
                    if (idx === globalIdx) return;
                    if (idx > globalIdx) idx--;
                } else {
                    if (idx >= globalIdx) idx++;
                }
                newVoltas[idx + suffix] = val;
            });
            currentParsed.voltaEndings = newVoltas;
        }

        // Reindex per-measure voicing override keys (ChordName@measureIdx.chordIdx)
        if (currentParsed.chordVoicings) {
            var newVoicings = {};
            $.each(currentParsed.chordVoicings, function(key, val) {
                var atPos = key.indexOf('@');
                if (atPos === -1) {
                    // Default voicing (chord name only) — keep as-is
                    newVoicings[key] = val;
                    return;
                }
                var chordName = key.substring(0, atPos);
                var suffix = key.substring(atPos + 1); // e.g. "15.0" or "15"
                var dotPos = suffix.indexOf('.');
                var chordSuffix = dotPos !== -1 ? suffix.substring(dotPos) : ''; // ".0" or ""
                var idx = parseInt(suffix);
                if (isNaN(idx)) { newVoicings[key] = val; return; }
                if (operation === 'delete') {
                    if (idx === globalIdx) return; // Remove overrides for deleted measure
                    if (idx > globalIdx) idx--;
                } else {
                    if (idx >= globalIdx) idx++;
                }
                newVoicings[chordName + '@' + idx + chordSuffix] = val;
            });
            currentParsed.chordVoicings = newVoicings;
        }
    }

    function getGlobalMeasureIndex(sectionIdx, localIdx) {
        var g = 0;
        for (var i = 0; i < sectionIdx; i++) g += (currentParsed.sections[i].measures || []).length;
        return g + localIdx;
    }

    /**
     * Ensure a section has an explicit lineBreaks array.
     * If missing, generate one from the current global barsPerRow.
     */
    function ensureLineBreaks(si) {
        var sec = currentParsed.sections[si];
        if (!sec) return;
        if (sec.lineBreaks && sec.lineBreaks.length) return;
        var total = (sec.measures || []).length;
        var bpr = barsPerRow;
        sec.lineBreaks = [];
        for (var i = 0; i < total; i += bpr) {
            sec.lineBreaks.push(Math.min(bpr, total - i));
        }
    }

    // =============================================================================
    // CHORD NAME FORMATTING
    // =============================================================================

    function formatChordNameHtml(name) {
        if (!name) return '';
        var match = name.match(/^([A-G][#b]?)(.*)$/);
        if (!match) return escapeHtml(name);
        var root = match[1].replace('#', '♯').replace('b', '♭');
        var quality = match[2];
        var slashIdx = quality.indexOf('/');
        var bass = '';
        if (slashIdx >= 0) {
            bass = '/' + quality.slice(slashIdx + 1).replace('#', '♯').replace('b', '♭');
            quality = quality.slice(0, slashIdx);
        }
        var display = quality
            .replace('maj7', '△7').replace('min7b5', 'ø7').replace('m7b5', 'ø7')
            .replace('dim7', '°7').replace('dim', '°').replace('min', '–')
            .replace(/^m(\d)/, '–$1').replace(/^m$/, '–').replace('aug', '+');
        return root + '<sup>' + escapeHtml(display) + '</sup>' + bass;
    }

    function escapeHtml(str) {
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function escapeHtmlAttr(str) {
        return escapeHtml(String(str || ''));
    }

    // =============================================================================
    // MINI CHORD DIAGRAM SVG
    // =============================================================================

    function renderMiniDiagram(voicing, size) {
        if (!voicing || !voicing.frets) return '';
        size = size || 52;
        var fretStr = voicing.frets, position = parseInt(voicing.position) || 1;
        var w = size, h = Math.round(size * 1.25);
        var padTop = 12, padSide = 7, padBot = 4;
        var gridW = w - padSide * 2, gridH = h - padTop - padBot;
        var ssp = gridW / 5, fsp = gridH / 4;

        var svg = '<svg class="sbn-ve-chord-svg" width="' + w + '" height="' + h + '" viewBox="0 0 ' + w + ' ' + h + '">';
        // Nut or position marker
        if (position <= 1) {
            svg += '<rect x="' + (padSide - 1) + '" y="' + (padTop - 2.5) + '" width="' + (gridW + 2) + '" height="2.5" fill="#333" rx="0.5"/>';
        } else {
            svg += '<text x="1" y="' + (padTop + fsp * 0.55) + '" font-size="7" fill="#999" font-family="monospace">' + position + '</text>';
        }
        // Fret lines
        for (var f = 0; f <= 4; f++) {
            if (f === 0 && position <= 1) continue;
            svg += '<line x1="' + padSide + '" y1="' + (padTop + f * fsp) + '" x2="' + (w - padSide) + '" y2="' + (padTop + f * fsp) + '" stroke="#ccc" stroke-width="0.7"/>';
        }
        // String lines
        for (var s = 0; s < 6; s++) {
            svg += '<line x1="' + (padSide + s * ssp) + '" y1="' + padTop + '" x2="' + (padSide + s * ssp) + '" y2="' + (padTop + gridH) + '" stroke="#bbb" stroke-width="0.7"/>';
        }
        // Dots — parse hex digits for frets > 9
        fretStr.split('').forEach(function(ch, i) {
            var x = padSide + i * ssp;
            if (ch === 'x') {
                svg += '<text x="' + x + '" y="' + (padTop - 4) + '" text-anchor="middle" font-size="7" fill="#999">×</text>';
            } else if (ch === '0') {
                svg += '<circle cx="' + x + '" cy="' + (padTop - 4) + '" r="2.2" fill="none" stroke="#999" stroke-width="0.8"/>';
            } else {
                var fNum = parseInt(ch, 16); // hex: a=10, b=11, c=12 etc.
                if (isNaN(fNum)) return;
                var adj = fNum - (position > 1 ? position - 1 : 0);
                if (adj >= 1 && adj <= 4) {
                    svg += '<circle cx="' + x + '" cy="' + (padTop + (adj - 0.5) * fsp) + '" r="' + (ssp * 0.34) + '" fill="#e85d3b"/>';
                }
            }
        });
        svg += '</svg>';
        return svg;
    }

    // =============================================================================
    // VISUAL EDITOR — RENDERING
    // =============================================================================

    function renderGrid() {
        if (!currentParsed || !currentParsed.sections) return;
        var $grid = $('#sbnGrid'), html = '', globalIdx = 0;

        currentParsed.sections.forEach(function(section, si) {
            var measures = section.measures || [];
            var isCollapsed = !!collapsedSections[si];

            html += '<div class="sbn-ve-section" data-section="' + si + '">';

            // Section header
            html += '<div class="sbn-ve-section-header' + (isCollapsed ? ' is-collapsed' : '') + '">';
            html += '<button class="sbn-ve-section-collapse' + (isCollapsed ? ' is-collapsed' : '') + '" data-action="toggle-collapse" data-section="' + si + '">▼</button>';
            html += '<input type="text" class="sbn-ve-section-id" value="' + escapeHtmlAttr(section.id) + '" maxlength="1" data-action="section-field" data-section="' + si + '" data-field="id">';
            html += '<input type="text" class="sbn-ve-section-name" value="' + escapeHtmlAttr(section.name) + '" placeholder="Section name" data-action="section-field" data-section="' + si + '" data-field="name">';

            // Section rhythm dropdown
            html += '<select class="sbn-ve-section-rhythm" data-action="section-field" data-section="' + si + '" data-field="rhythmSlug">';
            html += '<option value="">Inherit rhythm</option>';
            $('#sbnRhythm option').each(function() {
                if ($(this).val()) {
                    html += '<option value="' + escapeHtmlAttr($(this).val()) + '"' + ($(this).val() === (section.rhythmSlug || '') ? ' selected' : '') + '>' + escapeHtml($(this).text().trim()) + '</option>';
                }
            });
            html += '</select>';

            // Section tonality override (for modulations)
            html += '<input type="text" class="sbn-ve-section-tonality" value="' + escapeHtmlAttr(section.tonality || '') + '" placeholder="Key" title="Tonality override (e.g. Cm, G, Bbm)" data-action="section-field" data-section="' + si + '" data-field="tonality">';

            html += '<span class="sbn-ve-section-bar-count">' + measures.length + ' bars</span>';
            html += '<button class="sbn-ve-section-add-bar" data-action="add-measure" data-section="' + si + '" title="Add bar">+</button>';
            // Info toggle button

            if (currentParsed.sections.length > 1) {
                html += '<button class="sbn-ve-section-delete" data-action="delete-section" data-section="' + si + '" title="Remove section">×</button>';
            }
            html += '</div>';
            
            // Section info panel (collapsible)


            // Body: collapsed preview or measure grid
            if (isCollapsed) {
                var preview = measures.slice(0, 8).map(function(m) {
                    return '| ' + m.chords.map(function(c) { return c.name; }).join(' ') + ' ';
                }).join('');
                if (measures.length > 8) preview += '| ... +' + (measures.length - 8) + ' more';
                else preview += '|';
                html += '<div class="sbn-ve-section-collapsed">' + escapeHtml(preview) + '</div>';
                globalIdx += measures.length;
            } else {
                // Build row layout from section.lineBreaks or fall back to global barsPerRow
                var sectionHasDense = measures.some(function(m) {
                    return (m.chords || []).length >= 5;
                });
                var defaultBpr = sectionHasDense ? Math.min(barsPerRow, 2) : barsPerRow;

                // Compute row sizes from lineBreaks (or generate default)
                var rowSizes = [];
                if (section.lineBreaks && section.lineBreaks.length) {
                    // Use stored lineBreaks; they must sum to measures.length
                    var sum = 0;
                    section.lineBreaks.forEach(function(n) { rowSizes.push(n); sum += n; });
                    // If measures were added/removed, adjust last row or add new row
                    if (sum < measures.length) {
                        rowSizes.push(measures.length - sum);
                    } else if (sum > measures.length) {
                        // Trim excess from the end
                        var excess = sum - measures.length;
                        while (excess > 0 && rowSizes.length) {
                            var last = rowSizes[rowSizes.length - 1];
                            if (last <= excess) {
                                excess -= last;
                                rowSizes.pop();
                            } else {
                                rowSizes[rowSizes.length - 1] = last - excess;
                                excess = 0;
                            }
                        }
                    }
                } else {
                    // Default: split evenly by defaultBpr
                    for (var i = 0; i < measures.length; i += defaultBpr) {
                        rowSizes.push(Math.min(defaultBpr, measures.length - i));
                    }
                }

                html += '<div class="sbn-ve-section-body' + (sectionHasDense ? ' dense-layout' : '') + '">';
                var mIdx = 0;
                rowSizes.forEach(function(rowLen, rowIdx) {
                    html += '<div class="sbn-ve-row">';
                    for (var c = 0; c < rowLen; c++) {
                        var li = mIdx;
                        if (li < measures.length) {
                            html += renderMeasure(measures[li], li, globalIdx, si);
                            globalIdx++;
                        } else {
                            html += '<div class="sbn-ve-measure is-empty"></div>';
                        }
                        mIdx++;
                    }
                    // Row toolbar: −/+ resize and section split
                    var lastMeasureInRow = mIdx - 1; // local index of last measure in this row
                    var isLastRow = (mIdx >= measures.length);
                    html += '<div class="sbn-ve-row-resize">';
                    html += '<button class="sbn-ve-row-btn" data-action="row-shrink" data-section="' + si + '" data-row="' + rowIdx + '" title="Move last bar to next row">−</button>';
                    html += '<button class="sbn-ve-row-btn" data-action="row-grow" data-section="' + si + '" data-row="' + rowIdx + '" title="Pull next bar into this row">+</button>';
                    // Section split: only show if this row isn't the very last bar of the entire sheet
                    html += '<button class="sbn-ve-row-btn sbn-ve-row-btn-section" data-action="split-section" data-section="' + si + '" data-row="' + rowIdx + '" data-split-after="' + lastMeasureInRow + '" title="Add new section after this row">§</button>';
                    html += '</div>';
                    html += '</div>';
                });
                html += '</div>';
            }
            html += '</div>';
        });
        $grid.html(html);
    }

    function renderMeasure(measure, localIdx, globalIdx, si) {
        var chords = measure.chords || [], isMulti = chords.length > 1;
        var isDense = chords.length >= 5;  // Dense mode for 5+ chords (chord melody)
        var isFromTab = !!measure._fromTab;

        // Check repeat/volta status for this global index
        var rm = currentParsed.repeatMarkers || {};
        var ve = currentParsed.voltaEndings || {};
        var hasRepStart = !!(rm[globalIdx] && rm[globalIdx].start);
        var hasRepEnd = !!(rm[globalIdx] && rm[globalIdx].end);
        // Check volta — keys can be "6" or "6_start" or "6_stop"
        var voltaInfo = null;
        Object.keys(ve).forEach(function(k) {
            var idx = parseInt(k);
            if (idx === globalIdx) voltaInfo = ve[k];
        });

        var classes = 'sbn-ve-measure';
        if (hasRepStart) classes += ' has-rep-start';
        if (hasRepEnd) classes += ' has-rep-end';
        if (voltaInfo) classes += ' has-volta';
        if (isDense) classes += ' is-dense';
        if (isFromTab) classes += ' from-tab';
        if (isMeasureSelected(si, localIdx)) classes += ' is-selected';

        var html = '<div class="' + classes + '" data-section="' + si + '" data-measure="' + localIdx + '" data-global="' + globalIdx + '">';

        // Volta bracket indicator
        if (voltaInfo) {
            var voltaNum = voltaInfo.number || voltaInfo.text || '';
            html += '<div class="sbn-ve-volta">' + escapeHtml(String(voltaNum)) + '.</div>';
        }

        html += '<div class="sbn-ve-measure-num">' + (globalIdx + 1) + '</div>';

        // Tab-extracted badge
        if (isFromTab) {
            html += '<div class="sbn-ve-tab-badge" title="Auto-detected from tab">TAB</div>';
        }

        // Repeat sign indicators
        if (hasRepStart) html += '<div class="sbn-ve-rep-sign rep-start">𝄆</div>';
        if (hasRepEnd) html += '<div class="sbn-ve-rep-sign rep-end">𝄇</div>';

        html += '<div class="sbn-ve-measure-content">';

        chords.forEach(function(chord, ci) {
            // Voicing lookup: per-measure override first (ChordName@globalIdx.chordIdx), then default (ChordName)
            var overrideKey = chord.name + '@' + globalIdx + '.' + ci;
            var v = null;
            if (currentParsed.chordVoicings) {
                v = currentParsed.chordVoicings[overrideKey] || currentParsed.chordVoicings[chord.name] || null;
            }

            // Tiered diagram sizing: normal=64, multi=48, dense=32
            var ds = isDense ? 32 : (isMulti ? 48 : 64);

            var chordClasses = 'sbn-ve-chord';
            if (isMulti) chordClasses += ' multi';
            if (isDense) chordClasses += ' dense';
            if (pasteTarget && pasteTarget.si === si && pasteTarget.mi === localIdx && pasteTarget.ci === ci) {
                chordClasses += ' is-paste-target';
            }

            html += '<div class="' + chordClasses + '" data-chord-idx="' + ci + '" data-section="' + si + '" data-measure="' + localIdx + '">';
            html += '<div class="sbn-ve-chord-name" data-action="edit-chord" data-section="' + si + '" data-measure="' + localIdx + '" data-chord="' + ci + '">' + formatChordNameHtml(chord.name) + '</div>';

            // data-voicing-key: ChordName@globalIdx for per-measure override
            var vKey = overrideKey;
            if (v) {
                html += '<div class="sbn-ve-chord-diagram" data-action="pick-voicing" data-chord-name="' + escapeHtmlAttr(chord.name) + '" data-voicing-key="' + escapeHtmlAttr(vKey) + '">' + renderMiniDiagram(v, ds) + '</div>';
            } else {
                html += '<div class="sbn-ve-chord-diagram empty" data-action="pick-voicing" data-chord-name="' + escapeHtmlAttr(chord.name) + '" data-voicing-key="' + escapeHtmlAttr(vKey) + '">🎸</div>';
            }

            // Beat dots — skip in dense mode to save space
            if (!isDense) {
                html += '<div class="sbn-ve-beats">';
                for (var b = 0; b < Math.round(chord.beats); b++) html += '<div class="sbn-ve-beat-dot"></div>';
                html += '</div>';
            }
            html += '</div>';
        });

        html += '</div>';

        // Hover actions — raise chord limit for tab-extracted measures
        var maxChords = isFromTab ? 12 : 4;
        html += '<div class="sbn-ve-measure-actions">';
        html += '<button class="sbn-ve-measure-action action-insert-bar" data-action="insert-measure-after" data-section="' + si + '" data-measure="' + localIdx + '" title="Insert empty bar after this one">+bar</button>';
        html += '<button class="sbn-ve-measure-action action-repeat" data-action="toggle-repeat" data-section="' + si + '" data-measure="' + localIdx + '" data-global="' + globalIdx + '" title="Toggle repeat signs">𝄆𝄇</button>';
        if (chords.length < maxChords) html += '<button class="sbn-ve-measure-action action-add" data-action="add-chord" data-section="' + si + '" data-measure="' + localIdx + '">+chord</button>';
        if (chords.length > 1) html += '<button class="sbn-ve-measure-action action-remove" data-action="remove-chord" data-section="' + si + '" data-measure="' + localIdx + '">−chord</button>';
        if ((currentParsed.sections[si].measures || []).length > 1) html += '<button class="sbn-ve-measure-action action-delete" data-action="delete-measure" data-section="' + si + '" data-measure="' + localIdx + '">×</button>';
        html += '</div></div>';
        return html;
    }

    function renderVoicingTags() {
        if (!currentParsed) return;
        var html = '';
        getUniqueChords().forEach(function(name) {
            var has = !!(currentParsed.chordVoicings && currentParsed.chordVoicings[name]);
            html += '<button class="sbn-ve-voicing-tag' + (has ? ' has-voicing' : '') + '" data-action="pick-voicing" data-chord-name="' + escapeHtmlAttr(name) + '">';
            html += formatChordNameHtml(name);
            html += '<span class="tag-indicator">' + (has ? '✓' : '+') + '</span></button>';
        });
        $('#sbnVoicingTags').html(html);
    }

    function updateStats() {
        if (!currentParsed) return;
        var total = currentParsed.sections.reduce(function(s, sec) { return s + (sec.measures || []).length; }, 0);
        var unique = getUniqueChords();
        var repeatCount = currentParsed.repeatMarkers ? Object.keys(currentParsed.repeatMarkers).length : 0;
        var voltaCount = currentParsed.voltaEndings ? Object.keys(currentParsed.voltaEndings).length : 0;
        var stats = total + ' bars · ' + unique.length + ' chords';
        if (repeatCount) stats += ' · ' + repeatCount + ' repeats';
        if (voltaCount) stats += ' · ' + voltaCount + ' endings';
        $('#sbnStats').text(stats);
    }

    function getUniqueChords() {
        if (!currentParsed) return [];
        var set = {};
        currentParsed.sections.forEach(function(s) {
            (s.measures || []).forEach(function(m) {
                m.chords.forEach(function(c) { set[c.name] = true; });
            });
        });
        return Object.keys(set);
    }

    // =============================================================================
    // VOICING PRUNING — remove entries for chords no longer in the sheet
    // =============================================================================

    function pruneOrphanedVoicings() {
        if (!currentParsed || !currentParsed.chordVoicings) return;
        var activeChords = {};
        var activeOverrides = {};
        var globalIdx = 0;
        currentParsed.sections.forEach(function(s) {
            (s.measures || []).forEach(function(m) {
                (m.chords || []).forEach(function(c, ci) {
                    activeChords[c.name] = true;
                    activeOverrides[c.name + '@' + globalIdx + '.' + ci] = true;
                });
                globalIdx++;
            });
        });
        Object.keys(currentParsed.chordVoicings).forEach(function(key) {
            if (key.indexOf('@') !== -1) {
                if (!activeOverrides[key]) delete currentParsed.chordVoicings[key];
            } else {
                if (!activeChords[key]) delete currentParsed.chordVoicings[key];
            }
        });
    }

    // =============================================================================
    // UPDATE OUTPUT
    // =============================================================================

    function updateOutput() {
        if (!currentParsed) return;
        currentParsed.title = $('#sbnTitle').val();
        currentParsed.composer = $('#sbnComposer').val();
        currentParsed.key = $('#sbnKey').val();
        currentParsed.tempo = parseInt($('#sbnTempo').val()) || 120;
        currentParsed.timeSignature = $('#sbnTime').val();
        currentParsed.measures = [];
        currentParsed.sections.forEach(function(s) {
            (s.measures || []).forEach(function(m) { currentParsed.measures.push(m); });
        });

        // Prune orphaned voicings: remove entries for chords no longer in the sheet
        pruneOrphanedVoicings();

        var shortcode = generateShortcode(currentParsed, {
            tempo: currentParsed.tempo,
            rhythm: $('#sbnRhythm').val() || '',
            includeMelody: $('#sbnIncludeMelody').is(':checked'),
            melodyDisplay: $('#sbnMelodyDisplay').val() || 'both'
        });
        $('#sbnShortcodeOutput').val(shortcode);
    }

    function fullRefresh() {
        renderGrid();
        renderVoicingTags();
        updateStats();
        updateOutput();
    }

    // =============================================================================
    // EDIT MODE INIT — parse existing shortcode back into currentParsed
    // =============================================================================

    function initializeFromExistingData() {
        var output = $('#sbnShortcodeOutput').val();
        if (!output || output.indexOf('[sbn_leadsheet') === -1) return;

        // Extract time signature from shortcode attributes for correct beat calculation
        var tsMatch = output.match(/time="([^"]+)"/);
        var timeSig = tsMatch ? tsMatch[1] : ($('#sbnTime').val() || '4/4');
        var tsParts = timeSig.split('/');
        var beatsInBar = parseInt(tsParts[0]) || 4;

        currentParsed = {
            title: $('#sbnTitle').val() || '',
            composer: $('#sbnComposer').val() || '',
            key: $('#sbnKey').val() || 'C',
            tempo: parseInt($('#sbnTempo').val()) || 120,
            timeSignature: timeSig,
            measures: [],
            sections: [],
            chordVoicings: {},
            melody: null,
            repeatMarkers: null,
            voltaEndings: null
        };

        // Also sync the time field in case it differs
        $('#sbnTime').val(timeSig);

        var curSec = null;
        var curSecRowBarsCount = 0;
        var curSecInferredBreaks = [];
        output.split('\n').forEach(function(line) {
            var t = line.trim();
            // Section marker: [A label="Intro" rhythm="bossa-nova" breaks="4,4,3"]
            var sm = t.match(/^\[([A-Z])\s*(.*?)\]\s*$/);
            if (sm) {
                // Finalize previous section: apply inferred lineBreaks if no explicit ones
                if (curSec) {
                    if (curSecRowBarsCount > 0) curSecInferredBreaks.push(curSecRowBarsCount);
                    if (!curSec.lineBreaks && curSecInferredBreaks.length) {
                        curSec.lineBreaks = curSecInferredBreaks;
                    }
                    currentParsed.sections.push(curSec);
                }
                var lbl = sm[1], rs = null, secBreaks = null;
                var lm = sm[2].match(/label="([^"]*)"/); if (lm) lbl = lm[1];
                var rm = sm[2].match(/rhythm="([^"]*)"/); if (rm) rs = rm[1];
                var bkm = sm[2].match(/breaks="([^"]*)"/);
                if (bkm) {
                    secBreaks = bkm[1].split(',').map(function(n) { return parseInt(n) || 0; }).filter(function(n) { return n > 0; });
                    if (!secBreaks.length) secBreaks = null;
                }
                curSec = { id: sm[1], name: lbl, measures: [], rhythmSlug: rs, lineBreaks: secBreaks, tonality: '' };
                // Parse tonality override
                var tm = sm[2].match(/tonality="([^"]*)"/); if (tm) curSec.tonality = tm[1];
                curSecRowBarsCount = 0;
                curSecInferredBreaks = [];
                return;
            }
            // Measure lines: | Cmaj7 | Dm7 G7 | ...
            if (t.startsWith('|') && !t.startsWith('|--')) {
                if (!curSec) {
                    curSec = { id: 'A', name: 'Main', measures: [], rhythmSlug: null, lineBreaks: null, tonality: '' };
                    curSecRowBarsCount = 0;
                    curSecInferredBreaks = [];
                }
                var barsOnThisLine = 0;
                t.split('|').filter(function(m) { return m.trim(); }).forEach(function(ms) {
                    var cn = ms.trim().split(/\s+/).filter(function(c) { return c; });
                    if (cn.length) {
                        curSec.measures.push({
                            chords: cn.map(function(n) { return { name: n, beats: beatsInBar / cn.length }; })
                        });
                        barsOnThisLine++;
                    }
                });
                curSecRowBarsCount += barsOnThisLine;
                // Each newline in the shortcode = end of a visual row
                // (the line we just parsed is one visual row)
                if (barsOnThisLine > 0 && !curSec.lineBreaks) {
                    curSecInferredBreaks.push(barsOnThisLine);
                    curSecRowBarsCount = 0;
                }
            }
        });
        // Finalize last section
        if (curSec && curSec.measures.length) {
            if (!curSec.lineBreaks && curSecInferredBreaks.length) {
                curSec.lineBreaks = curSecInferredBreaks;
            }
            currentParsed.sections.push(curSec);
        }

        // Flatten measures
        currentParsed.sections.forEach(function(s) {
            (s.measures || []).forEach(function(m) { currentParsed.measures.push(m); });
        });

        // Parse voicings block
        var vm = output.match(/\[sbn_voicings\]([\s\S]*?)\[\/sbn_voicings\]/);
        if (vm) {
            vm[1].split('\n').filter(function(l) { return l.trim(); }).forEach(function(l) {
                // Split on ": " (colon-space) to handle override keys that contain colons
                // e.g. "Am7@5.0: x32010 @3 (102043)" or legacy "Am7@5:0: x32010"
                var sepIdx = l.indexOf(': ');
                if (sepIdx === -1) return;
                var key = l.substring(0, sepIdx).trim();
                var rest = l.substring(sepIdx + 2).trim();
                var m = rest.match(/^([x0-9a-f]+)(?:\s*@(\d+))?(?:\s*\(([0-9]+)\))?/i);
                if (!m) return;
                var frets = m[1];
                // Discard old #X.Y format entries (legacy corruption)
                if (/^#\d+\.\d+$/.test(key)) {
                    console.warn('[SBN VE] Discarding legacy #X.Y voicing key:', key);
                    return;
                }
                // Migrate legacy colon-based override keys to dot format
                // e.g. "Am7@5:0" → "Am7@5.0"
                key = key.replace(/(@\d+):(\d+)$/, '$1.$2');
                // Validate: frets must be exactly 6 chars
                if (frets.length !== 6 || !/^[x0-9a-f]{6}$/i.test(frets)) {
                    console.warn('[SBN VE] Discarding corrupted voicing on load:', key, frets);
                    return;
                }
                currentParsed.chordVoicings[key] = {
                    frets: frets,
                    position: m[2] ? parseInt(m[2]) : 1,
                    fingers: m[3] || '000000'
                };
            });
        }

        // Parse melody
        var mm = output.match(/\[sbn_melody\]([\s\S]*?)\[\/sbn_melody\]/);
        if (mm) try { currentParsed.melody = JSON.parse(mm[1].trim()); } catch(e) {}

        // Parse repeats
        var rp = output.match(/\[sbn_repeats\]([\s\S]*?)\[\/sbn_repeats\]/);
        if (rp) try { currentParsed.repeatMarkers = JSON.parse(rp[1].trim()); } catch(e) {}

        // Parse endings
        var ve = output.match(/\[sbn_endings\]([\s\S]*?)\[\/sbn_endings\]/);
        if (ve) try { currentParsed.voltaEndings = JSON.parse(ve[1].trim()); } catch(e) {}

        // Parse [sbn_info] block — restore description
        var infoMatch = output.match(/\[sbn_info\]([\s\S]*?)\[\/sbn_info\]/);
        if (infoMatch) {
            var dm = infoMatch[1].match(/\[description\]([\s\S]*?)\[\/description\]/);
            if (dm && !$('#sbnDescription').val()) $('#sbnDescription').val(dm[1].trim());
        }

        // Prune any orphaned voicing entries that may have been saved in a previous
        // version of the shortcode (before the pruning fix was added).
        pruneOrphanedVoicings();

        console.log('[SBN VE] Initialized:', currentParsed.sections.length, 'sections,', currentParsed.measures.length, 'bars');
    }

    // =============================================================================
    // UI HELPERS
    // =============================================================================

    function showToast(message, type) {
        var toast = $('<div class="sbn-toast ' + (type || '') + '">' + message + '</div>');
        $('body').append(toast);
        setTimeout(function() { toast.fadeOut(300, function() { $(this).remove(); }); }, 3000);
    }

    function showEditor() {
        $('#sbnVisualEditor').show();
        $('#sbnSaveLeadsheet').prop('disabled', false);

        // ── Sync header fields from parsed data ─────────────────────────
        // On fresh XML import, the form fields still hold server-side defaults
        // (e.g. key="C"). Push the parsed values into the inputs BEFORE
        // fullRefresh() → updateOutput() reads them back, otherwise the
        // parsed key/tempo/etc. get silently overwritten by the defaults.
        if (currentParsed) {
            if (currentParsed.title)         $('#sbnTitle').val(currentParsed.title);
            if (currentParsed.composer)       $('#sbnComposer').val(currentParsed.composer);
            if (currentParsed.key)            $('#sbnKey').val(currentParsed.key);
            if (currentParsed.tempo)          $('#sbnTempo').val(currentParsed.tempo);
            if (currentParsed.timeSignature)  $('#sbnTime').val(currentParsed.timeSignature);
        }

        // Show melody bar if melody present
        if (currentParsed && currentParsed.melody && currentParsed.melody.length) {
            var tabNotes = currentParsed.melody.filter(function(n) {
                return n.string !== null && n.fret !== null && !n.isRest;
            });
            var text = currentParsed.melody.length + ' melody notes';
            if (tabNotes.length) text += ' (' + tabNotes.length + ' with tab)';
            $('#sbnMelodyText').text(text);
            $('#sbnMelodyBar').show();
        }
        fullRefresh();
    }

    // =============================================================================
    // REVERSE VOICING LOOKUP (Tab extraction → real chord names)
    // =============================================================================

    /**
     * Call the PHP reverse-lookup endpoint with all Tab1/Tab2/... voicings.
     * On success, renames them throughout currentParsed (chordVoicings + all measures)
     * and re-renders the editor grid.
     *
     * @param {Object} tabVoicings  { "Tab1": { frets, position }, ... }
     */
    function identifyTabVoicings(tabVoicings) {
        if (!currentParsed || !tabVoicings) return;
        if (typeof sbnLeadsheet === 'undefined' || !sbnLeadsheet.identifyNonce) return;

        // Build request payload — only send Tab-prefixed names
        var payload = [];
        Object.keys(tabVoicings).forEach(function(name) {
            if (/^Tab\d+$/.test(name)) {
                var v = tabVoicings[name];
                payload.push({ frets: v.frets, position: v.position || 1 });
            }
        });

        if (!payload.length) return;

        console.log('[SBN Admin] Identify: sending', payload.length, 'tab voicings for identification...');

        $.post(sbnLeadsheet.ajaxUrl, {
            action: 'sbn_identify_voicings',
            nonce: sbnLeadsheet.identifyNonce,
            voicings: JSON.stringify(payload)
        }, function(resp) {
            if (!resp.success || !resp.data || !resp.data.length) return;

            // Build a map: fretsString → identified result
            var identified = {};
            resp.data.forEach(function(r) {
                if (r.name) identified[r.frets] = r;
            });

            // Build rename map: oldName (Tab1) → newName (Cm7(9))
            var renameMap = {};
            Object.keys(tabVoicings).forEach(function(tabName) {
                if (!/^Tab\d+$/.test(tabName)) return;
                var v = tabVoicings[tabName];
                var match = identified[v.frets];
                if (!match) return;

                // Multiple Tab shapes that identify as the same chord name (e.g. two
                // different voicings of Cm7(9)) all get that same name. The chord name
                // is the musical identity; the fret shape is stored separately in
                // chordVoicings and each chord event carries its own voicing reference.
                renameMap[tabName] = match.name;
            });

            if (!Object.keys(renameMap).length) return;

            console.log('[SBN Admin] Identify: renaming', renameMap);

            // Apply renames to chordVoicings.
            // Multiple Tab shapes may identify as the same chord name (e.g. a
            // full 4-string Am7 drop2 and a 3-string partial both → "Am7").
            // When that happens, keep the FULLER shape (more fretted strings)
            // as the default voicing in chordVoicings. Each chord event in
            // the measures still carries its own per-instance voicing data.
            if (currentParsed.chordVoicings) {
                Object.keys(renameMap).forEach(function(oldName) {
                    var newName = renameMap[oldName];
                    if (currentParsed.chordVoicings[oldName]) {
                        var incoming = currentParsed.chordVoicings[oldName];
                        var existing = currentParsed.chordVoicings[newName];
                        if (existing) {
                            // Collision: same chord name from different shapes.
                            // Keep whichever has more fretted strings.
                            var incomingCount = (incoming.frets || '').replace(/x/g, '').length;
                            var existingCount = (existing.frets || '').replace(/x/g, '').length;
                            if (incomingCount > existingCount) {
                                currentParsed.chordVoicings[newName] = incoming;
                            }
                            // Either way, remove the old Tab entry
                        } else {
                            currentParsed.chordVoicings[newName] = incoming;
                        }
                        delete currentParsed.chordVoicings[oldName];
                    }
                });
            }

            // Apply renames to all chord events in all sections/measures
            if (currentParsed.sections) {
                currentParsed.sections.forEach(function(section) {
                    (section.measures || []).forEach(function(measure) {
                        (measure.chords || []).forEach(function(chord) {
                            if (renameMap[chord.name]) {
                                chord.name = renameMap[chord.name];
                            }
                        });
                    });
                });
            }

            // Rebuild per-measure voicing overrides after rename.
            // When multiple Tab shapes map to the same chord name (e.g. two
            // different Dm7 voicings), the default in chordVoicings holds the
            // fullest shape. Chord events whose per-instance voicing differs
            // from the default need override entries (name@measureIdx) so the
            // renderer shows the correct shape for each occurrence.
            if (currentParsed.sections && currentParsed.chordVoicings) {
                var globalIdx = 0;
                currentParsed.sections.forEach(function(section) {
                    (section.measures || []).forEach(function(measure) {
                        (measure.chords || []).forEach(function(chord, ci) {
                            if (!chord.voicing) return;
                            var defaultV = currentParsed.chordVoicings[chord.name];
                            if (!defaultV) return;
                            if (chord.voicing.frets !== defaultV.frets ||
                                chord.voicing.position !== defaultV.position) {
                                var overrideKey = chord.name + '@' + globalIdx + '.' + ci;
                                currentParsed.chordVoicings[overrideKey] = chord.voicing;
                            }
                        });
                        globalIdx++;
                    });
                });
            }

            // Re-render the editor grid so renamed chords show immediately
            fullRefresh();
            showToast('Identified ' + Object.keys(renameMap).length + ' chord voicing(s) from tab data', 'success');
        }).fail(function() {
            console.warn('[SBN Admin] Identify: AJAX failed');
        });
    }

    // =============================================================================
    // INTERACTIVE DIAGRAM EDITOR
    // =============================================================================

    var _diagEditorState = null; // { frets: "3x443x", position: 1, chordName, voicingKey }
    var _diagEditorDebounce = null;

    /**
     * Build the interactive SVG diagram editor HTML.
     * Returns an HTML string to inject into the modal.
     *
     * @param {string} frets     6-char fret string (index 0 = low E)
     * @param {number} position  Starting fret (1 = includes nut)
     * @param {string} chordName Chord name for display
     * @returns {string} HTML
     */
    function buildDiagramEditor(frets, position, chordName) {
        var W = 210, H = 180;
        var padLeft = 22, padTop = 28, padRight = 10, padBot = 18;
        var gridW = W - padLeft - padRight;
        var gridH = H - padTop - padBot;
        var ssp = gridW / 5;   // string spacing
        var fsp = gridH / 4;   // fret spacing (4 frets visible)
        var strings = ['E','A','D','G','B','e'];

        var svg = '<svg id="sbnDiagEditor" class="sbn-diag-editor-svg" width="' + W + '" height="' + H + '" viewBox="0 0 ' + W + ' ' + H + '" data-frets="' + escapeHtmlAttr(frets) + '" data-position="' + position + '">';

        // String labels
        strings.forEach(function(s, i) {
            svg += '<text x="' + (padLeft + i * ssp) + '" y="' + (padTop - 10) + '" text-anchor="middle" font-size="9" fill="#aaa" font-family="sans-serif">' + s + '</text>';
        });

        // Position label (left side)
        if (position > 1) {
            svg += '<text x="' + (padLeft - 4) + '" y="' + (padTop + fsp * 0.6) + '" text-anchor="end" font-size="9" fill="#888" font-family="monospace">' + position + '</text>';
        }

        // Nut or top fret line
        if (position <= 1) {
            svg += '<rect x="' + (padLeft - 1) + '" y="' + (padTop - 3) + '" width="' + (gridW + 2) + '" height="3" fill="#333" rx="0.5"/>';
        } else {
            svg += '<line x1="' + padLeft + '" y1="' + padTop + '" x2="' + (padLeft + gridW) + '" y2="' + padTop + '" stroke="#ccc" stroke-width="0.8"/>';
        }

        // Fret lines
        for (var f = 1; f <= 4; f++) {
            svg += '<line x1="' + padLeft + '" y1="' + (padTop + f * fsp) + '" x2="' + (padLeft + gridW) + '" y2="' + (padTop + f * fsp) + '" stroke="#ddd" stroke-width="0.7"/>';
        }

        // String lines
        for (var s = 0; s < 6; s++) {
            var sx = padLeft + s * ssp;
            svg += '<line x1="' + sx + '" y1="' + padTop + '" x2="' + sx + '" y2="' + (padTop + gridH) + '" stroke="#bbb" stroke-width="0.8"/>';
        }

        // Invisible hit targets above nut (for open/mute toggle)
        for (var s = 0; s < 6; s++) {
            var sx = padLeft + s * ssp;
            svg += '<rect class="sbn-diag-hit-mute" x="' + (sx - ssp * 0.45) + '" y="2" width="' + (ssp * 0.9) + '" height="' + (padTop - 5) + '" fill="transparent" data-string="' + s + '" style="cursor:pointer"/>';
        }

        // Clickable grid cells (frets 1-4 × 6 strings)
        for (var f = 1; f <= 4; f++) {
            for (var s = 0; s < 6; s++) {
                var cx = padLeft + s * ssp;
                var cy = padTop + (f - 0.5) * fsp;
                svg += '<rect class="sbn-diag-hit-fret" x="' + (cx - ssp * 0.45) + '" y="' + (padTop + (f-1) * fsp + 1) + '" width="' + (ssp * 0.9) + '" height="' + (fsp - 2) + '" fill="transparent" data-string="' + s + '" data-fret-offset="' + f + '" style="cursor:pointer"/>';
            }
        }

        // Dots and mute/open symbols from current fret string
        frets.split('').forEach(function(ch, i) {
            var sx = padLeft + i * ssp;
            if (ch === 'x') {
                svg += '<text x="' + sx + '" y="' + (padTop - 5) + '" text-anchor="middle" font-size="9" fill="#cc4444" font-family="sans-serif">✕</text>';
            } else if (ch === '0') {
                svg += '<circle cx="' + sx + '" cy="' + (padTop - 5) + '" r="3.5" fill="none" stroke="#aaa" stroke-width="1.2"/>';
            } else {
                var fNum = parseInt(ch, 16);
                if (!isNaN(fNum)) {
                    var adj = fNum - (position > 1 ? position - 1 : 0);
                    if (adj >= 1 && adj <= 4) {
                        svg += '<circle cx="' + sx + '" cy="' + (padTop + (adj - 0.5) * fsp) + '" r="' + (ssp * 0.34) + '" fill="#e85d3b" class="sbn-diag-dot" data-string="' + i + '"/>';
                    }
                }
            }
        });

        svg += '</svg>';

        // Position control
        var posHtml = '<div class="sbn-diag-pos-row">'
            + '<label>Position: <input type="number" id="sbnDiagPosition" class="sbn-diag-pos-input" value="' + position + '" min="1" max="20" step="1"></label>'
            + '</div>';

        // Chord identification status
        var identHtml = '<div id="sbnDiagIdentified" class="sbn-diag-identified">Analyzing…</div>';

        return '<div class="sbn-diag-editor">'
            + '<div class="sbn-diag-editor-header">Edit detected shape'
            + (chordName ? ' <span class="sbn-diag-editor-for">for <strong>' + escapeHtml(chordName) + '</strong></span>' : '')
            + '</div>'
            + '<div class="sbn-diag-editor-body">'
            +   svg
            +   '<div class="sbn-diag-editor-right">'
            +     posHtml
            +     '<div class="sbn-diag-frets-display"><code id="sbnDiagFretsDisplay">' + escapeHtml(frets) + '</code></div>'
            +     identHtml
            +     '<div class="sbn-diag-editor-actions">'
            +       '<button class="button button-primary button-small" id="sbnDiagConfirm">Use this shape</button>'
            +       '<button class="button button-small" id="sbnDiagSaveNew">Save as new voicing</button>'
            +     '</div>'
            +   '</div>'
            + '</div>'
            + '</div>';
    }

    /**
     * Attach click handlers to the interactive diagram editor SVG.
     * Must be called after buildDiagramEditor() HTML is in the DOM.
     */
    function bindDiagramEditorEvents() {
        var $svg = $('#sbnDiagEditor');
        if (!$svg.length) return;

        // Click on fret cell → toggle dot
        $svg.on('click', '.sbn-diag-hit-fret', function() {
            var strIdx    = parseInt($(this).data('string'));
            var fretOff   = parseInt($(this).data('fret-offset'));
            var state     = _diagEditorState;
            var pos       = state.position;
            var actualFret = fretOff + (pos > 1 ? pos - 1 : 0);
            var frets     = state.frets.split('');
            var ch        = frets[strIdx];
            var chFret    = (ch !== 'x' && ch !== '0') ? parseInt(ch, 16) : -1;

            if (chFret === actualFret) {
                // Remove dot → mute
                frets[strIdx] = 'x';
            } else {
                // Place dot
                frets[strIdx] = actualFret <= 9 ? String(actualFret) : actualFret.toString(16);
            }
            _diagEditorUpdateFrets(frets.join(''), pos);
        });

        // Click above nut → cycle: sounding → open → muted
        $svg.on('click', '.sbn-diag-hit-mute', function() {
            var strIdx = parseInt($(this).data('string'));
            var frets  = _diagEditorState.frets.split('');
            var ch     = frets[strIdx];
            if (ch === 'x') {
                frets[strIdx] = '0';  // muted → open
            } else if (ch === '0') {
                frets[strIdx] = 'x';  // open → muted
            } else {
                frets[strIdx] = 'x';  // fretted → muted
            }
            _diagEditorUpdateFrets(frets.join(''), _diagEditorState.position);
        });

        // Position input → scroll the grid window
        $('#sbnDiagPosition').on('change input', function() {
            var pos = Math.max(1, parseInt($(this).val()) || 1);
            _diagEditorUpdateFrets(_diagEditorState.frets, pos);
        });

        // Confirm button → assign the current shape to the voicing slot
        $('#sbnDiagConfirm').on('click', function() {
            var state = _diagEditorState;
            if (!state) return;
            var assignKey = currentVoicingKey || currentVoicingChord;
            if (!currentParsed.chordVoicings) currentParsed.chordVoicings = {};
            currentParsed.chordVoicings[assignKey] = { frets: state.frets, position: state.position };
            $('#sbnVoicingModal').hide();
            fullRefresh();
        });

        // Save as new voicing
        $('#sbnDiagSaveNew').on('click', function() {
            var state = _diagEditorState;
            if (!state) return;
            var $idDiv = $('#sbnDiagIdentified');
            var suggestedName = $idDiv.data('chord-name') || currentVoicingChord || '';
            var name = window.prompt('Chord name for this voicing:', suggestedName);
            if (!name || !name.trim()) return;
            name = name.trim();
            _diagEditorSaveNew(name, state.frets, state.position);
        });
    }

    /**
     * Update diagram editor state, re-render SVG, re-run identification.
     */
    function _diagEditorUpdateFrets(newFrets, newPosition) {
        _diagEditorState.frets    = newFrets;
        _diagEditorState.position = newPosition;

        // Refresh SVG (rebuild the whole editor in-place)
        var $editor = $('.sbn-diag-editor');
        var chordName = _diagEditorState.chordName;
        $editor.replaceWith(buildDiagramEditor(newFrets, newPosition, chordName));
        bindDiagramEditorEvents();

        // Update frets display
        $('#sbnDiagFretsDisplay').text(newFrets + (newPosition > 1 ? ' @' + newPosition : ''));

        // Debounced identification
        clearTimeout(_diagEditorDebounce);
        _diagEditorDebounce = setTimeout(function() {
            _diagEditorRunIdentify(newFrets, newPosition);
        }, 350);
    }

    /**
     * Run reverse-lookup for the editor and update the identification badge.
     */
    function _diagEditorRunIdentify(frets, position) {
        if (typeof sbnLeadsheet === 'undefined' || !sbnLeadsheet.identifyNonce) return;
        var payload = JSON.stringify([{ frets: frets, position: position }]);
        $.post(sbnLeadsheet.ajaxUrl, {
            action: 'sbn_identify_voicings',
            nonce: sbnLeadsheet.identifyNonce,
            voicings: payload
        }, function(resp) {
            var $div = $('#sbnDiagIdentified');
            if (!$div.length) return;
            if (resp.success && resp.data && resp.data[0] && resp.data[0].name) {
                var r = resp.data[0];
                $div.html('Identified: <strong>' + escapeHtml(r.name) + '</strong>'
                    + ' <span class="sbn-diag-conf sbn-diag-conf--' + r.confidence + '">' + r.confidence + '</span>');
                $div.data('chord-name', r.name);
            } else {
                $div.html('<span class="sbn-diag-conf--none">No chord identified</span>');
                $div.data('chord-name', '');
            }
        });
    }

    /**
     * Save a diagram editor shape as a new chord diagram via AJAX.
     */
    function _diagEditorSaveNew(chordName, frets, position) {
        if (typeof sbnLeadsheet === 'undefined' || !sbnLeadsheet.chordDiagramsNonce) {
            showToast('Missing chord diagram save nonce — please reload', 'error');
            return;
        }

        // Parse root + quality from chord name
        var rootMatch   = chordName.match(/^([A-G][#b]?)(.*)/);
        var rootNote    = rootMatch ? rootMatch[1] : chordName;
        var quality     = rootMatch ? rootMatch[2] : '';

        // Convert fret string to diagram_data
        var diagramData = fretsStringToDiagramData(frets, position);

        $.post(sbnLeadsheet.ajaxUrl, {
            action: 'sbn_save_chord_diagram',
            nonce: sbnLeadsheet.chordDiagramsNonce,
            name: chordName,
            root_note: rootNote,
            quality: quality,
            extensions: '',
            start_fret: position,
            diagram_data: JSON.stringify(diagramData),
            voicing_category: '',
            root_string: '',
            inversion: 'root'
        }, function(resp) {
            if (resp.success) {
                // Assign new diagram to the voicing slot
                var assignKey = currentVoicingKey || currentVoicingChord;
                if (!currentParsed.chordVoicings) currentParsed.chordVoicings = {};
                currentParsed.chordVoicings[assignKey] = { frets: frets, position: position };
                $('#sbnVoicingModal').hide();
                fullRefresh();
                showToast('Voicing saved as "' + chordName + '"', 'success');
            } else {
                showToast('Failed to save voicing: ' + (resp.data || 'unknown error'), 'error');
            }
        }).fail(function() {
            showToast('Network error while saving voicing', 'error');
        });
    }

    /**
     * Convert a fret string back to diagram_data (inverse of diagramDataToFrets).
     * String numbering: internal 1 = low E, index 0 = low E, so string = i + 1.
     */
    function fretsStringToDiagramData(frets, position) {
        var positions = [], muted = [], open = [];
        for (var i = 0; i < 6; i++) {
            var ch = frets[i] || 'x';
            var s = i + 1; // internal string number
            if (ch === 'x') {
                muted.push(s);
            } else if (ch === '0') {
                open.push(s);
            } else {
                var fret = parseInt(ch, 16);
                if (!isNaN(fret)) {
                    positions.push({ string: s, fret: fret });
                }
            }
        }
        return { positions: positions, barres: [], muted: muted, open: open };
    }

    // =============================================================================
    // VOICING PICKER MODAL
    // =============================================================================

    var currentVoicingChord = null;
    var currentVoicingKey = null; // null = by chord name, "5:0" = per-instance (globalIdx:chordIdx)

    function openVoicingModal(chordName, voicingKey) {
        currentVoicingChord = chordName;
        currentVoicingKey = voicingKey; // null = assign to chord name, "Gm7@13" = per-measure override
        var lookupKey = voicingKey || chordName;
        var barLabel = '';
        if (voicingKey && voicingKey.indexOf('@') !== -1) {
            var mIdx = parseInt(voicingKey.split('@')[1]);
            barLabel = ' <span style="font-size:12px;color:#999;font-weight:400">bar ' + (mIdx + 1) + '</span>';
        }
        $('#sbnModalChordName').html(formatChordNameHtml(chordName) + barLabel);
        $('#sbnModalRemove').toggle(!!(currentParsed.chordVoicings && currentParsed.chordVoicings[lookupKey]));
        $('#sbnModalCount').text('');
        $('#sbnVoicingModal').show();

        // Resolve the voicing: per-measure override key first, then global chord name.
        // Tab-imported voicings are stored under chordName only (no @idx suffix), so
        // we need both lookups or the editor won't appear on first click.
        var existingVoicing = currentParsed.chordVoicings
            ? (currentParsed.chordVoicings[lookupKey] || currentParsed.chordVoicings[chordName] || null)
            : null;
        var editorHtml = '';
        if (existingVoicing && existingVoicing.frets) {
            _diagEditorState = {
                frets: existingVoicing.frets,
                position: existingVoicing.position || 1,
                chordName: chordName,
                voicingKey: voicingKey
            };
            editorHtml = buildDiagramEditor(existingVoicing.frets, existingVoicing.position || 1, chordName);
            editorHtml += '<div class="sbn-diag-editor-divider">— Or pick from library —</div>';
        } else {
            _diagEditorState = null;
        }

        $('#sbnModalBody').html(editorHtml + '<div class="sbn-ve-modal-loading">🔍 Searching voicing database...</div>');

        if (_diagEditorState) {
            bindDiagramEditorEvents();
            // Run initial identification
            _diagEditorRunIdentify(_diagEditorState.frets, _diagEditorState.position);
        }

        $.post(sbnLeadsheet.ajaxUrl, {
            action: 'sbn_search_chords',
            nonce: sbnLeadsheet.chordSearchNonce,
            query: chordName
        }, function(resp) {
            // Replace only the loading placeholder (keep the editor above it)
            var $loading = $('#sbnModalBody .sbn-ve-modal-loading');
            if (resp.success && resp.data && resp.data.results && resp.data.results.length) {
                var $grid = $('<div>');
                $grid.html(''); // will be populated by renderVoicingResults
                $loading.replaceWith('<div id="sbnVoicingResultsContainer"></div>');
                renderVoicingResults(resp.data.results);
            } else {
                $loading.replaceWith(
                    '<div class="sbn-ve-modal-empty">' +
                    '<div class="sbn-ve-modal-empty-icon">📭</div>' +
                    'No voicings found for ' + escapeHtml(chordName) + '.' +
                    '<div class="sbn-ve-modal-empty-hint">Add voicings in the Chord Diagrams admin page, or use the editor above to save a new one.</div>' +
                    '</div>'
                );
                $('#sbnModalCount').text('0 voicings');
            }
        }).fail(function() {
            $('#sbnModalBody .sbn-ve-modal-loading').replaceWith('<div class="sbn-ve-modal-empty">Error searching voicings.</div>');
        });
    }

    /**
     * Convert diagram_data {positions, barres, muted, open} → frets string like "x32010"
     * String numbering: 1=low E ... 6=high E → frets string index 0=low E, 5=high E
     */
    function diagramDataToFrets(dd) {
        if (!dd) return '';
        // Initialize all 6 strings as muted
        var result = ['x','x','x','x','x','x'];

        // Mark open strings (fret 0)
        if (dd.open) {
            dd.open.forEach(function(s) {
                if (s >= 1 && s <= 6) result[s - 1] = '0';
            });
        }

        // Place fretted positions
        if (dd.positions) {
            dd.positions.forEach(function(p) {
                if (p.string >= 1 && p.string <= 6 && p.fret > 0) {
                    var f = p.fret;
                    // Use hex for frets > 9
                    result[p.string - 1] = f <= 9 ? String(f) : f.toString(16);
                }
            });
        }

        // Barres also indicate fretted strings
        if (dd.barres) {
            dd.barres.forEach(function(b) {
                var from = Math.min(b.fromString, b.toString);
                var to = Math.max(b.fromString, b.toString);
                for (var s = from; s <= to; s++) {
                    if (s >= 1 && s <= 6 && result[s - 1] === 'x') {
                        var f = b.fret;
                        result[s - 1] = f <= 9 ? String(f) : f.toString(16);
                    }
                }
            });
        }

        // Muted strings stay as 'x' (already default)
        return result.join('');
    }

    function renderVoicingResults(results) {
        // Check instance-level voicing first, then chord-name level
        var lookupKey = currentVoicingKey || currentVoicingChord;
        var cur = currentParsed.chordVoicings ? (currentParsed.chordVoicings[lookupKey] || currentParsed.chordVoicings[currentVoicingChord]) : null;
        var html = '<div class="sbn-ve-voicing-grid">';
        results.forEach(function(v) {
            var frets = '', pos = parseInt(v.start_fret) || 1, fingers = '000000';

            // Parse diagram_data to extract frets string
            if (v.diagram_data) {
                try {
                    var dd = typeof v.diagram_data === 'string' ? JSON.parse(v.diagram_data) : v.diagram_data;
                    frets = diagramDataToFrets(dd);
                } catch(e) {
                    console.warn('[SBN VE] Failed to parse diagram_data:', e);
                }
            }

            // Skip if we couldn't extract any fret data
            if (!frets) return;

            var isSel = cur && frets === cur.frets && parseInt(pos) === parseInt(cur.position);

            html += '<div class="sbn-ve-voicing-card' + (isSel ? ' is-selected' : '') + '" data-frets="' + escapeHtmlAttr(frets) + '" data-position="' + pos + '" data-fingers="' + escapeHtmlAttr(fingers) + '">';
            if (isSel) html += '<div class="check-mark">✓</div>';
            html += renderMiniDiagram({ frets: frets, position: parseInt(pos) }, 58);
            html += '<div class="sbn-ve-voicing-category">' + escapeHtml(v.voicing_category || '') + '</div>';
            html += '<div class="sbn-ve-voicing-detail">' + escapeHtml((v.inversion === 'root' ? 'Root pos.' : (v.inversion || ''))) + (v.root_string ? ' · ' + v.root_string : '') + '</div>';
            html += '<div class="sbn-ve-voicing-frets">' + escapeHtml(frets) + (parseInt(pos) > 1 ? ' @' + pos : '') + '</div>';
            html += '</div>';
        });
        html += '</div>';
        // If the diagram editor is present, insert results into its container div;
        // otherwise replace the entire modal body (original behaviour).
        var $container = $('#sbnVoicingResultsContainer');
        if ($container.length) {
            $container.html(html);
        } else {
            $('#sbnModalBody').html(html);
        }
        $('#sbnModalCount').text(results.length + ' voicing' + (results.length !== 1 ? 's' : ''));
    }

    // =============================================================================
    // CHORD PICKER POPUP
    // =============================================================================

    var chordPickerTarget = null;
    var ROOTS = ['C','C#','Db','D','D#','Eb','E','F','F#','Gb','G','G#','Ab','A','A#','Bb','B'];
    var QUALITIES = [
        { label: '△7', val: 'maj7' }, { label: '7', val: '7' }, { label: '–7', val: 'm7' },
        { label: 'ø7', val: 'm7b5' }, { label: '°7', val: 'dim7' }, { label: '6', val: '6' },
        { label: '–6', val: 'm6' }, { label: '9', val: '9' }, { label: '–9', val: 'm9' },
        { label: 'sus4', val: 'sus4' }, { label: '+', val: 'aug' }, { label: '–', val: 'm' },
        { label: 'maj', val: '' }
    ];

    function openChordPicker(sectionIdx, measureIdx, chordIdx, anchorEl) {
        chordPickerTarget = { si: sectionIdx, mi: measureIdx, ci: chordIdx };
        var chord = currentParsed.sections[sectionIdx].measures[measureIdx].chords[chordIdx];
        var $picker = $('#sbnChordPicker');
        var $input = $('#sbnChordInput');
        $input.val(chord.name);

        // Position near the clicked element
        var rect = anchorEl.getBoundingClientRect();
        var top = Math.min(rect.bottom + 4, window.innerHeight - 300);
        var left = Math.max(8, Math.min(rect.left - 40, window.innerWidth - 290));
        $picker.css({ top: top + 'px', left: left + 'px' }).show();
        $input.focus().select();
        updatePickerButtons(chord.name);
    }

    function updatePickerButtons(value) {
        var curRoot = (value.match(/^([A-G][#b]?)/) || ['', ''])[1];
        var curQual = (value.match(/^[A-G][#b]?(.*)/) || ['', ''])[1];

        var rootHtml = '';
        ROOTS.forEach(function(r) {
            rootHtml += '<button class="sbn-ve-picker-btn' + (curRoot === r ? ' is-active' : '') + '" data-root="' + r + '">' + r + '</button>';
        });
        $('#sbnRootButtons').html(rootHtml);

        var qualHtml = '';
        QUALITIES.forEach(function(q) {
            qualHtml += '<button class="sbn-ve-picker-btn' + (curQual === q.val ? ' is-active' : '') + '" data-quality="' + q.val + '">' + q.label + '</button>';
        });
        $('#sbnQualityButtons').html(qualHtml);
    }

    function closeChordPicker() {
        $('#sbnChordPicker').hide();
        chordPickerTarget = null;
    }

    function applyChordPicker() {
        if (!chordPickerTarget) return;
        var name = $('#sbnChordInput').val().trim();
        if (!name || !name.match(/^[A-G]/)) return;
        var t = chordPickerTarget;
        currentParsed.sections[t.si].measures[t.mi].chords[t.ci].name = name;
        closeChordPicker();
        fullRefresh();
    }

    // =============================================================================
    // FILE PROCESSING
    // =============================================================================

    function processFile(file) {
        var fileName = file.name.toLowerCase();
        if (!fileName.endsWith('.xml') && !fileName.endsWith('.musicxml')) {
            if (fileName.endsWith('.mxl')) {
                showToast('Compressed .mxl not yet supported. Use .xml or .musicxml', 'error');
                return;
            }
            showToast('Please upload a .xml or .musicxml file', 'error');
            return;
        }
        var reader = new FileReader();
        reader.onload = function(e) {
            try {
                var parser = new MusicXMLParser(e.target.result);
                currentParsed = parser.parse();
                showEditor();
                var msg = 'Parsed: ' + currentParsed.measures.length + ' bars';
                if (currentParsed.melody && currentParsed.melody.length) {
                    msg += ', ' + currentParsed.melody.length + ' melody notes';
                }
                showToast(msg, 'success');
            } catch (err) {
                console.error('[SBN VE] Parse error:', err);
                showToast('Error: ' + err.message, 'error');
            }
        };
        reader.onerror = function() { showToast('Error reading file', 'error'); };
        reader.readAsText(file);
    }

    // =============================================================================
    // EVENT BINDINGS
    // =============================================================================

    $(document).ready(function() {

        // === EDIT MODE INIT ===
        if ($('#sbnLeadsheetId').val()) {
            initializeFromExistingData();
            if (currentParsed) showEditor();
        }

        // === FILE UPLOAD ===
        var $upload = $('#sbnUploadArea'), $fileInput = $('#sbnFileInput');
        if ($upload.length) {
            $upload.on('click', function(e) { e.preventDefault(); $fileInput[0].click(); });
            $upload.on('dragover dragenter', function(e) { e.preventDefault(); $(this).addClass('drag-over'); });
            $upload.on('dragleave dragend drop', function(e) { e.preventDefault(); $(this).removeClass('drag-over'); });
            $upload.on('drop', function(e) {
                e.preventDefault();
                var f = e.originalEvent.dataTransfer.files;
                if (f && f.length) processFile(f[0]);
            });
            $fileInput.on('change', function() { if (this.files && this.files.length) processFile(this.files[0]); });
        }

        // === HEADER FIELD CHANGES ===
        $('#sbnTitle, #sbnComposer, #sbnKey, #sbnTempo, #sbnTime, #sbnRhythm').on('change input', function() {
            if (currentParsed) updateOutput();
        });
        $('#sbnIncludeMelody, #sbnMelodyDisplay').on('change', function() {
            if (currentParsed) updateOutput();
        });
        $('#sbnBarsPerRow').on('change', function() {
            barsPerRow = parseInt($(this).val()) || 4;
            // Clear all per-section lineBreaks so they regenerate from the new global
            if (currentParsed && currentParsed.sections) {
                currentParsed.sections.forEach(function(sec) { delete sec.lineBreaks; });
            }
            if (currentParsed) { renderGrid(); updateOutput(); }
        });

        // === SHORTCODE PANEL TOGGLE ===
        $('#sbnToggleShortcode').on('click', function() {
            var $panel = $('#sbnShortcodePanel');
            var show = !$panel.is(':visible');
            $panel.toggle(show);
            $(this).text(show ? 'Hide Shortcode' : 'View Shortcode');
        });

        // Copy shortcode
        $('#sbnCopyOutput').on('click', function() {
            navigator.clipboard.writeText($('#sbnShortcodeOutput').val()).then(function() {
                showToast('Copied!', 'success');
            });
        });

        // =================================================================
        // DELEGATED EVENTS ON GRID
        // =================================================================

        // Chord-level click: set paste target (for chord-level paste)
        // Clicking a chord div (not its name/diagram) marks it as the paste target
        $('#sbnGrid').on('click', '.sbn-ve-chord', function(e) {
            // Only set paste target on direct chord area clicks, not on buttons/name/diagram
            if ($(e.target).closest('[data-action], .sbn-ve-chord-name, .sbn-ve-chord-diagram').length) return;
            e.stopPropagation();
            var $chord = $(this);
            var $measure = $chord.closest('.sbn-ve-measure');
            var si = parseInt($measure.data('section'));
            var mi = parseInt($measure.data('measure'));
            var ci = parseInt($chord.data('chord-idx'));
            if (isNaN(si) || isNaN(mi) || isNaN(ci)) return;

            // Toggle: click same chord again to deselect
            if (pasteTarget && pasteTarget.si === si && pasteTarget.mi === mi && pasteTarget.ci === ci) {
                pasteTarget = null;
            } else {
                pasteTarget = { si: si, mi: mi, ci: ci };
            }
            // Clear measure selection when setting chord paste target
            selectedMeasures = [];
            renderGrid();
            updateClipboardToolbar();
        });

        // Measure selection: click = select one, Shift+click = range, Ctrl/Cmd+click = toggle
        $('#sbnGrid').on('click', '.sbn-ve-measure', function(e) {
            // Don't select when clicking on action buttons, chord names, diagrams, inputs, chord areas
            if ($(e.target).closest('[data-action], input, select, .sbn-ve-chord-name, .sbn-ve-chord-diagram, .sbn-ve-chord, .sbn-ve-measure-action, .sbn-ve-section-header').length) return;

            var si = parseInt($(this).data('section'));
            var mi = parseInt($(this).data('measure'));
            if (isNaN(si) || isNaN(mi)) return;

            // Clear chord-level paste target when selecting measures
            pasteTarget = null;
            $('.sbn-ve-chord').removeClass('is-paste-target');

            if (e.shiftKey && lastClickedMeasure) {
                // Range select from lastClicked to this
                selectRange(lastClickedMeasure.si, lastClickedMeasure.mi, si, mi);
            } else if (e.ctrlKey || e.metaKey) {
                // Toggle this measure
                toggleMeasureSelection(si, mi);
            } else {
                // Solo select
                if (selectedMeasures.length === 1 && isMeasureSelected(si, mi)) {
                    clearSelection();
                    return;
                }
                selectedMeasures = [{ si: si, mi: mi }];
            }
            lastClickedMeasure = { si: si, mi: mi };
            applySelectionClasses();
            updateClipboardToolbar();
        });

        // Clear selection on clicking outside measures
        $('#sbnGrid').on('click', function(e) {
            if (!$(e.target).closest('.sbn-ve-measure').length &&
                !$(e.target).closest('.sbn-ve-row-resize').length) {
                clearSelection();
            }
        });

        // Keyboard shortcuts: Ctrl+C, Ctrl+X, Ctrl+V, Escape, Ctrl+A
        $(document).on('keydown', function(e) {
            if (!currentParsed) return;
            // Don't intercept when typing in inputs/textareas
            if ($(e.target).is('input, textarea, select')) return;

            var isCtrl = e.ctrlKey || e.metaKey;

            if (isCtrl && e.key === 'c') {
                if (selectedMeasures.length) { e.preventDefault(); doCopy(); }
            } else if (isCtrl && e.key === 'x') {
                if (selectedMeasures.length) { e.preventDefault(); doCut(); }
            } else if (isCtrl && e.key === 'v') {
                if (clipboard) { e.preventDefault(); doPaste(); }
            } else if (isCtrl && e.key === 'a') {
                // Select all measures
                e.preventDefault();
                selectedMeasures = [];
                currentParsed.sections.forEach(function(sec, si) {
                    (sec.measures || []).forEach(function(m, mi) {
                        selectedMeasures.push({ si: si, mi: mi });
                    });
                });
                applySelectionClasses();
                updateClipboardToolbar();
            } else if (e.key === 'Escape') {
                clearSelection();
            } else if (e.key === 'Delete' || e.key === 'Backspace') {
                if (selectedMeasures.length) {
                    e.preventDefault();
                    // Delete selected measures (reverse order for valid indices)
                    var sorted = selectedMeasures.slice().sort(function(a, b) {
                        return getGlobalMeasureIndex(b.si, b.mi) - getGlobalMeasureIndex(a.si, a.mi);
                    });
                    sorted.forEach(function(sel) {
                        var sec = currentParsed.sections[sel.si];
                        if (sec && sec.measures && sec.measures.length > 1) {
                            // Adjust lineBreaks
                            if (sec.lineBreaks && sec.lineBreaks.length) {
                                var pos = 0;
                                for (var ri = 0; ri < sec.lineBreaks.length; ri++) {
                                    if (sel.mi < pos + sec.lineBreaks[ri]) {
                                        sec.lineBreaks[ri] -= 1;
                                        if (sec.lineBreaks[ri] <= 0) sec.lineBreaks.splice(ri, 1);
                                        break;
                                    }
                                    pos += sec.lineBreaks[ri];
                                }
                            }
                            var gIdx = getGlobalMeasureIndex(sel.si, sel.mi);
                            sec.measures.splice(sel.mi, 1);
                            reindexMeasureData('delete', gIdx);
                        }
                    });
                    var count = sorted.length;
                    selectedMeasures = [];
                    fullRefresh();
                    showToast(count + ' bar' + (count > 1 ? 's' : '') + ' deleted', 'success');
                }
            }
        });

        // Clipboard toolbar buttons
        $(document).on('click', '#sbnCopyBtn', function() { doCopy(); });
        $(document).on('click', '#sbnCutBtn', function() { doCut(); });
        $(document).on('click', '#sbnPasteBtn', function() { doPaste(); });

        // Section field changes (id, name, rhythm, tonality)
        $('#sbnGrid').on('change input', '.sbn-ve-section-id, .sbn-ve-section-name, .sbn-ve-section-rhythm, .sbn-ve-section-tonality', function() {
            var si = parseInt($(this).data('section'));
            var field = $(this).data('field');
            var val = $(this).val();
            if (field === 'id') val = val.toUpperCase();
            if (field === 'rhythmSlug' && val === '') val = null;
            if (field === 'tonality' && val === '') val = '';
            currentParsed.sections[si][field] = val;
            updateOutput();
        });

        // Section info textarea changes
        $('#sbnGrid').on('input', '.sbn-ve-section-info-textarea', function() {
            var si = parseInt($(this).data('section'));
            var field = $(this).data('field');
            currentParsed.sections[si][field] = $(this).val();
            // Update indicator
            var section = currentParsed.sections[si];
            var hasInfo = !!(section.info || section.harmonyNotes || section.formNotes || section.voicingNotes);
            $(this).closest('.sbn-ve-section').find('.sbn-ve-section-info-btn')
                .toggleClass('has-info', hasInfo);
            updateOutput();
        });

        // Toggle section info panel
        

        // Toggle section collapse
        $('#sbnGrid').on('click', '[data-action="toggle-collapse"]', function() {
            var si = parseInt($(this).data('section'));
            collapsedSections[si] = !collapsedSections[si];
            renderGrid();
        });

        // Add measure to section
        $('#sbnGrid').on('click', '[data-action="add-measure"]', function() {
            var si = parseInt($(this).data('section'));
            var sec = currentParsed.sections[si];
            var last = sec.measures.length ? sec.measures[sec.measures.length - 1] : null;
            var newChord = last ? last.chords[0].name : 'Cmaj7';
            var globalIdx = getGlobalMeasureIndex(si, sec.measures.length);
            sec.measures.push({ chords: [{ name: newChord, beats: 4 }] });
            // Extend lineBreaks: add the new bar to the last row
            if (sec.lineBreaks && sec.lineBreaks.length) {
                sec.lineBreaks[sec.lineBreaks.length - 1] += 1;
            }
            reindexMeasureData('insert', globalIdx);
            fullRefresh();
        });

        // Delete section (moves measures to adjacent section)
        $('#sbnGrid').on('click', '[data-action="delete-section"]', function() {
            var si = parseInt($(this).data('section'));
            if (currentParsed.sections.length <= 1) return;
            if (!confirm('Delete section ' + currentParsed.sections[si].id + '? Measures will merge into adjacent section.')) return;
            var measures = currentParsed.sections[si].measures || [];
            var deletedBreaks = currentParsed.sections[si].lineBreaks || [];
            var target = si > 0 ? si - 1 : 1;
            currentParsed.sections[target].measures = (currentParsed.sections[target].measures || []).concat(measures);
            // Merge lineBreaks if both have them, otherwise reset
            if (currentParsed.sections[target].lineBreaks && deletedBreaks.length) {
                currentParsed.sections[target].lineBreaks = currentParsed.sections[target].lineBreaks.concat(deletedBreaks);
            } else {
                delete currentParsed.sections[target].lineBreaks; // will regenerate from default
            }
            currentParsed.sections.splice(si, 1);
            collapsedSections = {};
            fullRefresh();
        });

        // Add new section at end (kept for the bottom button)
        $('#sbnAddSection').on('click', function() {
            if (!currentParsed) return;
            var used = currentParsed.sections.map(function(s) { return s.id; });
            var letter = 'B';
            'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('').some(function(l) {
                if (!used.includes(l)) { letter = l; return true; }
            });
            currentParsed.sections.push({
                id: letter, name: letter,
                measures: [{ chords: [{ name: 'Cmaj7', beats: 4 }] }],
                rhythmSlug: null, lineBreaks: null
            });
            fullRefresh();
        });

        // Split section: add a new section after the clicked row
        // Measures after that row are moved into the new section
        $('#sbnGrid').on('click', '[data-action="split-section"]', function(e) {
            e.stopPropagation();
            if (!currentParsed) return;
            var si = parseInt($(this).data('section'));
            var splitAfter = parseInt($(this).data('split-after')); // local measure index
            var rowIdx = parseInt($(this).data('row'));
            var sec = currentParsed.sections[si];
            if (!sec) return;

            var totalMeasures = sec.measures.length;
            var cutPoint = splitAfter + 1; // measures from cutPoint onward go to new section

            // Pick next available letter
            var used = currentParsed.sections.map(function(s) { return s.id; });
            var letter = 'B';
            'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('').some(function(l) {
                if (!used.includes(l)) { letter = l; return true; }
            });

            if (cutPoint >= totalMeasures) {
                // Row is the last row — insert an empty new section after this one
                currentParsed.sections.splice(si + 1, 0, {
                    id: letter, name: letter,
                    measures: [{ chords: [{ name: 'Cmaj7', beats: 4 }] }],
                    rhythmSlug: null, lineBreaks: null
                });
            } else {
                // Split: measures before cutPoint stay, the rest move to new section
                var keepMeasures = sec.measures.slice(0, cutPoint);
                var moveMeasures = sec.measures.slice(cutPoint);

                // Split lineBreaks too
                var keepBreaks = null, moveBreaks = null;
                if (sec.lineBreaks && sec.lineBreaks.length) {
                    keepBreaks = sec.lineBreaks.slice(0, rowIdx + 1);
                    moveBreaks = sec.lineBreaks.slice(rowIdx + 1);
                    if (!moveBreaks.length) moveBreaks = null;
                    if (!keepBreaks.length) keepBreaks = null;
                }

                sec.measures = keepMeasures;
                sec.lineBreaks = keepBreaks;

                currentParsed.sections.splice(si + 1, 0, {
                    id: letter, name: letter,
                    measures: moveMeasures,
                    rhythmSlug: sec.rhythmSlug, // inherit rhythm from parent
                    lineBreaks: moveBreaks
                });
            }

            // Shift collapsed-sections map
            var newCollapsed = {};
            Object.keys(collapsedSections).forEach(function(k) {
                var idx = parseInt(k);
                if (idx > si) newCollapsed[idx + 1] = collapsedSections[k];
                else newCollapsed[idx] = collapsedSections[k];
            });
            collapsedSections = newCollapsed;

            fullRefresh();
            showToast('Section ' + letter + ' created after row', 'success');
        });

        // Edit chord name (opens picker)
        $('#sbnGrid').on('click', '[data-action="edit-chord"]', function(e) {
            e.stopPropagation();
            openChordPicker(
                parseInt($(this).data('section')),
                parseInt($(this).data('measure')),
                parseInt($(this).data('chord')),
                this
            );
        });

        // Add chord to measure (split beats)
        $('#sbnGrid').on('click', '[data-action="add-chord"]', function(e) {
            e.stopPropagation();
            var si = parseInt($(this).data('section')), mi = parseInt($(this).data('measure'));
            var m = currentParsed.sections[si].measures[mi];
            var lastName = m.chords[m.chords.length - 1].name;
            var tsBeats = parseInt((currentParsed.timeSignature || '4/4').split('/')[0]) || 4;
            m.chords.push({ name: lastName, beats: tsBeats / (m.chords.length + 1) });
            var beatsEach = tsBeats / m.chords.length;
            m.chords.forEach(function(c) { c.beats = beatsEach; });
            fullRefresh();
        });

        // Remove last chord from measure
        $('#sbnGrid').on('click', '[data-action="remove-chord"]', function(e) {
            e.stopPropagation();
            var si = parseInt($(this).data('section')), mi = parseInt($(this).data('measure'));
            var m = currentParsed.sections[si].measures[mi];
            if (m.chords.length <= 1) return;
            m.chords.pop();
            var tsBeats = parseInt((currentParsed.timeSignature || '4/4').split('/')[0]) || 4;
            var beatsEach = tsBeats / m.chords.length;
            m.chords.forEach(function(c) { c.beats = beatsEach; });
            fullRefresh();
        });

        // Delete measure
        $('#sbnGrid').on('click', '[data-action="delete-measure"]', function(e) {
            e.stopPropagation();
            var si = parseInt($(this).data('section')), mi = parseInt($(this).data('measure'));
            var sec = currentParsed.sections[si];
            if (sec.measures.length <= 1) return;
            // Adjust lineBreaks: find which row this measure is in, shrink it
            if (sec.lineBreaks && sec.lineBreaks.length) {
                var pos = 0;
                for (var ri = 0; ri < sec.lineBreaks.length; ri++) {
                    if (mi < pos + sec.lineBreaks[ri]) {
                        sec.lineBreaks[ri] -= 1;
                        if (sec.lineBreaks[ri] <= 0) sec.lineBreaks.splice(ri, 1);
                        break;
                    }
                    pos += sec.lineBreaks[ri];
                }
            }
            var globalIdx = getGlobalMeasureIndex(si, mi);
            sec.measures.splice(mi, 1);
            reindexMeasureData('delete', globalIdx);
            fullRefresh();
        });

        // Insert empty measure after a specific bar
        $('#sbnGrid').on('click', '[data-action="insert-measure-after"]', function(e) {
            e.stopPropagation();
            var si = parseInt($(this).data('section')), mi = parseInt($(this).data('measure'));
            var sec = currentParsed.sections[si];
            if (!sec) return;
            var tsBeats = parseInt((currentParsed.timeSignature || '4/4').split('/')[0]) || 4;
            var refChord = sec.measures[mi].chords[0].name;
            var insertAt = mi + 1;
            var globalIdx = getGlobalMeasureIndex(si, insertAt);
            sec.measures.splice(insertAt, 0, { chords: [{ name: refChord, beats: tsBeats }] });
            // Adjust lineBreaks: add the new bar to the same row as the clicked measure
            if (sec.lineBreaks && sec.lineBreaks.length) {
                var pos = 0;
                for (var ri = 0; ri < sec.lineBreaks.length; ri++) {
                    if (mi < pos + sec.lineBreaks[ri]) {
                        sec.lineBreaks[ri] += 1;
                        break;
                    }
                    pos += sec.lineBreaks[ri];
                }
            }
            reindexMeasureData('insert', globalIdx);
            fullRefresh();
        });

        // Toggle repeat signs on a measure
        $('#sbnGrid').on('click', '[data-action="toggle-repeat"]', function(e) {
            e.stopPropagation();
            var globalIdx = parseInt($(this).data('global'));
            if (!currentParsed.repeatMarkers) currentParsed.repeatMarkers = {};
            var rm = currentParsed.repeatMarkers;

            // Cycle: none → start → end → start+end → none
            var cur = rm[globalIdx] || {};
            if (!cur.start && !cur.end) {
                rm[globalIdx] = { start: true };
                showToast('Repeat start added (bar ' + (globalIdx + 1) + ')', 'success');
            } else if (cur.start && !cur.end) {
                rm[globalIdx] = { end: true };
                showToast('Repeat end added (bar ' + (globalIdx + 1) + ')', 'success');
            } else if (!cur.start && cur.end) {
                rm[globalIdx] = { start: true, end: true };
                showToast('Repeat start+end (bar ' + (globalIdx + 1) + ')', 'success');
            } else {
                delete rm[globalIdx];
                showToast('Repeat signs removed (bar ' + (globalIdx + 1) + ')', 'success');
            }
            fullRefresh();
        });

        // Row resize: grow (+) pulls next bar into this row
        $('#sbnGrid').on('click', '[data-action="row-grow"]', function(e) {
            e.stopPropagation();
            var si = parseInt($(this).data('section'));
            var rowIdx = parseInt($(this).data('row'));
            var sec = currentParsed.sections[si];
            if (!sec) return;
            // Ensure lineBreaks exists
            ensureLineBreaks(si);
            var lb = sec.lineBreaks;
            // Can only grow if there's a next row to pull from
            if (rowIdx >= lb.length - 1) return;
            if (lb[rowIdx + 1] <= 1) {
                // Next row has only 1 bar — absorb it entirely (remove that row)
                lb[rowIdx] += 1;
                lb.splice(rowIdx + 1, 1);
            } else {
                lb[rowIdx] += 1;
                lb[rowIdx + 1] -= 1;
            }
            renderGrid();
            updateOutput();
        });

        // Row resize: shrink (−) pushes last bar of this row to next row
        $('#sbnGrid').on('click', '[data-action="row-shrink"]', function(e) {
            e.stopPropagation();
            var si = parseInt($(this).data('section'));
            var rowIdx = parseInt($(this).data('row'));
            var sec = currentParsed.sections[si];
            if (!sec) return;
            ensureLineBreaks(si);
            var lb = sec.lineBreaks;
            // Can't shrink below 1 bar
            if (lb[rowIdx] <= 1) return;
            lb[rowIdx] -= 1;
            if (rowIdx + 1 < lb.length) {
                lb[rowIdx + 1] += 1;
            } else {
                // Create a new row with the pushed bar
                lb.push(1);
            }
            renderGrid();
            updateOutput();
        });

        // Pick voicing (from grid diagrams or voicing bar tags)
        $(document).on('click', '[data-action="pick-voicing"]', function(e) {
            e.stopPropagation();
            var chordName = $(this).attr('data-chord-name');
            var voicingKey = $(this).attr('data-voicing-key') || null;
            openVoicingModal(chordName, voicingKey);
        });

        // =================================================================
        // VOICING MODAL EVENTS
        // =================================================================

        // Close on overlay click
        $('#sbnVoicingModal').on('click', function(e) {
            if (e.target === this) $(this).hide();
        });
        $('#sbnModalClose').on('click', function() { $('#sbnVoicingModal').hide(); });

        // Select voicing
        $('#sbnModalBody').on('click', '.sbn-ve-voicing-card', function() {
            if (!currentVoicingChord) return;
            if (!currentParsed.chordVoicings) currentParsed.chordVoicings = {};
            var assignKey = currentVoicingKey || currentVoicingChord;
            currentParsed.chordVoicings[assignKey] = {
                frets: String($(this).attr('data-frets') || ''),
                position: parseInt($(this).attr('data-position')) || 1,
                fingers: String($(this).attr('data-fingers') || '000000')
            };
            // If assigning per-instance and there's also a chord-name level voicing, keep it
            // (per-instance overrides chord-name in renderMeasure)
            $('#sbnVoicingModal').hide();
            fullRefresh();
            var label = currentVoicingKey
                ? currentVoicingChord + ' (bar ' + (parseInt(currentVoicingKey.split('@')[1]) + 1) + ')'
                : currentVoicingChord;
            showToast('Voicing assigned for ' + label, 'success');
        });

        // Remove voicing
        $('#sbnModalRemove').on('click', function() {
            if (!currentVoicingChord) return;
            var removeKey = currentVoicingKey || currentVoicingChord;
            delete currentParsed.chordVoicings[removeKey];
            $('#sbnVoicingModal').hide();
            fullRefresh();
            showToast('Voicing removed', 'success');
        });

        // =================================================================
        // CHORD PICKER EVENTS
        // =================================================================

        // Close picker on outside click
        $(document).on('click', function() { closeChordPicker(); });
        $('#sbnChordPicker').on('click', function(e) { e.stopPropagation(); });

        // Live typing
        $('#sbnChordInput').on('input', function() { updatePickerButtons($(this).val()); });
        $('#sbnChordInput').on('keydown', function(e) {
            if (e.key === 'Enter') applyChordPicker();
            if (e.key === 'Escape') closeChordPicker();
        });

        // Root buttons
        $('#sbnRootButtons').on('click', '.sbn-ve-picker-btn', function() {
            var root = $(this).data('root');
            var curQual = ($('#sbnChordInput').val().match(/^[A-G][#b]?(.*)/) || ['', 'maj7'])[1] || 'maj7';
            $('#sbnChordInput').val(root + curQual);
            updatePickerButtons(root + curQual);
        });

        // Quality buttons
        $('#sbnQualityButtons').on('click', '.sbn-ve-picker-btn', function() {
            var qual = $(this).data('quality');
            var curRoot = ($('#sbnChordInput').val().match(/^([A-G][#b]?)/) || ['', 'C'])[1] || 'C';
            $('#sbnChordInput').val(curRoot + qual);
            updatePickerButtons(curRoot + qual);
        });

        $('#sbnChordCancel').on('click', closeChordPicker);
        $('#sbnChordApply').on('click', applyChordPicker);

        // =================================================================
        // SAVE LEADSHEET
        // =================================================================

        $('#sbnSaveLeadsheet').on('click', function() {
            var $btn = $(this);
            $btn.prop('disabled', true).text('Saving...');
            updateOutput(); // ensure shortcode is fresh

            $.post(sbnLeadsheet.ajaxUrl, {
                action: 'sbn_save_leadsheet',
                nonce: sbnLeadsheet.nonce,
                id: $('#sbnLeadsheetId').val() || 0,
                title: $('#sbnTitle').val(),
                composer: $('#sbnComposer').val(),
                song_key: $('#sbnKey').val(),
                tempo: $('#sbnTempo').val(),
                time_signature: $('#sbnTime').val(),
                rhythm: $('#sbnRhythm').val(),
                course_id: $('#sbnCourse').val(),
                measure_count: currentParsed ? currentParsed.measures.length : 0,
                shortcode_content: $('#sbnShortcodeOutput').val(),
                json_data: currentParsed ? JSON.stringify(currentParsed) : '',
                description: $('#sbnDescription').val() || '',
                harmony_notes: '',
                form_notes: '',
                voicing_notes: ''
            }, function(resp) {
                if (resp.success) {
                    showToast('Leadsheet saved!', 'success');
                    if (!$('#sbnLeadsheetId').val() && resp.data && resp.data.id) {
                        window.location.href = sbnLeadsheet.listUrl;
                    }
                } else {
                    showToast('Error: ' + resp.data, 'error');
                }
                $btn.prop('disabled', false).text($('#sbnLeadsheetId').val() ? 'Update Leadsheet' : 'Save Leadsheet');
            }).fail(function() {
                showToast('Network error saving leadsheet', 'error');
                $btn.prop('disabled', false).text($('#sbnLeadsheetId').val() ? 'Update Leadsheet' : 'Save Leadsheet');
            });
        });

        // =================================================================
        // LIST PAGE EVENTS (delete, copy shortcode)
        // =================================================================

        $('.sbn-delete-leadsheet').on('click', function() {
            if (!confirm('Delete this leadsheet?')) return;
            var $btn = $(this), id = $btn.data('id');
            $.post(sbnLeadsheet.ajaxUrl, {
                action: 'sbn_delete_leadsheet',
                nonce: sbnLeadsheet.nonce,
                id: id
            }, function(resp) {
                if (resp.success) {
                    $btn.closest('tr').fadeOut(300, function() { $(this).remove(); });
                    showToast('Deleted', 'success');
                } else {
                    showToast('Error deleting', 'error');
                }
            });
        });

        $('.sbn-delete-leadsheet').on('click', function() {
            if (!confirm('Delete this leadsheet?')) return;
            var $btn = $(this), id = $btn.data('id');
            $.post(sbnLeadsheet.ajaxUrl, {
                action: 'sbn_delete_leadsheet',
                nonce: sbnLeadsheet.nonce,
                id: id
            }, function(resp) {
                if (resp.success) {
                    $btn.closest('tr').fadeOut(300, function() { $(this).remove(); });
                    showToast('Deleted', 'success');
                } else {
                    showToast('Error deleting', 'error');
                }
            });
        });

        $('.sbn-copy-shortcode').on('click', function() {
            navigator.clipboard.writeText($(this).data('shortcode')).then(function() {
                showToast('Shortcode copied!', 'success');
            });
        });

        // ── Quick-edit description ─────────────────────────────────────────
        $(document).on('click', '.sbn-desc-edit-btn', function() {
            var id = $(this).data('id');
            var $row = $(this).closest('td');
            $row.find('.sbn-desc-text, .sbn-desc-edit-btn').hide();
            $row.find('#sbn-desc-editor-' + id).slideDown(150);
            $row.find('.sbn-desc-textarea').focus();
        });

        $(document).on('click', '.sbn-desc-cancel', function() {
            var id = $(this).data('id');
            var $row = $(this).closest('td');
            $row.find('#sbn-desc-editor-' + id).slideUp(150);
            $row.find('.sbn-desc-text, .sbn-desc-edit-btn').show();
        });

        $(document).on('click', '.sbn-desc-save', function() {
            var id   = $(this).data('id');
            var $row = $(this).closest('td');
            var desc = $row.find('.sbn-desc-textarea').val().trim();
            var $btn = $(this).prop('disabled', true).text('Saving…');
            $.post(sbnLeadsheet.ajaxUrl, {
                action: 'sbn_update_description',
                nonce: sbnLeadsheet.nonce,
                id: id,
                description: desc
            }, function(resp) {
                $btn.prop('disabled', false).text('Save');
                if (resp.success) {
                    var preview = desc ? desc.split(' ').slice(0, 12).join(' ') + (desc.split(' ').length > 12 ? '…' : '') : '';
                    var $text = $row.find('.sbn-desc-text');
                    $text.html(preview || '<span class="sbn-desc-empty">Add info…</span>');
                    $row.find('#sbn-desc-editor-' + id).slideUp(150);
                    $row.find('.sbn-desc-edit-btn').data('desc', desc).show();
                    $text.show();
                    showToast('Saved', 'success');
                } else {
                    showToast('Error saving', 'error');
                }
            });
        });
    });

})(jQuery);
