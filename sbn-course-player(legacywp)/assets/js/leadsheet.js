/**
 * SBN Interactive Leadsheet Player
 * 
 * Vanilla JS component for interactive chord leadsheets with audio playback.
 * Uses Tone.js for audio synthesis.
 * 
 * @package SBN_Course_Player
 * @since 6.5.0
 */

(function() {
    'use strict';

    // ========================================================================
    // AUDIO ENGINE
    // ========================================================================

    // ========================================================================
    // AUDIO ENGINE — thin wrapper around the shared SbnAudio module
    // SbnAudio must be loaded before leadsheet.js (enqueued with dependency).
    // The wrapper keeps all internal call sites unchanged.
    // ========================================================================

    const AudioEngine = {

        get initialized() { return window.SbnAudio ? SbnAudio.initialized : false; },
        // samplerReady kept for UI polling logic — synth is ready instantly
        get samplerReady() { return window.SbnAudio ? SbnAudio.initialized : false; },

        async init() {
            if (!window.SbnAudio) {
                console.warn('[SBN Leadsheet] SbnAudio not loaded');
                return false;
            }
            return SbnAudio.init();
        },

        releaseAll() {
            if (window.SbnAudio) SbnAudio.releaseAll();
        },

        // Rhythm playback
        playBass(voicing) {
            if (voicing && window.SbnAudio) SbnAudio.playBass(voicing.frets);
        },
        playFingers(voicing) {
            if (voicing && window.SbnAudio) SbnAudio.playFingers(voicing.frets);
        },
        playArpeggio(voicing) {
            if (voicing && window.SbnAudio) SbnAudio.playArpeggio(voicing.frets);
        },

        // Tab playback
        playTabNotes(noteNames, duration, time) {
            if (window.SbnAudio) SbnAudio.playNotes(noteNames, duration, time);
        },

        // Pitch helpers used elsewhere in leadsheet
        fretToNote(si, fret) {
            return window.SbnAudio ? SbnAudio.fretToNote(si, fret) : null;
        },
        getNotesFromVoicing(frets) {
            return window.SbnAudio ? SbnAudio.getNotesFromFrets(frets) : [];
        },
        getBassNote(frets) {
            return window.SbnAudio ? SbnAudio.getBassNote(frets) : null;
        },
        getUpperNotes(frets) {
            return window.SbnAudio ? SbnAudio.getUpperNotes(frets) : [];
        }
    };

    // ========================================================================
    // HELPER FUNCTIONS
    // ========================================================================

    function formatChordName(name) {
        // Delegate to shared chord card component if available
        if (typeof SbnChordCard !== 'undefined') {
            return SbnChordCard.formatChordName(name);
        }
        // Inline fallback (keeps leadsheet working if loaded standalone)
        if (!name) return '';
        const match = name.match(/^([A-G])([#♯b♭])?(.*)$/);
        if (!match) return name;

        let [, root, acc, rest] = match;
        acc = (acc || '').replace('#', '♯').replace('b', '♭');
        
        let bass = '';
        const slashIdx = rest.indexOf('/');
        if (slashIdx !== -1) {
            bass = rest.slice(slashIdx).replace('#', '♯').replace('b', '♭');
            rest = rest.slice(0, slashIdx);
        }

        let minor = '';
        if (/^(m(?!aj)|min|-)/.test(rest)) {
            minor = 'm';
            rest = rest.replace(/^(m(?!aj)|min|-)/, '');
        }

        rest = rest.replace(/#/g, '♯').replace(/b(?=\d)/g, '♭')
            .replace(/maj/gi, 'maj').replace(/dim/gi, '°').replace(/aug/gi, '+');

        let html = `<span class="sbn-chord-root">${root}</span>`;
        if (acc) html += `<span class="sbn-chord-accidental">${acc}</span>`;
        if (minor) html += `<span class="sbn-chord-quality">${minor}</span>`;
        if (rest) html += `<sup class="sbn-chord-ext">${rest}</sup>`;
        if (bass) html += `<span class="sbn-chord-bass">${bass}</span>`;

        return html;
    }

    function renderChordDiagram(voicing, size, active, extraOpts) {
        // Delegate to shared chord card component if available
        if (typeof SbnChordCard !== 'undefined') {
            return SbnChordCard.renderDiagram(voicing, Object.assign({
                size: size || 60,
                color: active ? '#e85d3b' : '#555',
                showFingers: true
            }, extraOpts || {}));
        }
        // Return empty if no shared component and no voicing
        if (!voicing || !voicing.frets) return '';
        return '';
    }

    // ========================================================================
    // REPEAT NAVIGATOR
    // ========================================================================
    // Handles repeat signs, volta endings, and section looping.
    // Wraps the linear measure sequence and returns the correct "next measure"
    // accounting for all navigation markers.

    class RepeatNavigator {
        /**
         * @param {object} repeatMarkers - Map of measureIdx → {start: bool, end: bool}
         * @param {object} voltaEndings  - Map of measureIdx → {number, type, text}
         * @param {number} totalMeasures - Total number of measures
         * @param {Array}  sections      - Array of section objects with startMeasure
         */
        constructor(repeatMarkers, voltaEndings, totalMeasures, sections) {
            this.repeatMarkers = repeatMarkers || {};
            this.voltaEndings = voltaEndings || {};
            this.totalMeasures = totalMeasures;
            this.sections = sections || [];
            
            // Pre-compute volta regions for efficient lookup
            this._buildVoltaRegions();
            
            this.reset();
        }
        
        /**
         * Build volta region maps from individual start/stop entries.
         * A "region" is a contiguous range of measures under one volta bracket.
         * 
         * Example voltaEndings input:
         *   { "4": {number:1, type:"start"}, "5": {number:1, type:"stop"},
         *     "6": {number:2, type:"start"}, "7": {number:2, type:"stop"} }
         * 
         * Produces voltaRegions:
         *   [ {number:1, startMeasure:4, endMeasure:5, text:"1."},
         *     {number:2, startMeasure:6, endMeasure:7, text:"2."} ]
         */
        _buildVoltaRegions() {
            this.voltaRegions = [];
            
            // Parse entries — keys can be plain numbers ("4") or composite ("4_stop")
            const entries = Object.keys(this.voltaEndings)
                .map(k => {
                    const measureIdx = parseInt(k); // "4_stop" → 4
                    return { measure: measureIdx, ...this.voltaEndings[k] };
                })
                .sort((a, b) => {
                    if (a.measure !== b.measure) return a.measure - b.measure;
                    // Within same measure, starts before stops
                    if (a.type === 'start' && b.type !== 'start') return -1;
                    if (a.type !== 'start' && b.type === 'start') return 1;
                    return 0;
                });
            
            let currentRegion = null;
            entries.forEach(entry => {
                if (entry.type === 'start') {
                    // Close any previous unclosed region
                    if (currentRegion) {
                        this.voltaRegions.push(currentRegion);
                    }
                    currentRegion = {
                        number: entry.number,
                        startMeasure: entry.measure,
                        endMeasure: entry.measure, // Will be updated on stop
                        text: entry.text || `${entry.number}.`
                    };
                } else if ((entry.type === 'stop' || entry.type === 'discontinue') && currentRegion) {
                    currentRegion.endMeasure = entry.measure;
                    this.voltaRegions.push(currentRegion);
                    currentRegion = null;
                }
            });
            
            // Handle unclosed region (start without stop — single measure volta)
            if (currentRegion) {
                this.voltaRegions.push(currentRegion);
            }
            
            if (this.voltaRegions.length > 0) {
                console.log('[SBN RepeatNav] Volta regions:', this.voltaRegions);
            }
        }

        reset() {
            // Track which repeat-end barlines have been used (each fires once)
            this.repeatsTaken = {};
            // Section loop mode: null = play all, or {sectionIdx, remaining}
            this.sectionLoop = null;
        }

        /**
         * Given the current measure that just finished playing,
         * return the next measure index to play.
         * Returns -1 if the song is complete (no loop).
         * 
         * The volta logic works as follows:
         * 
         * A typical repeat-with-endings structure in MusicXML:
         *   |: m0 | m1 | m2 | m3 |1. m4 | m5 :| 2. m6 | m7 |
         *   
         *   repeatMarkers: { "0": {start:true}, "5": {end:true} }
         *   voltaRegions:  [ {number:1, start:4, end:5}, {number:2, start:6, end:7} ]
         *   
         * Pass 1: 0 → 1 → 2 → 3 → 4 → 5 (repeat-end fires, jump to 0)
         * Pass 2: 0 → 1 → 2 → 3 → (skip volta 1: 4,5) → 6 → 7 → continue
         */
        getNextMeasure(currentMeasure) {
            const nextLinear = currentMeasure + 1;

            // ---- Section loop check ----
            if (this.sectionLoop !== null) {
                const section = this.sections[this.sectionLoop.sectionIdx];
                if (section) {
                    const sectionEnd = section.startMeasure + (section.measures ? section.measures.length : 0);
                    if (nextLinear >= sectionEnd) {
                        if (this.sectionLoop.remaining > 0 || this.sectionLoop.remaining === -1) {
                            if (this.sectionLoop.remaining > 0) this.sectionLoop.remaining--;
                            return section.startMeasure;
                        }
                        this.sectionLoop = null;
                    }
                }
            }

            // ---- Repeat-end barline check ----
            const marker = this.repeatMarkers[currentMeasure.toString()];
            if (marker && marker.end && !this.repeatsTaken[currentMeasure]) {
                // First time hitting this repeat-end → jump back
                this.repeatsTaken[currentMeasure] = true;
                const repeatStart = this.findRepeatStart(currentMeasure);
                console.log(`[SBN RepeatNav] Repeat-end at ${currentMeasure}, jumping to ${repeatStart}`);
                return repeatStart;
            }

            // ---- Volta skip check ----
            // If the next measure is inside a volta 1 region, and we've already
            // taken the repeat that precedes it, skip to volta 2.
            const volta1Region = this._getVoltaRegion(nextLinear, 1);
            if (volta1Region) {
                // Find the repeat-end that is on or within this volta 1 region
                const repeatEndInVolta = this._findRepeatEndInRange(
                    volta1Region.startMeasure, volta1Region.endMeasure
                );
                
                if (repeatEndInVolta !== null && this.repeatsTaken[repeatEndInVolta]) {
                    // We're on the second pass — skip volta 1, jump to volta 2
                    const volta2Region = this._findNextVoltaRegion(volta1Region, 2);
                    if (volta2Region) {
                        console.log(`[SBN RepeatNav] Skipping volta 1 (${volta1Region.startMeasure}-${volta1Region.endMeasure}), jumping to volta 2 at ${volta2Region.startMeasure}`);
                        return volta2Region.startMeasure;
                    }
                    // No volta 2 found — skip past volta 1 entirely
                    console.log(`[SBN RepeatNav] Skipping volta 1, no volta 2 found, jumping to ${volta1Region.endMeasure + 1}`);
                    return volta1Region.endMeasure + 1;
                }
            }

            // ---- Normal linear advance ----
            if (nextLinear >= this.totalMeasures) {
                return -1; // Song complete
            }

            return nextLinear;
        }

        /**
         * Scan backward from a repeat-end to find the matching repeat-start.
         */
        findRepeatStart(repeatEndMeasure) {
            for (let i = repeatEndMeasure - 1; i >= 0; i--) {
                const m = this.repeatMarkers[i.toString()];
                if (m && m.start) return i;
            }
            return 0;
        }

        /**
         * Get the volta region that contains a given measure, filtered by number.
         */
        _getVoltaRegion(measureIdx, number) {
            return this.voltaRegions.find(r => 
                r.number === number && 
                measureIdx >= r.startMeasure && 
                measureIdx <= r.endMeasure
            ) || null;
        }

        /**
         * Find a repeat-end barline within a range of measures.
         */
        _findRepeatEndInRange(startMeasure, endMeasure) {
            for (let i = startMeasure; i <= endMeasure; i++) {
                const m = this.repeatMarkers[i.toString()];
                if (m && m.end) return i;
            }
            return null;
        }

        /**
         * Find the next volta region with a given number, after a reference region.
         * Typically used to find volta 2 after volta 1.
         */
        _findNextVoltaRegion(afterRegion, number) {
            return this.voltaRegions.find(r => 
                r.number === number && 
                r.startMeasure > afterRegion.endMeasure
            ) || null;
        }

        /**
         * Check if a measure is inside any volta region, and return it.
         */
        getVoltaForMeasure(measureIdx) {
            return this.voltaRegions.find(r => 
                measureIdx >= r.startMeasure && 
                measureIdx <= r.endMeasure
            ) || null;
        }

        /**
         * Enable section looping.
         */
        loopSection(sectionIdx, repeatCount = -1) {
            this.sectionLoop = { sectionIdx, remaining: repeatCount };
        }

        /**
         * Disable section looping.
         */
        clearSectionLoop() {
            this.sectionLoop = null;
        }

        /**
         * Check if a section loop is active.
         */
        isLoopingSection() {
            return this.sectionLoop !== null;
        }
        
        /**
         * Get the section index for a given measure.
         */
        getSectionForMeasure(measureIdx) {
            for (let i = this.sections.length - 1; i >= 0; i--) {
                if (measureIdx >= this.sections[i].startMeasure) return i;
            }
            return 0;
        }
    }

    // ========================================================================
    // LEADSHEET PLAYER CLASS
    // ========================================================================

    class SBNLeadsheetPlayer {
        constructor(container) {
            this.container = container;
            this.song = null;
            
            // Parse song data - handle HTML-encoded JSON
            try {
                let rawData = container.dataset.leadsheet || '{}';
                
                // Decode HTML entities if needed (e.g., &quot; -> ")
                // This handles both cases: direct JSON or HTML-escaped JSON
                if (rawData.includes('&')) {
                    const textarea = document.createElement('textarea');
                    textarea.innerHTML = rawData;
                    rawData = textarea.value;
                }
                
                this.song = JSON.parse(rawData);
            } catch (e) {
                console.error('[SBN Leadsheet] Failed to parse song data:', e);
                console.error('[SBN Leadsheet] Raw data:', container.dataset.leadsheet);
                container.innerHTML = '<div class="sbn-leadsheet-error">Error loading leadsheet data. Check browser console.</div>';
                return;
            }

            // State
            this.isPlaying = false;
            this.tempo = this.song.tempo || 120;
            this.currentMeasure = 0;
            this.currentSubBeat = 0;
            this.loopEnabled = true;
            this.audioReady = false;
            this.showDiagrams = false;
            this.showTab = false; // Tab view toggle (integrated below chords)
            this.rhythmCollapsed = false;
            this.selectedChord = null;
            this.chordLibraryCache = {}; // Cache AJAX results by chord name
            this.isFullscreen = false;
            
            // View selector state: 'rhythm', 'fretboard', 'allvoicings', 'info'
            this.activeView = this.song.rhythmPattern ? 'rhythm' : 'fretboard';
            this._voicingExtState = {}; // extension cycling state per archetype key
            
            // Check if we have tab data available
            this.hasTabData = this.checkForTabData();
            
            // Rhythm pattern settings
            // Calculate how many measures the pattern spans based on beats
            // 8 beats = 1 measure, 16 beats = 2 measures, etc.
            this.subbeatsPerMeasure = 8; // Always 8 subbeats per measure
            this.patternBeats = this.song.rhythmPattern ? this.song.rhythmPattern.beats || 8 : 8;
            this.measuresPerPattern = Math.ceil(this.patternBeats / this.subbeatsPerMeasure);
            
            // Playback layer settings
            // Both layers are independent: percussion = backing track, guitar = demonstration
            this.percEnabled   = true;   // percussion layer on/off
            this.guitarEnabled = true;   // guitar synth layer on/off
            this.percVol       = 0.7;    // 0-1
            this.guitarVol     = 0.7;    // 0-1
            this.ghostDensity  = 0.4;    // ghost note density (mirrors rhythm grid default)
            this._percInitialized = false;

            // Fretboard settings
            this.fretboardFrets = 12; // Show 12 frets
            this.fretboardTheme = 'dark'; // 'dark' (ebony) or 'light' (maple/rosewood)
            this.fretboardHighlightStrings = []; // Strings to highlight during playback
            this.guideToneMode = false; // Guide tone visualization overlay
            
            // Melody state
            this.melodyEnabled = !!(this.song.melody && this.song.melody.length > 0);
            this.melodyMuted = false;
            this.currentMelodyIndex = 0;
            this.ticksPerSubBeat = 0; // Will be calculated in playback
            
            // Tab data - organized by measure for rendering
            this.tabData = this.buildTabData();
            this._tabTickMap = null;  // Built lazily on first tab playback

            // Playback
            this.playbackTimer = null;     // Rhythm playback interval
            this.tabPlaybackTimer = null;  // Tab playback interval
            this.tabIsPlaying = false;     // Tab playback active flag
            this.tabCurrentMeasure = 0;    // Tab playback position (measure)
            this.tabCurrentSubBeat = 0;    // Tab playback position (subbeat within measure)
            
            // Bound event handlers (for potential removal later)
            this._boundHandleClick = this.handleClick.bind(this);
            this._boundHandleInput = this.handleInput.bind(this);
            this._boundHandleFullscreenChange = this.handleFullscreenChange.bind(this);

            // Calculate totals
            this.totalMeasures = 0;
            if (this.song.sections) {
                this.song.sections.forEach(s => {
                    this.totalMeasures += s.measures ? s.measures.length : 0;
                });
            }
            
            // Initialize repeat/section navigation
            this.repeatNav = new RepeatNavigator(
                this.song.repeatMarkers || {},
                this.song.voltaEndings || {},
                this.totalMeasures,
                this.song.sections || []
            );
            
            // Section loop state (null = play all, number = looping section index)
            this.loopingSectionIdx = null;
            
            // Collapsed sections state — initialized from section.collapsed flags
            this.collapsedSections = {};
            if (this.song.sections) {
                this.song.sections.forEach((s, i) => {
                    if (s.collapsed) this.collapsedSections[i] = true;
                });
            }
            
            // Section info overlay visibility
            
            // Build melody timeline if melody exists
            if (this.melodyEnabled) {
                this.buildMelodyTimeline();
            }

            // Initial render
            this.render();
            
            // Bind events ONCE - use event delegation on the container
            this.bindEvents();
            
            // Listen for fullscreen changes
            document.addEventListener('fullscreenchange', this._boundHandleFullscreenChange);
            document.addEventListener('webkitfullscreenchange', this._boundHandleFullscreenChange);
            
            console.log('[SBN Leadsheet] Initialized:', this.song.title, this.melodyEnabled ? '(with melody)' : '');
        }
        
        // Build a timeline of melody events mapped to subbeats
        buildMelodyTimeline() {
            if (!this.song.melody || !this.song.melody.length) return;
            
            // Calculate ticks per subbeat
            // Standard: 480 ticks per beat
            // We use 4 subbeats per beat (16th note resolution) to match playback
            // In 4/4: 4 beats × 4 subbeats = 16 subbeats per measure, but we display 8
            // In 2/4: 2 beats × 4 subbeats = 8 subbeats per measure
            const ticksPerBeat = 480;
            
            // Determine subbeats per beat based on time signature
            // 4/4 time: 8 subbeats per measure / 4 beats = 2 subbeats per beat (8th notes)
            // 2/4 time: 8 subbeats per measure / 2 beats = 4 subbeats per beat (16th notes)
            const timeSig = this.song.timeSignature || '4/4';
            const beatsPerMeasure = parseInt(timeSig.split('/')[0]) || 4;
            const subbeatsPerBeat = this.subbeatsPerMeasure / beatsPerMeasure;
            
            this.ticksPerSubBeat = ticksPerBeat / subbeatsPerBeat;
            
            // Map melody events to subbeats
            this.melodyBySubbeat = {};
            
            this.song.melody.forEach((event, idx) => {
                // Skip tieStop notes for playback - they shouldn't sound
                // (they're continuation of a tied note, not a new attack)
                // But they ARE included in the melody array for rendering ties
                if (event.tieStop && !event.tieStart) {
                    return; // Skip pure tieStop notes (not also starting a new tie)
                }
                
                const subbeat = Math.round(event.tick / this.ticksPerSubBeat);
                if (!this.melodyBySubbeat[subbeat]) {
                    this.melodyBySubbeat[subbeat] = [];
                }
                this.melodyBySubbeat[subbeat].push(event);
            });
            
            console.log('[SBN Leadsheet] Melody timeline built:', Object.keys(this.melodyBySubbeat).length, 'subbeats with notes');
            console.log('[SBN Leadsheet] Time sig:', timeSig, 'subbeatsPerBeat:', subbeatsPerBeat, 'ticksPerSubBeat:', this.ticksPerSubBeat);
        }
        
        /**
         * Check if melody has tab data (string/fret info)
         */
        checkForTabData() {
            if (!this.song.melody || !this.song.melody.length) return false;
            return this.song.melody.some(note => note.string !== null && note.fret !== null);
        }
        
        /**
         * Build tab data organized by measure for rendering
         * Returns array of measures, each containing notes with timing and tab positions
         * Preserves voice info for multi-voice support (stems, beaming, playback)
         * Includes tie, beam, and flag information for rhythmic notation
         */
        buildTabData() {
            if (!this.song.melody || !this.song.melody.length) return [];
            
            const ticksPerBeat = 480; // Always based on quarter note
            const timeSig = this.song.timeSignature || '4/4';
            const timeParts = timeSig.split('/');
            const beatsPerMeasure = parseInt(timeParts[0]) || 4;
            const beatType = parseInt(timeParts[1]) || 4;
            
            // Adjust for beat type: 4=quarter, 2=half, 8=eighth
            // ticksPerBeat is per quarter note, so scale by (4/beatType)
            const ticksPerBeatUnit = ticksPerBeat * (4 / beatType);
            const ticksPerMeasure = ticksPerBeatUnit * beatsPerMeasure;
            
            // Duration constants (based on 480 ticks per quarter note)
            const WHOLE = 1920;
            const HALF = 960;
            const QUARTER = 480;
            const EIGHTH = 240;
            const SIXTEENTH = 120;
            const THIRTYSECOND = 60;
            
            // Group notes by measure
            const measureMap = {};
            
            // First pass: create note objects
            const allNotes = [];
            this.song.melody.forEach((note, idx) => {
                const measureIdx = Math.floor(note.tick / ticksPerMeasure);
                const tickInMeasure = note.tick % ticksPerMeasure;
                
                // Handle rests
                if (note.isRest) {
                    const restData = {
                        tick: note.tick,
                        tickInMeasure: tickInMeasure,
                        measureIdx: measureIdx,
                        duration: note.duration,
                        ticks: note.ticks,
                        voice: note.voice || 1,
                        xPos: tickInMeasure / ticksPerMeasure,
                        isRest: true,
                        originalIdx: idx
                    };
                    allNotes.push(restData);
                    return; // continue to next
                }
                
                // Convert MusicXML string (1=high e) to our index (0=low E, 5=high e)
                let stringIdx = null;
                if (note.string !== null) {
                    stringIdx = 6 - note.string;
                }
                
                // Determine flag count based on duration
                // Flags are only for unbeamed notes shorter than quarter
                let flagCount = 0;
                const baseTicks = note.ticks % 60 === 0 ? note.ticks : 
                                  Math.floor(note.ticks / 1.5); // Remove dot for calculation
                if (baseTicks <= SIXTEENTH) flagCount = 2;
                else if (baseTicks <= EIGHTH) flagCount = 1;
                
                const noteData = {
                    tick: note.tick,
                    tickInMeasure: tickInMeasure,
                    measureIdx: measureIdx,
                    pitch: note.pitch,
                    octave: note.octave,
                    duration: note.duration,
                    ticks: note.ticks,
                    string: stringIdx,
                    fret: note.fret,
                    voice: note.voice || 1,
                    stemDir: null, // Will be computed based on voice count
                    xPos: tickInMeasure / ticksPerMeasure,
                    // Rhythmic info
                    flagCount: flagCount,
                    // Tie info from MusicXML (if present)
                    tieStart: note.tieStart || false,
                    tieStop: note.tieStop || false,
                    // Beam info (will be computed)
                    beamStart: false,
                    beamEnd: false,
                    beamContinue: false,
                    beamWith: null, // Reference to next note in beam group
                    isRest: false,
                    originalIdx: idx
                };
                
                allNotes.push(noteData);
            });
            
            // Second pass: compute beam groups
            // Notes are beamed together if they:
            // 1. Are in the same voice
            // 2. Are 8th notes or shorter
            // 3. Fall within the same beat
            // 4. Are adjacent (no rests between)
            this.computeBeamGroups(allNotes, ticksPerBeatUnit, ticksPerMeasure);
            
            // Third pass: compute ties
            // Ties connect notes of the same pitch on the same string
            this.computeTies(allNotes);
            
            // Fourth pass: determine stem direction based on voice count
            // If only one voice, use down stems (standard for tab)
            // If multiple voices, use up for voice 1, down for voice 2+
            const voices = new Set(allNotes.map(n => n.voice));
            const hasMultipleVoices = voices.size > 1;
            
            allNotes.forEach(note => {
                if (hasMultipleVoices) {
                    // Multi-voice: up for voice 1, down for others
                    note.stemDir = note.voice === 1 ? 'up' : 'down';
                } else {
                    // Single voice: always down
                    note.stemDir = 'down';
                }
            });
            
            // Fifth pass: for chord notes (multiple notes at same tick), 
            // consolidate rhythmic notation to one note (the topmost string)
            // This prevents rendering multiple stems/flags/beams for the same chord
            const tickGroups = {};
            allNotes.forEach(note => {
                if (!note.isRest) {
                    const key = `${note.tick}-${note.voice}`;
                    if (!tickGroups[key]) tickGroups[key] = [];
                    tickGroups[key].push(note);
                }
            });
            
            Object.values(tickGroups).forEach(group => {
                if (group.length > 1) {
                    // Multiple notes at same tick - this is a chord
                    // Sort by string (highest string number = highest pitch = first)
                    group.sort((a, b) => b.string - a.string);
                    
                    // Collect ALL beam/flag properties from ANY note in the group
                    let maxFlagCount = 0;
                    let hasBeamStart = false;
                    let hasBeamEnd = false;
                    let hasBeamContinue = false;
                    let beamWithRef = null;
                    
                    group.forEach(note => {
                        if (note.flagCount > maxFlagCount) maxFlagCount = note.flagCount;
                        if (note.beamStart) {
                            hasBeamStart = true;
                            beamWithRef = note.beamWith;
                        }
                        if (note.beamEnd) hasBeamEnd = true;
                        if (note.beamContinue) {
                            hasBeamContinue = true;
                            if (!beamWithRef) beamWithRef = note.beamWith;
                        }
                    });
                    
                    // The topmost string (highest pitch, index 0 after sorting) carries all rhythmic notation
                    const targetNote = group[0];
                    targetNote.flagCount = maxFlagCount;
                    targetNote.beamStart = hasBeamStart;
                    targetNote.beamEnd = hasBeamEnd;
                    targetNote.beamContinue = hasBeamContinue;
                    targetNote.beamWith = beamWithRef;
                    
                    // Clear rhythmic notation from ALL other notes in the chord
                    group.forEach((note, idx) => {
                        if (idx > 0) {
                            note.stemDir = null;
                            note.flagCount = 0;
                            note.beamStart = false;
                            note.beamEnd = false;
                            note.beamContinue = false;
                            note.beamWith = null;
                        }
                    });
                }
            });
            
            // Group by measure
            allNotes.forEach(note => {
                if (!measureMap[note.measureIdx]) {
                    measureMap[note.measureIdx] = [];
                }
                measureMap[note.measureIdx].push(note);
            });
            
            // Build repeat markers map
            // First check if we have repeat markers in the song data (from shortcode)
            if (this.song.repeatMarkers) {
                this.repeatMarkers = this.song.repeatMarkers;
                console.log('[SBN Repeat] Using repeat markers from song data:', this.repeatMarkers);
            } else {
                // Fall back to building from melody notes (for MusicXML import)
                this.repeatMarkers = {};
                allNotes.forEach(note => {
                    if (note.measureRepeatStart && !this.repeatMarkers[note.measureIdx]) {
                        this.repeatMarkers[note.measureIdx] = { start: true };
                        console.log(`[SBN Repeat] Found repeatStart in measure ${note.measureIdx}`);
                    }
                    if (note.measureRepeatEnd) {
                        if (!this.repeatMarkers[note.measureIdx]) {
                            this.repeatMarkers[note.measureIdx] = {};
                        }
                        this.repeatMarkers[note.measureIdx].end = true;
                        console.log(`[SBN Repeat] Found repeatEnd in measure ${note.measureIdx}`);
                    }
                });
            }
            
            console.log('[SBN Repeat] Final repeatMarkers map:', this.repeatMarkers);
            
            // Build volta endings map
            // Check if we have volta endings in the song data (from shortcode)
            if (this.song.voltaEndings) {
                this.voltaEndings = this.song.voltaEndings;
                console.log('[SBN Volta] Using volta endings from song data:', this.voltaEndings);
            } else {
                this.voltaEndings = {};
            }
            
            // Convert to array
            const result = [];
            const maxMeasure = Math.max(...Object.keys(measureMap).map(Number), 0);
            for (let i = 0; i <= maxMeasure; i++) {
                result.push(measureMap[i] || []);
            }
            
            // Log voice distribution for debugging
            const voiceCounts = {};
            const beamCount = allNotes.filter(n => n.beamStart || n.beamContinue).length;
            const tieCount = allNotes.filter(n => n.tieStart).length;
            result.flat().forEach(n => {
                voiceCounts[n.voice] = (voiceCounts[n.voice] || 0) + 1;
            });
            console.log('[SBN Leadsheet] Tab data built:', result.length, 'measures, voices:', voiceCounts, 'beams:', beamCount, 'ties:', tieCount);
            
            return result;
        }
        
        /**
         * Compute beam groups for notes
         * Beams connect 8th notes and shorter within the same beat
         * CRITICAL: Never beam across beat boundaries
         */
        computeBeamGroups(notes, ticksPerBeat, ticksPerMeasure) {
            const EIGHTH = 240;
            
            // Group notes by voice and measure for beaming
            const voiceGroups = {};
            notes.forEach(note => {
                const key = `${note.voice}-${note.measureIdx}`;
                if (!voiceGroups[key]) voiceGroups[key] = [];
                voiceGroups[key].push(note);
            });
            
            // Process each voice-measure group
            Object.values(voiceGroups).forEach(group => {
                // Sort by tick
                group.sort((a, b) => a.tick - b.tick);
                
                // Find beam groups (consecutive 8th notes or shorter within same beat)
                let beamGroup = [];
                
                for (let i = 0; i < group.length; i++) {
                    const note = group[i];
                    const baseTicks = this.getBaseDuration(note.ticks);
                    
                    // Only beam 8th notes and shorter
                    if (baseTicks > EIGHTH) {
                        // End any current beam group
                        this.finalizeBeamGroup(beamGroup);
                        beamGroup = [];
                        continue;
                    }
                    
                    // Calculate which beat this note falls on
                    const currentBeat = Math.floor(note.tickInMeasure / ticksPerBeat);
                    
                    // Check if this note can join the current beam group
                    if (beamGroup.length === 0) {
                        beamGroup.push(note);
                    } else {
                        const lastNote = beamGroup[beamGroup.length - 1];
                        const lastBeat = Math.floor(lastNote.tickInMeasure / ticksPerBeat);
                        
                        // CRITICAL: Notes must be in same beat
                        const sameBeat = lastBeat === currentBeat;
                        
                        // Check if notes are adjacent (no gap)
                        const isAdjacent = note.tick === lastNote.tick + lastNote.ticks;
                        
                        // Additional check: ensure we don't cross beat boundary
                        // The END of the last note must be in the same beat as the START of current note
                        const lastNoteEndBeat = Math.floor((lastNote.tickInMeasure + lastNote.ticks) / ticksPerBeat);
                        const crossesBeatBoundary = lastNoteEndBeat !== currentBeat;
                        
                        if (sameBeat && isAdjacent && !crossesBeatBoundary) {
                            beamGroup.push(note);
                        } else {
                            // End current beam group, start new one
                            this.finalizeBeamGroup(beamGroup);
                            beamGroup = [note];
                        }
                    }
                }
                
                // Finalize any remaining beam group
                this.finalizeBeamGroup(beamGroup);
            });
        }
        
        /**
         * Get base duration (removing any dots)
         */
        getBaseDuration(ticks) {
            // Common dotted durations: 720 (dotted quarter), 360 (dotted eighth), 180 (dotted 16th)
            if (ticks === 720) return 480;
            if (ticks === 360) return 240;
            if (ticks === 180) return 120;
            if (ticks === 1440) return 960;
            return ticks;
        }
        
        /**
         * Finalize a beam group - mark notes appropriately
         */
        finalizeBeamGroup(group) {
            if (group.length < 2) {
                // Single notes keep their flags
                return;
            }
            
            // Mark beam relationships
            for (let i = 0; i < group.length; i++) {
                const note = group[i];
                note.flagCount = 0; // Beamed notes don't have flags
                
                if (i === 0) {
                    note.beamStart = true;
                    note.beamWith = group[i + 1];
                } else if (i === group.length - 1) {
                    note.beamEnd = true;
                } else {
                    note.beamContinue = true;
                    note.beamWith = group[i + 1];
                }
            }
        }
        
        /**
         * Compute ties between notes of same pitch
         * Handles:
         * - Ties within measures
         * - Ties across barlines
         * - Multiple simultaneous ties (chords)
         * Uses ONLY explicit MusicXML tie markers
         */
        computeTies(notes) {
            // Group by voice and string for tie detection
            // Each string in each voice can have its own tie
            const groups = {};
            notes.forEach(note => {
                if (note.string === null) return;
                const key = `${note.voice}-${note.string}`;
                if (!groups[key]) groups[key] = [];
                groups[key].push(note);
            });
            
            // Process each voice-string group independently
            Object.values(groups).forEach(group => {
                // Sort by tick
                group.sort((a, b) => a.tick - b.tick);
                
                // Find ties based on explicit MusicXML markers
                for (let i = 0; i < group.length - 1; i++) {
                    const current = group[i];
                    const next = group[i + 1];
                    
                    // Check if notes are adjacent in timing
                    const isAdjacent = next.tick === current.tick + current.ticks;
                    const sameFret = current.fret === next.fret;
                    
                    // Check for explicit tie markers
                    const hasExplicitTieStart = current.tieStart === true;
                    const hasExplicitTieStop = next.tieStop === true;
                    
                    // Create tie if:
                    // 1. Has explicit tie marker(s)
                    // 2. Notes are the same fret
                    // 3. Notes are roughly adjacent (allow small gaps for rounding)
                    const timingGap = next.tick - (current.tick + current.ticks);
                    const isCloseEnough = Math.abs(timingGap) <= 5; // Allow 5 tick tolerance
                    
                    if ((hasExplicitTieStart || hasExplicitTieStop) && sameFret && isCloseEnough) {
                        current.tieStart = true;
                        current.tieEndNote = next;
                        next.tieStop = true;
                        next.tieStartNote = current;
                    }
                }
            });
        }

        // --------------------------------------------------------------------
        // RENDERING
        // --------------------------------------------------------------------

        render() {
            const song = this.song;
            if (!song || !song.sections) {
                this.container.innerHTML = '<div class="sbn-leadsheet-error">Invalid leadsheet data</div>';
                return;
            }

            // Check if we have any chord measures
            const hasChordMeasures = song.sections.some(s => s.measures && s.measures.length > 0);
            
            // For tab-only files, we can still render if we have tab data
            const isTabOnly = !hasChordMeasures && this.hasTabData;

            // Build CSS classes for the container
            let containerClasses = 'sbn-leadsheet';
            if (this.showDiagrams) containerClasses += ' has-inline-diagrams';
            if (this.showTab && this.hasTabData) containerClasses += ' has-inline-tab';
            if (isTabOnly) containerClasses += ' is-tab-only';
            if (this.isFullscreen) containerClasses += ' is-fullscreen';

            let html = `<div class="${containerClasses}">`;

            // Header
            html += `<div class="sbn-leadsheet-header">
                <div class="sbn-leadsheet-info">
                    <h2 class="sbn-leadsheet-title">${song.title || 'Untitled'}</h2>
                    <div class="sbn-leadsheet-meta">
                        ${song.composer ? `<span class="sbn-leadsheet-composer">${song.composer}</span>` : ''}
                        <span class="sbn-leadsheet-key">${song.key || 'C'}</span>
                        <span class="sbn-leadsheet-time">${song.timeSignature || '4/4'}</span>
                    </div>
                </div>
                <div class="sbn-leadsheet-options">`;
            
            // Diagrams toggle - only show if we have chord measures
            if (hasChordMeasures) {
                html += `
                    <div class="sbn-toggle${this.showDiagrams ? ' is-active' : ''}" data-action="toggle-diagrams">
                        <span>Diagrams</span>
                        <div class="sbn-toggle-switch"></div>
                    </div>`;
            }
            
            // Tab toggle - only show if tab data exists AND we have chord measures (otherwise tab is always shown)
            if (this.hasTabData && hasChordMeasures) {
                html += `
                    <div class="sbn-toggle${this.showTab ? ' is-active' : ''}" data-action="toggle-tab">
                        <span>Tab</span>
                        <div class="sbn-toggle-switch"></div>
                    </div>`;
            }
            
            html += `
                    <button class="sbn-fullscreen-btn" data-action="toggle-fullscreen" title="Fullscreen">
                        ${this.isFullscreen ? '⊠' : '⛶'}
                    </button>
                </div>
            </div>`;

            // Visualization area with view selector (only if we have chord voicings or rhythm)
            if (!isTabOnly) {
                html += this.renderVisualizationArea();
            }

            // Body - either chord grid with optional tab, or tab-only view
            html += '<div class="sbn-leadsheet-body">';
            
            if (isTabOnly) {
                // Tab-only mode: render tab rows directly from tabData
                html += this.renderTabOnlyBody();
            } else {
                // Normal mode: chord grid with optional integrated tab
                let measureIdx = 0;
                song.sections.forEach((section, sectionIdx) => {
                    const isLooping = this.loopingSectionIdx === sectionIdx;
                    const isCollapsed = !!(this.collapsedSections && this.collapsedSections[sectionIdx]);
                    html += `<div class="sbn-leadsheet-section${isLooping ? ' is-looping' : ''}${isCollapsed ? ' is-collapsed' : ''}" data-section="${sectionIdx}">`;
                    
                    // Collect unique detected progressions for this section (needed by both header and collapsed preview)
                    const sectionProgs = (song.detectedProgressions && song.detectedProgressions[section.id])
                        ? song.detectedProgressions[section.id]
                        : [];
                    const uniqueProgs = [];
                    const seenProgIds = new Set();
                    sectionProgs.forEach(p => {
                        if (!seenProgIds.has(p.progression_id)) {
                            seenProgIds.add(p.progression_id);
                            uniqueProgs.push(p);
                        }
                    });
                    const hasProgs = uniqueProgs.length > 0;

                    // Section header — always show if more than one section, or if named
                    const showHeader = song.sections.length > 1 || (section.name && section.name !== 'Main');
                    if (showHeader) {
                        const rhythmBadge = section.rhythmPattern
                            ? '<span class="sbn-leadsheet-section-rhythm">' + (section.rhythmPattern.name || section.rhythmPattern.slug || '') + '</span>'
                            : '';
                        const collapseIcon = isCollapsed ? '&#9654;' : '&#9660;';
                        const progInfoBtn = hasProgs
                            ? '<button class="sbn-leadsheet-section-prog-btn' + (this.sectionProgVisible && this.sectionProgVisible[sectionIdx] ? ' is-active' : '') + '" data-action="toggle-section-prog" data-section-idx="' + sectionIdx + '" title="Chord progressions in this section">&#x1D11E;</button>'
                            : '';

                        html += '<div class="sbn-leadsheet-section-header' + (hasProgs ? ' has-progressions' : '') + '">'
                            + '<button class="sbn-leadsheet-section-collapse-btn' + (isCollapsed ? ' is-collapsed' : '') + '" data-action="toggle-section-collapse" data-section-idx="' + sectionIdx + '" title="' + (isCollapsed ? 'Expand' : 'Collapse') + '">' + collapseIcon + '</button>'
                            + '<span class="sbn-leadsheet-section-id">' + (section.id || '') + '</span>'
                            + '<span class="sbn-leadsheet-section-name">' + (section.name || '') + '</span>'
                            + rhythmBadge
                            + progInfoBtn
                            + '<button class="sbn-leadsheet-section-loop' + (isLooping ? ' is-active' : '') + '" data-action="toggle-section-loop" data-section-idx="' + sectionIdx + '" title="Loop this section">&#x27F3;</button>'
                            + '</div>';
                    }

                    // Inject progression panel if open for this section
                    if (this.sectionProgVisible && this.sectionProgVisible[sectionIdx]) {
                        html += this.renderProgressionPanel(sectionIdx);
                    }

                    const measures = section.measures || [];
                    const measuresPerRow = 4;
                    
                    if (isCollapsed) {
                        // Show collapsed summary
                        const previewChords = measures.slice(0, 8).flatMap(m => m.chords.map(c => c.name));
                        // Build progression pills for collapsed view
                        let collapsedProgPills = '';
                        if (uniqueProgs.length > 0) {
                            const pillsHtml = uniqueProgs.map(p => {
                                const count = sectionProgs.filter(x => x.progression_id === p.progression_id).length;
                                const countBadge = count > 1 ? ' <span class="sbn-prog-pill-count">×' + count + '</span>' : '';
                                const isHighlighted = this.activeProgHighlight &&
                                    this.activeProgHighlight.sectionId === section.id &&
                                    String(this.activeProgHighlight.progressionId) === String(p.progression_id);
                                return '<span class="sbn-prog-pill sbn-prog-cat-' + (p.category || 'jazz') + (isHighlighted ? ' is-highlighted' : '') + '" data-action="highlight-prog" data-section-idx="' + sectionIdx + '" data-section-id="' + this.escapeHtml(section.id) + '" data-prog-id="' + p.progression_id + '" data-prog-cat="' + (p.category || 'jazz') + '" title="Click to highlight measures">' + this.escapeHtml(p.name) + countBadge + '</span>';
                            }).join('');
                            collapsedProgPills = '<span class="sbn-prog-pills-collapsed">' + pillsHtml + '</span>';
                        }
                        html += '<div class="sbn-leadsheet-section-collapsed-preview">' + previewChords.slice(0, 8).join(' &middot; ') + (measures.length > 8 ? ' &hellip;' : '') + ' <span class="sbn-section-bar-count">' + measures.length + ' bars</span>' + collapsedProgPills + '</div>';
                        measureIdx += measures.length;
                    } else {
                    for (let i = 0; i < measures.length; i += measuresPerRow) {
                        const rowStartGlobal = measureIdx;
                        const rowMeasureCount = Math.min(measuresPerRow, measures.length - i);
                        const rowEndGlobal = rowStartGlobal + rowMeasureCount; // exclusive
                        
                        // Check for volta brackets that overlap this row
                        const rowVoltas = this.repeatNav.voltaRegions.filter(v =>
                            v.startMeasure < rowEndGlobal && v.endMeasure >= rowStartGlobal
                        );
                        
                        // Wrap row + volta in a container for proper positioning
                        const hasVoltas = rowVoltas.length > 0;
                        if (hasVoltas) {
                            html += '<div class="sbn-leadsheet-row-with-volta">';
                            // Render volta brackets as absolutely-positioned elements
                            html += '<div class="sbn-volta-bracket-row">';
                            rowVoltas.forEach(volta => {
                                // Calculate which slots in this 4-measure row the volta covers
                                const localStart = Math.max(0, volta.startMeasure - rowStartGlobal);
                                const localEnd = Math.min(measuresPerRow - 1, volta.endMeasure - rowStartGlobal);
                                const leftPercent = (localStart / measuresPerRow) * 100;
                                const widthPercent = ((localEnd - localStart + 1) / measuresPerRow) * 100;
                                
                                // Determine which edges to draw
                                const hasLeftEdge = volta.startMeasure >= rowStartGlobal;
                                const hasRightEdge = volta.endMeasure < rowEndGlobal;
                                
                                let bracketClasses = 'sbn-volta-bracket';
                                if (hasLeftEdge) bracketClasses += ' has-left-edge';
                                if (hasRightEdge) bracketClasses += ' has-right-edge';
                                
                                html += `<div class="${bracketClasses}" style="left:${leftPercent}%;width:${widthPercent}%;">`;
                                if (hasLeftEdge) {
                                    html += `<span class="sbn-volta-text">${volta.text}</span>`;
                                }
                                html += '</div>';
                            });
                            html += '</div>';
                        }
                        
                        html += '<div class="sbn-leadsheet-row">';
                        
                        for (let slot = 0; slot < measuresPerRow; slot++) {
                            const j = i + slot;
                            if (j < measures.length) {
                                html += this.renderMeasure(measures[j], measureIdx++, j, section.id);
                            } else {
                                html += '<div class="sbn-leadsheet-measure is-empty"></div>';
                            }
                        }
                        html += '</div>';
                        
                        if (hasVoltas) {
                            html += '</div>'; // .sbn-leadsheet-row-with-volta
                        }
                        
                        // Render tab row below chord row if tab is enabled
                        if (this.showTab && this.hasTabData) {
                            html += this.renderTabRow(measureIdx - rowMeasureCount, Math.min(measureIdx, this.totalMeasures));
                        }
                    }
                    
                    // If no measures were rendered (measureIdx wasn't incremented by the loop),
                    // make sure we still advance for empty sections
                    if (measures.length === 0) {
                        // nothing to render
                    }
                    
                    } // end else (not collapsed)
                    
                    html += '</div>'; // .sbn-leadsheet-section
                });
            }
            html += '</div>';

            // Controls
            html += this.renderControls();

            // Detail panel
            if (this.selectedChord) {
                html += this.renderDetailPanel();
            }

            html += '</div>';
            this.container.innerHTML = html;
        }
        
        /**
         * Render tab-only body (no chord measures, just tablature)
         */
        renderTabOnlyBody() {
            if (!this.tabData || !this.tabData.length) {
                return '<div class="sbn-tab-empty">No tablature data available</div>';
            }
            
            const measuresPerRow = 4;
            let html = '<div class="sbn-leadsheet-section">';
            
            for (let i = 0; i < this.tabData.length; i += measuresPerRow) {
                const rowEnd = Math.min(i + measuresPerRow, this.tabData.length);
                
                // Render measure numbers row
                html += '<div class="sbn-tab-measure-numbers">';
                for (let j = i; j < i + measuresPerRow; j++) {
                    if (j < this.tabData.length) {
                        html += `<div class="sbn-tab-measure-num-standalone">${j + 1}</div>`;
                    } else {
                        html += '<div class="sbn-tab-measure-num-standalone is-empty"></div>';
                    }
                }
                html += '</div>';
                
                // Render tab row
                html += this.renderTabRow(i, rowEnd);
            }
            
            html += '</div>';
            return html;
        }
        
        /**
         * Render the tab view body (replaces chord grid)
         */
        renderTabBody() {
            let html = '<div class="sbn-leadsheet-body sbn-tab-body">';
            
            // Tab configuration - cleaner MuseScore-style
            const stringCount = 6;
            const measuresPerRow = 4;
            const measureWidth = 180;
            const stringSpacing = 12; // Tighter spacing like MuseScore
            const topPadding = 22; // Increased to accommodate down stems above top string
            const bottomPadding = 20; // Increased to accommodate up stems below bottom string
            const stringAreaTop = 14; // Where strings actually start (visual padding for numbers + measure numbers)
            const tabHeight = topPadding + stringSpacing * (stringCount - 1) + bottomPadding;
            
            // Helper function for x position calculation (standalone view)
            const xPadding = 12;
            const xRange = measureWidth - 2 * xPadding;
            const getX = (xPos) => xPadding + xPos * xRange;
            const topStringY = stringAreaTop;
            const bottomStringY = stringAreaTop + (5 * stringSpacing);
            
            // Process measures in rows
            for (let rowStart = 0; rowStart < this.tabData.length; rowStart += measuresPerRow) {
                const rowEnd = Math.min(rowStart + measuresPerRow, this.tabData.length);
                const rowMeasures = this.tabData.slice(rowStart, rowEnd);
                
                html += '<div class="sbn-tab-row">';
                
                // Measures
                html += '<div class="sbn-tab-measures">';
                
                // Build a list of voltas that span this row
                const rowVoltas = [];
                if (this.voltaEndings) {
                    // Track ongoing voltas
                    let activeVolta = null;
                    
                    for (let measureIdx = 0; measureIdx < this.tabData.length; measureIdx++) {
                        // Convert measureIdx to string since JSON keys are strings
                        const ending = this.voltaEndings[measureIdx.toString()];
                        
                        if (ending && ending.type === 'start') {
                            activeVolta = {
                                number: ending.number,
                                text: ending.text || `${ending.number}.`,
                                startMeasure: measureIdx,
                                endMeasure: null
                            };
                            console.log(`[SBN Volta] Start volta ${ending.number} at measure ${measureIdx}`);
                        }
                        
                        if (ending && ending.type === 'stop' && activeVolta) {
                            activeVolta.endMeasure = measureIdx;
                            
                            // Check if this volta intersects with current row
                            if (activeVolta.startMeasure < rowEnd && activeVolta.endMeasure >= rowStart) {
                                rowVoltas.push(activeVolta);
                                console.log(`[SBN Volta] Adding volta ${activeVolta.number} (measures ${activeVolta.startMeasure}-${activeVolta.endMeasure}) to row ${rowStart}-${rowEnd-1}`);
                            }
                            activeVolta = null;
                        }
                    }
                }
                
                if (rowVoltas.length > 0) {
                    console.log(`[SBN Volta] Row ${rowStart}-${rowEnd-1} has ${rowVoltas.length} voltas:`, rowVoltas);
                }
                
                rowMeasures.forEach((measureNotes, localIdx) => {
                    const measureIdx = rowStart + localIdx;
                    const isCurrent = measureIdx === this.currentMeasure;
                    
                    // Measure container - clickable for seeking
                    html += `<div class="sbn-tab-measure${isCurrent ? ' is-current' : ''}" data-measure="${measureIdx}" data-action="seek-measure">`;
                    
                    // Measure number above the staff
                    html += `<div class="sbn-tab-measure-num">${measureIdx + 1}</div>`;
                    
                    // SVG for this measure
                    html += `<svg class="sbn-tab-svg" viewBox="0 0 ${measureWidth} ${tabHeight}" preserveAspectRatio="xMidYMid meet">`;
                    
                    // Check if this measure has repeat markers
                    const hasRepeatStart = this.repeatMarkers && this.repeatMarkers[measureIdx]?.start;
                    const hasRepeatEnd = this.repeatMarkers && this.repeatMarkers[measureIdx]?.end;
                    
                    if (hasRepeatStart) console.log(`[SBN Render Standalone] Drawing repeatStart for measure ${measureIdx}`);
                    if (hasRepeatEnd) console.log(`[SBN Render Standalone] Drawing repeatEnd for measure ${measureIdx}`);
                    
                    // Draw string lines (thin, light gray like MuseScore)
                    for (let s = 0; s < stringCount; s++) {
                        const y = stringAreaTop + s * stringSpacing;
                        html += `<line x1="0" y1="${y}" x2="${measureWidth}" y2="${y}" class="sbn-tab-string-line" />`;
                    }
                    
                    // Draw repeat start barline AFTER strings (so it appears on top)
                    if (hasRepeatStart) {
                        const staffTop = stringAreaTop;
                        const staffBottom = stringAreaTop + (stringCount - 1) * stringSpacing;
                        const staffHeight = staffBottom - staffTop;
                        
                        const barX1 = 2;    // Thick barline
                        const barX2 = 6;    // Thin barline  
                        const dotX = 11;    // Dots between barlines
                        const repeatY1 = stringAreaTop + (stringSpacing * 1.5);
                        const repeatY2 = stringAreaTop + (stringSpacing * 3.5);
                        
                        // Thick barline (left) - 6.6% of staff height
                        html += `<line x1="${barX1}" y1="${staffTop}" x2="${barX1}" y2="${staffBottom}" stroke="#000" stroke-width="${staffHeight * 0.066}" stroke-linecap="butt" />`;
                        
                        // Thin barline (right) - 2.1% of staff height
                        html += `<line x1="${barX2}" y1="${staffTop}" x2="${barX2}" y2="${staffBottom}" stroke="#000" stroke-width="${staffHeight * 0.021}" stroke-linecap="butt" />`;
                        
                        // Repeat dots - 3% of staff height
                        const dotRadius = staffHeight * 0.03;
                        html += `<circle cx="${dotX}" cy="${repeatY1}" r="${dotRadius}" fill="#000" />`;
                        html += `<circle cx="${dotX}" cy="${repeatY2}" r="${dotRadius}" fill="#000" />`;
                    }
                    
                    // First pass: Draw beams (behind notes) - using standalone dimensions
                    html += this.renderBeamsForMeasureStandalone(measureNotes, getX, topStringY, bottomStringY, stringSpacing, measureWidth);
                    
                    // Second pass: Draw ties (behind notes)
                    html += this.renderTiesForMeasureStandalone(measureNotes, measureIdx, getX, topStringY, bottomStringY, stringSpacing, measureWidth);
                    
                    // Third pass: Draw rests (positioned properly on staff)
                    measureNotes.forEach(note => {
                        if (!note.isRest) return;
                        
                        const x = getX(note.xPos);
                        // Pass stringAreaTop and stringSpacing for proper positioning
                        html += this.renderRestStandalone(x, stringAreaTop, stringSpacing, note.ticks, note.voice);
                    });
                    
                    // Fourth pass: Draw notes, stems, and flags
                    measureNotes.forEach(note => {
                        if (note.isRest) return; // Skip rests, already rendered
                        if (note.string === null || note.fret === null) return;
                        
                        const x = getX(note.xPos);
                        const displayString = 5 - note.string;
                        const y = stringAreaTop + displayString * stringSpacing;
                        
                        const noteId = `tab-note-${measureIdx}-${note.tick}-${note.string}`;
                        
                        html += `<text id="${noteId}" x="${x}" y="${y}" dominant-baseline="central" text-anchor="middle" class="sbn-tab-note-text" data-measure="${measureIdx}" data-tick="${note.tick}" data-end-tick="${note.tick + note.ticks}">${note.fret}</text>`;
                        
                        if (note.stemDir) {
                            const isHalfNote = note.ticks >= 960;
                            const stemLength = isHalfNote ? 12 : 24; // Increased from 7:14 to 12:24
                            
                            let stemY1, stemY2;
                            
                            if (note.stemDir === 'up') {
                                stemY1 = topStringY - 5; // Increased gap from tab system
                                stemY2 = stemY1 - stemLength;
                            } else {
                                stemY1 = bottomStringY + 5; // Increased gap from tab system
                                stemY2 = stemY1 + stemLength;
                            }
                            
                            const voiceClass = note.voice === 2 ? ' voice-2' : '';
                            html += `<line x1="${x}" y1="${stemY1}" x2="${x}" y2="${stemY2}" class="sbn-tab-stem${voiceClass}" />`;
                            
                            // Add flags for unbeamed 8th/16th notes
                            if (note.flagCount > 0 && !note.beamStart && !note.beamContinue && !note.beamEnd) {
                                html += this.renderFlagStandalone(x, stemY2, note.stemDir, note.flagCount, voiceClass);
                            }
                            
                            // Add dot for dotted notes
                            if (note.ticks === 720 || note.ticks === 1440 || note.ticks === 360 || note.ticks === 180) {
                                const dotRadius = 1.2;
                                const dotOffset = 4;
                                const dotX = x + dotOffset;
                                const dotY = note.stemDir === 'up' ? stemY2 + 3 : stemY2 - 3;
                                html += `<circle cx="${dotX}" cy="${dotY}" r="${dotRadius}" class="sbn-tab-dot${voiceClass}" />`;
                            }
                        }
                    });
                    
                    // Bar line at end of measure
                    const staffTop = stringAreaTop;
                    const staffBottom = stringAreaTop + (stringCount - 1) * stringSpacing;
                    const staffHeight = staffBottom - staffTop;
                    
                    // If measure has repeat end, draw repeat barline with double barline
                    if (hasRepeatEnd) {
                        const barX2 = measureWidth - 2;  // Thin barline (left)
                        const barX1 = measureWidth - 6;  // Thick barline (right)
                        const dotX = measureWidth - 11;  // Dots between barlines
                        const repeatY1 = stringAreaTop + (stringSpacing * 1.5);
                        const repeatY2 = stringAreaTop + (stringSpacing * 3.5);
                        
                        // Thin barline (left) - 2.1% of staff height
                        html += `<line x1="${barX2}" y1="${staffTop}" x2="${barX2}" y2="${staffBottom}" stroke="#000" stroke-width="${staffHeight * 0.021}" stroke-linecap="butt" />`;
                        
                        // Thick barline (right) - 6.6% of staff height
                        html += `<line x1="${barX1}" y1="${staffTop}" x2="${barX1}" y2="${staffBottom}" stroke="#000" stroke-width="${staffHeight * 0.066}" stroke-linecap="butt" />`;
                        
                        // Repeat dots - 3% of staff height
                        const dotRadius = staffHeight * 0.03;
                        html += `<circle cx="${dotX}" cy="${repeatY1}" r="${dotRadius}" fill="#000" />`;
                        html += `<circle cx="${dotX}" cy="${repeatY2}" r="${dotRadius}" fill="#000" />`;
                    } else {
                        // Normal barline
                        html += `<line x1="${measureWidth - 0.5}" y1="${staffTop}" x2="${measureWidth - 0.5}" y2="${staffBottom}" class="sbn-tab-bar-line" />`;
                    }
                    
                    html += '</svg>';
                    html += '</div>'; // .sbn-tab-measure
                });
                
                html += '</div>'; // .sbn-tab-measures
                
                // Render volta brackets as an overlay across the entire row
                if (rowVoltas.length > 0) {
                    console.log(`[SBN Volta] Rendering ${rowVoltas.length} volta brackets for row ${rowStart}-${rowEnd-1}`);
                    html += '<div class="sbn-tab-volta-overlay">';
                    // ViewBox needs to start at negative Y to include the bracket above the staff
                    // volta sits at stringAreaTop - 20 = 14 - 20 = -6
                    // So we need viewBox to start at -25 to give room for bracket + text
                    const voltaViewBoxY = -25;
                    const voltaViewBoxHeight = tabHeight + Math.abs(voltaViewBoxY);
                    html += `<svg class="sbn-tab-volta-svg" viewBox="0 ${voltaViewBoxY} ${measureWidth * measuresPerRow} ${voltaViewBoxHeight}" preserveAspectRatio="xMidYMid meet">`;
                    
                    rowVoltas.forEach(volta => {
                        // Calculate positions
                        const localStart = Math.max(0, volta.startMeasure - rowStart);
                        const localEnd = Math.min(measuresPerRow - 1, volta.endMeasure - rowStart);
                        
                        const startX = localStart * measureWidth;
                        const endX = (localEnd + 1) * measureWidth;
                        
                        const voltaHeight = 20;
                        const voltaY = stringAreaTop - voltaHeight;
                        const bracketThickness = 0.8;
                        const textSize = 10;
                        const textPadding = 4;
                        
                        console.log(`[SBN Volta] Drawing volta ${volta.number}: startX=${startX}, endX=${endX}, voltaY=${voltaY}, text="${volta.text}"`);
                        
                        // Vertical line at start
                        html += `<line x1="${startX}" y1="${voltaY}" x2="${startX}" y2="${stringAreaTop - 2}" 
                                      stroke="#000" stroke-width="${bracketThickness}" stroke-linecap="butt" />`;
                        
                        // Horizontal line across the top
                        html += `<line x1="${startX}" y1="${voltaY}" x2="${endX}" y2="${voltaY}" 
                                      stroke="#000" stroke-width="${bracketThickness}" stroke-linecap="butt" />`;
                        
                        // Vertical line at end (only if ending is in this row)
                        if (volta.endMeasure <= rowEnd - 1) {
                            html += `<line x1="${endX}" y1="${voltaY}" x2="${endX}" y2="${stringAreaTop - 2}" 
                                          stroke="#000" stroke-width="${bracketThickness}" stroke-linecap="butt" />`;
                        }
                        
                        // Text label
                        html += `<text x="${startX + textPadding}" y="${voltaY - 2}" 
                                      font-family="serif" font-size="${textSize}" fill="#000">${volta.text}</text>`;
                    });
                    
                    html += '</svg>';
                    html += '</div>'; // .sbn-tab-volta-overlay
                }
                
                html += '</div>'; // .sbn-tab-row
            }
            
            html += '</div>'; // .sbn-tab-body
            return html;
        }
        
        /**
         * Render a row of tab notation (integrated below chord row)
         * @param {number} startMeasure - First measure index in this row
         * @param {number} endMeasure - Last measure index (exclusive) in this row
         */
        renderTabRow(startMeasure, endMeasure) {
            // Tab configuration - MuseScore uses ~44px spacing over ~2444px width ≈ 1.8%
            // For 100-unit measure width, string spacing of 7 gives similar proportions
            const stringCount = 6;
            const stringSpacing = 7;  // Tight spacing matching MuseScore look
            const topPadding = 14;    // Increased to accommodate down stems above top string
            const bottomPadding = 14; // Increased to accommodate up stems below bottom string
            const stringAreaTop = 9;  // Where strings actually start (visual padding for numbers)
            const tabHeight = topPadding + stringSpacing * (stringCount - 1) + bottomPadding;
            const measureWidth = 100; // ViewBox width per measure (100 for easy percentage calc)
            const measuresPerRow = 4; // Always render 4 measure slots for consistent width
            
            // Calculate aspect ratio as percentage for CSS (height/width * 100)
            const aspectRatio = (tabHeight / measureWidth * 100).toFixed(2);
            
            let html = '<div class="sbn-tab-row sbn-tab-row-integrated">';
            
            // Always render 4 measure slots to maintain consistent row width
            for (let slot = 0; slot < measuresPerRow; slot++) {
                const measureIdx = startMeasure + slot;
                const hasContent = measureIdx < endMeasure && measureIdx < (this.tabData?.length || 0);
                const measureNotes = hasContent ? (this.tabData[measureIdx] || []) : [];
                const isCurrent = hasContent && measureIdx === this.currentMeasure;
                
                // Measure container with aspect-ratio wrapper
                // Empty slots still render but with no content
                html += `<div class="sbn-tab-measure-inline${isCurrent ? ' is-current' : ''}${!hasContent ? ' is-empty' : ''}" ${hasContent ? `data-measure="${measureIdx}" data-action="seek-measure"` : ''}>`;
                
                // Inner wrapper maintains aspect ratio using padding technique
                html += `<div class="sbn-tab-aspect-wrapper" style="padding-bottom: ${aspectRatio}%;">`;
                
                // SVG fills the aspect-ratio container - use 'meet' to fit uniformly
                html += `<svg class="sbn-tab-svg-inline" viewBox="0 0 ${measureWidth} ${tabHeight}" preserveAspectRatio="xMidYMid meet">`;
                
                if (hasContent) {
                    // Check if this measure has repeat markers
                    const hasRepeatStart = this.repeatMarkers && this.repeatMarkers[measureIdx]?.start;
                    const hasRepeatEnd = this.repeatMarkers && this.repeatMarkers[measureIdx]?.end;
                    
                    // Draw string lines - extend full width, continuous across measures
                    // Use stringAreaTop instead of topPadding for string positioning
                    for (let s = 0; s < stringCount; s++) {
                        const y = stringAreaTop + s * stringSpacing;
                        html += `<line x1="0" y1="${y}" x2="${measureWidth}" y2="${y}" stroke="#999" stroke-width="0.5" />`;
                    }
                    
                    // Draw repeat start barline AFTER strings (so it appears on top)
                    if (hasRepeatStart) {
                        const barX1 = 1.5;  // Thick barline
                        const barX2 = 4.5;  // Thin barline
                        const dotX = 8;     // Dots between barlines
                        const repeatY1 = stringAreaTop + (stringSpacing * 1.5);
                        const repeatY2 = stringAreaTop + (stringSpacing * 3.5);
                        const staffTop = stringAreaTop;
                        const staffBottom = stringAreaTop + (stringCount - 1) * stringSpacing;
                        const staffHeight = staffBottom - staffTop;
                        
                        // Thick barline (left) - 6.6% of staff height
                        html += `<line x1="${barX1}" y1="${staffTop}" x2="${barX1}" y2="${staffBottom}" stroke="#000" stroke-width="${staffHeight * 0.066}" stroke-linecap="butt" />`;
                        
                        // Thin barline (right) - 2.1% of staff height  
                        html += `<line x1="${barX2}" y1="${staffTop}" x2="${barX2}" y2="${staffBottom}" stroke="#000" stroke-width="${staffHeight * 0.021}" stroke-linecap="butt" />`;
                        
                        // Repeat dots - 3% of staff height
                        const dotRadius = staffHeight * 0.03;
                        html += `<circle cx="${dotX}" cy="${repeatY1}" r="${dotRadius}" fill="#000" />`;
                        html += `<circle cx="${dotX}" cy="${repeatY2}" r="${dotRadius}" fill="#000" />`;
                    }
                    
                    // Helper function to calculate x position
                    const xPadding = 10;
                    const xRange = measureWidth - 2 * xPadding;
                    const getX = (xPos) => xPadding + xPos * xRange;
                    
                    // Calculate positions
                    const topStringY = stringAreaTop;
                    const bottomStringY = stringAreaTop + (5 * stringSpacing);
                    
                    // First pass: Draw beams (behind notes)
                    html += this.renderBeamsForMeasure(measureNotes, getX, topStringY, bottomStringY, stringSpacing);
                    
                    // Second pass: Draw ties (behind notes)
                    html += this.renderTiesForMeasure(measureNotes, measureIdx, getX, topStringY, bottomStringY, stringSpacing);
                    
                    // Third pass: Draw rests (positioned properly on staff)
                    measureNotes.forEach(note => {
                        if (!note.isRest) return;
                        
                        const x = getX(note.xPos);
                        // Pass stringAreaTop and stringSpacing for proper positioning
                        html += this.renderRest(x, stringAreaTop, stringSpacing, note.ticks, note.voice);
                    });
                    
                    // Fourth pass: Draw notes, stems, and flags
                    measureNotes.forEach(note => {
                        if (note.isRest) return; // Skip rests, already rendered
                        if (note.string === null || note.fret === null) return;
                        
                        // X position based on timing
                        const x = getX(note.xPos);
                        
                        // Y position: high e (string 5) at top, low E (string 0) at bottom
                        const displayString = 5 - note.string;
                        const y = stringAreaTop + displayString * stringSpacing;
                        
                        // Unique ID for highlighting
                        const noteId = `tab-inline-${measureIdx}-${note.tick}-${note.string}`;
                        
                        // Note text - use dominant-baseline="central" for precise vertical centering
                        html += `<text id="${noteId}" x="${x}" y="${y}" dominant-baseline="central" class="sbn-tab-note-text" text-anchor="middle" data-measure="${measureIdx}" data-tick="${note.tick}" data-end-tick="${note.tick + note.ticks}">${note.fret}</text>`;
                        
                        // Draw stem if voice info is available
                        if (note.stemDir) {
                            const isHalfNote = note.ticks >= 960;
                            const stemLength = isHalfNote ? 7 : 14; // Increased from 4:8 to 7:14
                            
                            let stemY1, stemY2;
                            
                            if (note.stemDir === 'up') {
                                stemY1 = topStringY - 3; // Increased gap from tab system
                                stemY2 = stemY1 - stemLength;
                            } else {
                                stemY1 = bottomStringY + 3; // Increased gap from tab system
                                stemY2 = stemY1 + stemLength;
                            }
                            
                            const voiceClass = note.voice === 2 ? ' voice-2' : '';
                            html += `<line x1="${x}" y1="${stemY1}" x2="${x}" y2="${stemY2}" class="sbn-tab-stem${voiceClass}" />`;
                            
                            // Add flags for unbeamed 8th/16th notes
                            if (note.flagCount > 0 && !note.beamStart && !note.beamContinue && !note.beamEnd) {
                                html += this.renderFlag(x, stemY2, note.stemDir, note.flagCount, voiceClass);
                            }
                            
                            // Add dot for dotted notes
                            if (note.ticks === 720 || note.ticks === 1440 || note.ticks === 360 || note.ticks === 180) {
                                const dotRadius = 0.8;
                                const dotOffset = 2.5;
                                const dotX = x + dotOffset;
                                const dotY = note.stemDir === 'up' ? stemY2 + 2 : stemY2 - 2;
                                html += `<circle cx="${dotX}" cy="${dotY}" r="${dotRadius}" class="sbn-tab-dot${voiceClass}" />`;
                            }
                        }
                    });
                    
                    // Bar line only at the end of each measure
                    const staffTop = stringAreaTop;
                    const staffBottom = stringAreaTop + (stringCount - 1) * stringSpacing;
                    const staffHeight = staffBottom - staffTop;
                    
                    // If measure has repeat end, draw repeat barline with double barline
                    if (hasRepeatEnd) {
                        const barX2 = measureWidth - 1.5;  // Thin barline (left)
                        const barX1 = measureWidth - 4.5;  // Thick barline (right)
                        const dotX = measureWidth - 8;     // Dots between barlines
                        const repeatY1 = stringAreaTop + (stringSpacing * 1.5);
                        const repeatY2 = stringAreaTop + (stringSpacing * 3.5);
                        
                        // Thin barline (left) - 2.1% of staff height
                        html += `<line x1="${barX2}" y1="${staffTop}" x2="${barX2}" y2="${staffBottom}" stroke="#000" stroke-width="${staffHeight * 0.021}" stroke-linecap="butt" />`;
                        
                        // Thick barline (right) - 6.6% of staff height
                        html += `<line x1="${barX1}" y1="${staffTop}" x2="${barX1}" y2="${staffBottom}" stroke="#000" stroke-width="${staffHeight * 0.066}" stroke-linecap="butt" />`;
                        
                        // Repeat dots - 3% of staff height
                        const dotRadius = staffHeight * 0.03;
                        html += `<circle cx="${dotX}" cy="${repeatY1}" r="${dotRadius}" fill="#000" />`;
                        html += `<circle cx="${dotX}" cy="${repeatY2}" r="${dotRadius}" fill="#000" />`;
                    } else {
                        // Normal barline
                        html += `<line x1="${measureWidth - 0.5}" y1="${staffTop}" x2="${measureWidth - 0.5}" y2="${staffBottom}" stroke="#666" stroke-width="1" />`;
                    }
                }
                
                html += '</svg>';
                html += '</div>'; // .sbn-tab-aspect-wrapper
                html += '</div>'; // .sbn-tab-measure-inline
            }
            
            html += '</div>'; // .sbn-tab-row
            return html;
        }
        
        /**
         * SMuFL codepoints for music notation glyphs (Bravura font)
         */
        static SMUFL = {
            // Flags - stem up (at top of stem)
            flag8thUp: '\uE240',
            flag16thUp: '\uE242',
            flag32ndUp: '\uE244',
            // Flags - stem down (at bottom of stem)
            flag8thDown: '\uE241',
            flag16thDown: '\uE243',
            flag32ndDown: '\uE245',
            // Rests
            restWhole: '\uE4E3',
            restHalf: '\uE4E4',
            restQuarter: '\uE4E5',
            rest8th: '\uE4E6',
            rest16th: '\uE4E7',
            rest32nd: '\uE4E8',
            // Repeat signs
            repeatLeft: '\uE040',  // repeat barline with dots on right
            repeatRight: '\uE041', // repeat barline with dots on left
            repeatDot: '\uE044',   // single repeat dot
        };
        
        /**
         * Render flags for unbeamed 8th and 16th notes using SMuFL glyphs
         * @param {number} x - X position of the stem
         * @param {number} stemEndY - Y position of stem end
         * @param {string} stemDir - 'up' or 'down'
         * @param {number} flagCount - Number of flags (1 for 8th, 2 for 16th)
         * @param {string} voiceClass - CSS class for voice styling
         */
        renderFlag(x, stemEndY, stemDir, flagCount, voiceClass) {
            // Use SMuFL glyphs for professional-quality flags
            const fontSize = 8; // Font size in viewBox units
            let glyph;
            
            if (stemDir === 'up') {
                // Stem up: flag hangs down from top of stem
                if (flagCount >= 2) {
                    glyph = SBNLeadsheetPlayer.SMUFL.flag16thUp;
                } else {
                    glyph = SBNLeadsheetPlayer.SMUFL.flag8thUp;
                }
                // Position: at stem end, flag extends down and right
                return `<text x="${x}" y="${stemEndY}" 
                              font-family="Bravura" font-size="${fontSize}" 
                              fill="#333" class="sbn-tab-flag smufl${voiceClass}">${glyph}</text>`;
            } else {
                // Stem down: flag goes up from bottom of stem
                if (flagCount >= 2) {
                    glyph = SBNLeadsheetPlayer.SMUFL.flag16thDown;
                } else {
                    glyph = SBNLeadsheetPlayer.SMUFL.flag8thDown;
                }
                // Position: at stem end, flag extends up and right
                return `<text x="${x}" y="${stemEndY}" 
                              font-family="Bravura" font-size="${fontSize}" 
                              fill="#333" class="sbn-tab-flag smufl${voiceClass}">${glyph}</text>`;
            }
        }
        
        /**
         * Render beams connecting notes within a measure
         */
        renderBeamsForMeasure(measureNotes, getX, topStringY, bottomStringY, stringSpacing) {
            let html = '';
            
            // Find beam groups in this measure
            const processedStarts = new Set();
            
            measureNotes.forEach(note => {
                if (!note.beamStart || processedStarts.has(note.tick)) return;
                processedStarts.add(note.tick);
                
                // Collect all notes in this beam group
                const beamGroup = [note];
                let current = note;
                while (current.beamWith) {
                    // Find the next note in this measure
                    const nextNote = measureNotes.find(n => 
                        n.tick === current.beamWith.tick && n.voice === current.voice
                    );
                    if (nextNote) {
                        beamGroup.push(nextNote);
                        current = nextNote;
                    } else {
                        break;
                    }
                }
                
                if (beamGroup.length < 2) return;
                
                // Calculate beam positions
                const isUp = beamGroup[0].stemDir === 'up';
                const stemLength = 14; // Match updated stem length for inline view
                const beamThickness = 1.2;
                const beamSpacing = 2;
                
                // Get Y position of beam line (at stem ends) - match updated positioning
                const baseY = isUp 
                    ? topStringY - 3 - stemLength  // Above for up stems
                    : bottomStringY + 3 + stemLength; // Below for down stems
                
                // Find the min/max flags needed for secondary beams
                let maxFlags = 0;
                beamGroup.forEach(n => {
                    const flags = this.getFlagCount(n.ticks);
                    maxFlags = Math.max(maxFlags, flags);
                });
                
                // Draw primary beam (8th note level)
                const x1 = getX(beamGroup[0].xPos);
                const x2 = getX(beamGroup[beamGroup.length - 1].xPos);
                const voiceClass = beamGroup[0].voice === 2 ? ' voice-2' : '';
                
                html += `<rect x="${x1}" y="${isUp ? baseY : baseY - beamThickness}" width="${x2 - x1}" height="${beamThickness}" class="sbn-tab-beam${voiceClass}" fill="#333" />`;
                
                // Draw secondary beams (16th note level) if needed
                if (maxFlags >= 2) {
                    const secondaryY = isUp ? baseY + beamSpacing : baseY - beamSpacing - beamThickness;
                    
                    // For 16th notes, draw partial beams where needed
                    for (let i = 0; i < beamGroup.length; i++) {
                        const n = beamGroup[i];
                        const flags = this.getFlagCount(n.ticks);
                        
                        if (flags >= 2) {
                            const nx = getX(n.xPos);
                            
                            if (i === 0 && beamGroup.length > 1) {
                                // First note - beam to the right
                                const nextX = getX(beamGroup[1].xPos);
                                const beamLen = Math.min(3, (nextX - nx) / 2);
                                html += `<rect x="${nx}" y="${isUp ? secondaryY : secondaryY}" width="${beamLen}" height="${beamThickness}" class="sbn-tab-beam secondary${voiceClass}" fill="#333" />`;
                            } else if (i === beamGroup.length - 1 && beamGroup.length > 1) {
                                // Last note - beam to the left
                                const prevX = getX(beamGroup[i - 1].xPos);
                                const beamLen = Math.min(3, (nx - prevX) / 2);
                                html += `<rect x="${nx - beamLen}" y="${isUp ? secondaryY : secondaryY}" width="${beamLen}" height="${beamThickness}" class="sbn-tab-beam secondary${voiceClass}" fill="#333" />`;
                            } else if (i > 0 && i < beamGroup.length - 1) {
                                // Middle note - check neighbors
                                const prevFlags = this.getFlagCount(beamGroup[i - 1].ticks);
                                const nextFlags = this.getFlagCount(beamGroup[i + 1].ticks);
                                
                                if (prevFlags >= 2 && nextFlags >= 2) {
                                    // Full beam through this note
                                    const prevX = getX(beamGroup[i - 1].xPos);
                                    const nextX = getX(beamGroup[i + 1].xPos);
                                    html += `<rect x="${prevX}" y="${isUp ? secondaryY : secondaryY}" width="${nextX - prevX}" height="${beamThickness}" class="sbn-tab-beam secondary${voiceClass}" fill="#333" />`;
                                } else {
                                    // Partial beam
                                    const beamLen = 2;
                                    html += `<rect x="${nx - beamLen / 2}" y="${isUp ? secondaryY : secondaryY}" width="${beamLen}" height="${beamThickness}" class="sbn-tab-beam secondary${voiceClass}" fill="#333" />`;
                                }
                            }
                        }
                    }
                }
            });
            
            return html;
        }
        
        /**
         * Get the number of flags for a note duration
         */
        getFlagCount(ticks) {
            const baseTicks = this.getBaseDuration(ticks);
            if (baseTicks <= 120) return 2; // 16th or shorter
            if (baseTicks <= 240) return 1; // 8th
            return 0; // Quarter or longer
        }
        
        /**
         * Render ties connecting notes within and across measures
         * 
         * For within-measure ties: single arc from note to note
         * For cross-barline ties: TWO arcs that meet at the barline
         *   - First measure: arc from note to end of measure (flat exit)
         *   - Second measure: arc from start of measure to note (flat entry)
         *   These meet seamlessly at the barline to look like one continuous tie
         */
        renderTiesForMeasure(measureNotes, measureIdx, getX, topStringY, bottomStringY, stringSpacing) {
            let html = '';
            const measureWidth = 100; // Standard measure width in viewBox units
            
            measureNotes.forEach(note => {
                // Skip notes without string info
                if (note.string === null) return;
                
                const displayString = 5 - note.string;
                const y = topStringY + displayString * stringSpacing;
                
                // Determine curve direction based on voice
                const curveDir = note.voice === 1 ? -1 : 1; // Voice 1 curves up, voice 2 curves down
                const voiceClass = note.voice === 2 ? ' voice-2' : '';
                const tieY = y + (curveDir > 0 ? 1.5 : -1.5);
                
                // WITHIN-MEASURE TIE: note starts and ends in same measure
                if (note.tieStart && note.tieEndNote && note.tieEndNote.measureIdx === measureIdx) {
                    const x1 = getX(note.xPos) + 2;
                    const x2 = getX(note.tieEndNote.xPos) - 2;
                    
                    html += this.renderTieArc(x1, x2, tieY, curveDir, 3, voiceClass);
                }
                
                // OUTGOING CROSS-BARLINE TIE: This note starts a tie that ends in next measure
                if (note.tieStart && note.tieEndNote && note.tieEndNote.measureIdx !== measureIdx) {
                    const x1 = getX(note.xPos) + 2;
                    const x2 = measureWidth; // Go to edge of measure
                    
                    // Flatter curve for cross-barline (exits horizontally)
                    html += this.renderTieArcCrossBar(x1, x2, tieY, curveDir, 2.5, voiceClass, 'outgoing');
                }
                
                // INCOMING CROSS-BARLINE TIE: This note receives a tie from previous measure
                if (note.tieStop && note.tieStartNote && note.tieStartNote.measureIdx !== measureIdx) {
                    const x1 = 0; // Start from edge of measure
                    const x2 = getX(note.xPos) - 2;
                    
                    // Flatter curve for cross-barline (enters horizontally)
                    html += this.renderTieArcCrossBar(x1, x2, tieY, curveDir, 2.5, voiceClass, 'incoming');
                }
            });
            
            return html;
        }
        
        /**
         * Render a tie arc (filled crescent shape like MuseScore)
         */
        renderTieArc(x1, x2, y, curveDir, curveHeight, voiceClass) {
            const midX = (x1 + x2) / 2;
            const outerY = y - (curveHeight * curveDir);
            const innerY = y - ((curveHeight - 0.8) * curveDir);
            
            // Cubic bezier control points for smooth curve
            const cp1x = x1 + (x2 - x1) * 0.2;
            const cp2x = x1 + (x2 - x1) * 0.8;
            
            // Create filled crescent shape (like MuseScore)
            return `<path d="M${x1},${y} C${cp1x},${outerY} ${cp2x},${outerY} ${x2},${y} C${cp2x},${innerY} ${cp1x},${innerY} ${x1},${y}" 
                          fill="#333" stroke="none" class="sbn-tab-tie${voiceClass}" />`;
        }
        
        /**
         * Render a cross-barline tie arc (asymmetric - flat at barline edge)
         */
        renderTieArcCrossBar(x1, x2, y, curveDir, curveHeight, voiceClass, direction) {
            const outerY = y - (curveHeight * curveDir);
            const innerY = y - ((curveHeight - 0.6) * curveDir);
            
            if (direction === 'outgoing') {
                // Outgoing: curve starts at note (full curve) and exits flat at barline
                const cp1x = x1 + (x2 - x1) * 0.3;
                const cp2x = x1 + (x2 - x1) * 0.7;
                
                return `<path d="M${x1},${y} C${cp1x},${outerY} ${cp2x},${outerY} ${x2},${outerY} L${x2},${innerY} C${cp2x},${innerY} ${cp1x},${innerY} ${x1},${y}" 
                              fill="#333" stroke="none" class="sbn-tab-tie cross-bar outgoing${voiceClass}" />`;
            } else {
                // Incoming: enters flat at barline and curves to note
                const cp1x = x1 + (x2 - x1) * 0.3;
                const cp2x = x1 + (x2 - x1) * 0.7;
                
                return `<path d="M${x1},${outerY} C${cp1x},${outerY} ${cp2x},${outerY} ${x2},${y} C${cp2x},${innerY} ${cp1x},${innerY} ${x1},${innerY} L${x1},${outerY}" 
                              fill="#333" stroke="none" class="sbn-tab-tie cross-bar incoming${voiceClass}" />`;
            }
        }
        
        /**
         * Render flags for standalone view (larger dimensions) using SMuFL glyphs
         */
        renderFlagStandalone(x, stemEndY, stemDir, flagCount, voiceClass) {
            // Use SMuFL glyphs - larger font size for standalone view
            const fontSize = 14; // Larger font size for standalone
            let glyph;
            
            if (stemDir === 'up') {
                if (flagCount >= 2) {
                    glyph = SBNLeadsheetPlayer.SMUFL.flag16thUp;
                } else {
                    glyph = SBNLeadsheetPlayer.SMUFL.flag8thUp;
                }
                return `<text x="${x}" y="${stemEndY}" 
                              font-family="Bravura" font-size="${fontSize}" 
                              fill="#333" class="sbn-tab-flag smufl${voiceClass}">${glyph}</text>`;
            } else {
                if (flagCount >= 2) {
                    glyph = SBNLeadsheetPlayer.SMUFL.flag16thDown;
                } else {
                    glyph = SBNLeadsheetPlayer.SMUFL.flag8thDown;
                }
                return `<text x="${x}" y="${stemEndY}" 
                              font-family="Bravura" font-size="${fontSize}" 
                              fill="#333" class="sbn-tab-flag smufl${voiceClass}">${glyph}</text>`;
            }
        }
        
        /**
         * Render a rest using SMuFL glyph (inline view)
         * @param {number} x - X position
         * @param {number} stringAreaTop - Y position of top string (string 0 / high E)
         * @param {number} stringSpacing - Spacing between strings
         * @param {number} ticks - Duration in ticks
         * @param {number} voice - Voice number (1 or 2)
         */
        renderRest(x, stringAreaTop, stringSpacing, ticks, voice) {
            // Scale font size to string spacing - inline view uses stringSpacing=7
            // Use ~2x string spacing for good visibility
            const fontSize = stringSpacing * 2;
            const glyph = this.getRestGlyph(ticks);
            const voiceClass = voice === 2 ? ' voice-2' : '';
            
            // Duration constants
            const WHOLE = 1920;
            const HALF = 960;
            
            let y, baseline;
            
            // Position half and whole rests on staff lines (standard notation)
            // In tab: string 0 = high E (top), string 5 = low E (bottom)
            // Center strings are 2 (G) and 3 (D)
            if (ticks >= WHOLE) {
                // Whole rest: hangs BELOW a line - position below string 2 (G string)
                // Bravura whole rest glyph hangs down from baseline
                y = stringAreaTop + 2 * stringSpacing;
                baseline = 'hanging'; // Glyph hangs below the line
            } else if (ticks >= HALF) {
                // Half rest: sits ON TOP of a line - position on string 3 (D string)
                // Bravura half rest glyph sits on baseline
                y = stringAreaTop + 3 * stringSpacing;
                baseline = 'auto'; // Glyph sits on the line
            } else {
                // Quarter, 8th, 16th rests: center vertically between strings 2 and 3
                y = stringAreaTop + 2.5 * stringSpacing;
                baseline = 'central';
            }
            
            return `<text x="${x}" y="${y}" 
                          font-family="Bravura" font-size="${fontSize}" 
                          dominant-baseline="${baseline}" text-anchor="middle"
                          fill="#333" class="sbn-tab-rest smufl${voiceClass}">${glyph}</text>`;
        }
        
        /**
         * Render a rest using SMuFL glyph (standalone view - larger)
         * @param {number} x - X position
         * @param {number} stringAreaTop - Y position of top string (string 0 / high E)
         * @param {number} stringSpacing - Spacing between strings
         * @param {number} ticks - Duration in ticks
         * @param {number} voice - Voice number (1 or 2)
         */
        renderRestStandalone(x, stringAreaTop, stringSpacing, ticks, voice) {
            // Scale font size to string spacing - standalone uses stringSpacing=12
            // Use ~2x string spacing for good visibility
            const fontSize = stringSpacing * 2;
            const glyph = this.getRestGlyph(ticks);
            const voiceClass = voice === 2 ? ' voice-2' : '';
            
            // Duration constants
            const WHOLE = 1920;
            const HALF = 960;
            
            let y, baseline;
            
            // Position half and whole rests on staff lines (standard notation)
            // In tab: string 0 = high E (top), string 5 = low E (bottom)
            // Center strings are 2 (G) and 3 (D)
            if (ticks >= WHOLE) {
                // Whole rest: hangs BELOW a line - position below string 2 (G string)
                // Bravura whole rest glyph hangs down from baseline
                y = stringAreaTop + 2 * stringSpacing;
                baseline = 'hanging'; // Glyph hangs below the line
            } else if (ticks >= HALF) {
                // Half rest: sits ON TOP of a line - position on string 3 (D string)
                // Bravura half rest glyph sits on baseline
                y = stringAreaTop + 3 * stringSpacing;
                baseline = 'auto'; // Glyph sits on the line
            } else {
                // Quarter, 8th, 16th rests: center vertically between strings 2 and 3
                y = stringAreaTop + 2.5 * stringSpacing;
                baseline = 'central';
            }
            
            return `<text x="${x}" y="${y}" 
                          font-family="Bravura" font-size="${fontSize}" 
                          dominant-baseline="${baseline}" text-anchor="middle"
                          fill="#333" class="sbn-tab-rest smufl${voiceClass}">${glyph}</text>`;
        }
        
        /**
         * Get the appropriate rest glyph based on duration ticks
         */
        getRestGlyph(ticks) {
            // Duration constants (based on 480 ticks per quarter note)
            const WHOLE = 1920;
            const HALF = 960;
            const QUARTER = 480;
            const EIGHTH = 240;
            const SIXTEENTH = 120;
            
            // Handle dotted durations by using base duration
            const baseTicks = ticks % 60 === 0 ? ticks : Math.floor(ticks / 1.5);
            
            if (baseTicks >= WHOLE) return SBNLeadsheetPlayer.SMUFL.restWhole;
            if (baseTicks >= HALF) return SBNLeadsheetPlayer.SMUFL.restHalf;
            if (baseTicks >= QUARTER) return SBNLeadsheetPlayer.SMUFL.restQuarter;
            if (baseTicks >= EIGHTH) return SBNLeadsheetPlayer.SMUFL.rest8th;
            if (baseTicks >= SIXTEENTH) return SBNLeadsheetPlayer.SMUFL.rest16th;
            return SBNLeadsheetPlayer.SMUFL.rest32nd;
        }
        
        /**
         * Render beams for standalone view (larger dimensions)
         */
        renderBeamsForMeasureStandalone(measureNotes, getX, topStringY, bottomStringY, stringSpacing, measureWidth) {
            let html = '';
            
            const processedStarts = new Set();
            
            measureNotes.forEach(note => {
                if (!note.beamStart || processedStarts.has(note.tick)) return;
                processedStarts.add(note.tick);
                
                const beamGroup = [note];
                let current = note;
                while (current.beamWith) {
                    const nextNote = measureNotes.find(n => 
                        n.tick === current.beamWith.tick && n.voice === current.voice
                    );
                    if (nextNote) {
                        beamGroup.push(nextNote);
                        current = nextNote;
                    } else {
                        break;
                    }
                }
                
                if (beamGroup.length < 2) return;
                
                const isUp = beamGroup[0].stemDir === 'up';
                const stemLength = 24; // Match updated standalone stem length
                const beamThickness = 2;  // Thicker for standalone
                const beamSpacing = 3.5;
                
                // Match updated standalone positioning
                const baseY = isUp 
                    ? topStringY - 5 - stemLength
                    : bottomStringY + 5 + stemLength;
                
                let maxFlags = 0;
                beamGroup.forEach(n => {
                    const flags = this.getFlagCount(n.ticks);
                    maxFlags = Math.max(maxFlags, flags);
                });
                
                const x1 = getX(beamGroup[0].xPos);
                const x2 = getX(beamGroup[beamGroup.length - 1].xPos);
                const voiceClass = beamGroup[0].voice === 2 ? ' voice-2' : '';
                
                // Primary beam
                html += `<rect x="${x1}" y="${isUp ? baseY : baseY - beamThickness}" width="${x2 - x1}" height="${beamThickness}" class="sbn-tab-beam${voiceClass}" fill="#333" />`;
                
                // Secondary beams for 16th notes
                if (maxFlags >= 2) {
                    const secondaryY = isUp ? baseY + beamSpacing : baseY - beamSpacing - beamThickness;
                    
                    for (let i = 0; i < beamGroup.length; i++) {
                        const n = beamGroup[i];
                        const flags = this.getFlagCount(n.ticks);
                        
                        if (flags >= 2) {
                            const nx = getX(n.xPos);
                            
                            if (i === 0 && beamGroup.length > 1) {
                                const nextX = getX(beamGroup[1].xPos);
                                const beamLen = Math.min(5, (nextX - nx) / 2);
                                html += `<rect x="${nx}" y="${isUp ? secondaryY : secondaryY}" width="${beamLen}" height="${beamThickness}" class="sbn-tab-beam secondary${voiceClass}" fill="#333" />`;
                            } else if (i === beamGroup.length - 1 && beamGroup.length > 1) {
                                const prevX = getX(beamGroup[i - 1].xPos);
                                const beamLen = Math.min(5, (nx - prevX) / 2);
                                html += `<rect x="${nx - beamLen}" y="${isUp ? secondaryY : secondaryY}" width="${beamLen}" height="${beamThickness}" class="sbn-tab-beam secondary${voiceClass}" fill="#333" />`;
                            } else if (i > 0 && i < beamGroup.length - 1) {
                                const prevFlags = this.getFlagCount(beamGroup[i - 1].ticks);
                                const nextFlags = this.getFlagCount(beamGroup[i + 1].ticks);
                                
                                if (prevFlags >= 2 && nextFlags >= 2) {
                                    const prevX = getX(beamGroup[i - 1].xPos);
                                    const nextX = getX(beamGroup[i + 1].xPos);
                                    html += `<rect x="${prevX}" y="${isUp ? secondaryY : secondaryY}" width="${nextX - prevX}" height="${beamThickness}" class="sbn-tab-beam secondary${voiceClass}" fill="#333" />`;
                                } else {
                                    const beamLen = 3.5;
                                    html += `<rect x="${nx - beamLen / 2}" y="${isUp ? secondaryY : secondaryY}" width="${beamLen}" height="${beamThickness}" class="sbn-tab-beam secondary${voiceClass}" fill="#333" />`;
                                }
                            }
                        }
                    }
                }
            });
            
            return html;
        }
        
        /**
         * Render ties for standalone view (larger dimensions)
         * Uses same logic as inline but with larger dimensions
         */
        renderTiesForMeasureStandalone(measureNotes, measureIdx, getX, topStringY, bottomStringY, stringSpacing, measureWidth) {
            let html = '';
            
            measureNotes.forEach(note => {
                // Skip notes without string info
                if (note.string === null) return;
                
                const displayString = 5 - note.string;
                const y = topStringY + displayString * stringSpacing;
                
                const curveDir = note.voice === 1 ? -1 : 1;
                const voiceClass = note.voice === 2 ? ' voice-2' : '';
                const tieY = y + (curveDir > 0 ? 2 : -2);
                
                // WITHIN-MEASURE TIE
                if (note.tieStart && note.tieEndNote && note.tieEndNote.measureIdx === measureIdx) {
                    const x1 = getX(note.xPos) + 3;
                    const x2 = getX(note.tieEndNote.xPos) - 3;
                    
                    html += this.renderTieArcStandalone(x1, x2, tieY, curveDir, 5, voiceClass);
                }
                
                // OUTGOING CROSS-BARLINE TIE
                if (note.tieStart && note.tieEndNote && note.tieEndNote.measureIdx !== measureIdx) {
                    const x1 = getX(note.xPos) + 3;
                    const x2 = measureWidth;
                    
                    html += this.renderTieArcCrossBarStandalone(x1, x2, tieY, curveDir, 4, voiceClass, 'outgoing');
                }
                
                // INCOMING CROSS-BARLINE TIE
                if (note.tieStop && note.tieStartNote && note.tieStartNote.measureIdx !== measureIdx) {
                    const x1 = 0;
                    const x2 = getX(note.xPos) - 3;
                    
                    html += this.renderTieArcCrossBarStandalone(x1, x2, tieY, curveDir, 4, voiceClass, 'incoming');
                }
            });
            
            return html;
        }
        
        /**
         * Render a tie arc for standalone view (filled crescent shape)
         */
        renderTieArcStandalone(x1, x2, y, curveDir, curveHeight, voiceClass) {
            const outerY = y - (curveHeight * curveDir);
            const innerY = y - ((curveHeight - 1.2) * curveDir);
            
            const cp1x = x1 + (x2 - x1) * 0.2;
            const cp2x = x1 + (x2 - x1) * 0.8;
            
            return `<path d="M${x1},${y} C${cp1x},${outerY} ${cp2x},${outerY} ${x2},${y} C${cp2x},${innerY} ${cp1x},${innerY} ${x1},${y}" 
                          fill="#333" stroke="none" class="sbn-tab-tie${voiceClass}" />`;
        }
        
        /**
         * Render a cross-barline tie arc for standalone view
         */
        renderTieArcCrossBarStandalone(x1, x2, y, curveDir, curveHeight, voiceClass, direction) {
            const outerY = y - (curveHeight * curveDir);
            const innerY = y - ((curveHeight - 0.8) * curveDir);
            
            if (direction === 'outgoing') {
                const cp1x = x1 + (x2 - x1) * 0.3;
                const cp2x = x1 + (x2 - x1) * 0.7;
                
                return `<path d="M${x1},${y} C${cp1x},${outerY} ${cp2x},${outerY} ${x2},${outerY} L${x2},${innerY} C${cp2x},${innerY} ${cp1x},${innerY} ${x1},${y}" 
                              fill="#333" stroke="none" class="sbn-tab-tie cross-bar outgoing${voiceClass}" />`;
            } else {
                const cp1x = x1 + (x2 - x1) * 0.3;
                const cp2x = x1 + (x2 - x1) * 0.7;
                
                return `<path d="M${x1},${outerY} C${cp1x},${outerY} ${cp2x},${outerY} ${x2},${y} C${cp2x},${innerY} ${cp1x},${innerY} ${x1},${innerY} L${x1},${outerY}" 
                              fill="#333" stroke="none" class="sbn-tab-tie cross-bar incoming${voiceClass}" />`;
            }
        }
        
        /**
         * Update tab highlighting during playback (called from updateDisplay)
         */
        updateTabHighlighting() {
            if (!this.showTab || !this.hasTabData) return;
            
            const ticksPerBeat = 480;
            const timeSig = this.song.timeSignature || '4/4';
            const beatsPerMeasure = parseInt(timeSig.split('/')[0]) || 4;
            const ticksPerMeasure = ticksPerBeat * beatsPerMeasure;
            
            // Calculate current tick position
            const currentTick = this.currentMeasure * ticksPerMeasure + 
                (this.currentSubBeat / this.subbeatsPerMeasure) * ticksPerMeasure;
            
            // Update measure highlights (both standalone and inline)
            this.container.querySelectorAll('.sbn-tab-measure, .sbn-tab-measure-inline').forEach(el => {
                const measureIdx = parseInt(el.dataset.measure);
                el.classList.toggle('is-current', measureIdx === this.currentMeasure);
            });
            
            // Remove all existing note highlights
            this.container.querySelectorAll('.sbn-tab-note-text.is-playing').forEach(el => {
                el.classList.remove('is-playing');
            });
            
            // Add highlight to currently playing notes
            if (this.isPlaying) {
                this.container.querySelectorAll('.sbn-tab-note-text').forEach(el => {
                    const noteTick = parseInt(el.dataset.tick);
                    const noteEndTick = parseInt(el.dataset.endTick);
                    const noteMeasure = parseInt(el.dataset.measure);
                    
                    // Check if this note is currently playing
                    if (noteMeasure === this.currentMeasure && 
                        currentTick >= noteTick && 
                        currentTick < noteEndTick) {
                        el.classList.add('is-playing');
                    }
                });
            }
        }
        
        /**
         * Render the visualization area with view selector tabs
         */
        renderVisualizationArea() {
            const hasRhythm = !!this.song.rhythmPattern;
            const hasVoicings = this.song.chordVoicings && Object.keys(this.song.chordVoicings).length > 0;
            const hasInfo = true; // Always show Song Info tab
            
            // If no rhythm, no voicings, and no info, don't show this area
            if (!hasRhythm && !hasVoicings && !hasInfo) {
                return '';
            }
            
            let html = `<div class="sbn-leadsheet-viz${this.fretboardTheme === 'light' ? ' theme-light' : ' theme-dark'}">`;
            
            // View selector tabs — always show as tab buttons now for clarity
            html += '<div class="sbn-leadsheet-viz-tabs">';
            
            if (hasVoicings) {
                html += `
                    <button class="sbn-leadsheet-viz-tab${this.activeView === 'fretboard' ? ' is-active' : ''}" data-action="switch-view" data-view="fretboard">
                        <span class="sbn-viz-tab-icon">🎸</span>
                        <span class="sbn-viz-tab-label">Fretboard</span>
                    </button>`;
            }
            if (hasRhythm) {
                html += `
                    <button class="sbn-leadsheet-viz-tab${this.activeView === 'rhythm' ? ' is-active' : ''}" data-action="switch-view" data-view="rhythm">
                        <span class="sbn-viz-tab-icon">🥁</span>
                        <span class="sbn-viz-tab-label">Rhythm</span>
                    </button>`;
            }
            if (hasVoicings) {
                html += `
                    <button class="sbn-leadsheet-viz-tab${this.activeView === 'allvoicings' ? ' is-active' : ''}" data-action="switch-view" data-view="allvoicings">
                        <span class="sbn-viz-tab-icon">🎼</span>
                        <span class="sbn-viz-tab-label">All Voicings</span>
                    </button>`;
            }
            if (hasInfo) {
                html += `
                    <button class="sbn-leadsheet-viz-tab${this.activeView === 'info' ? ' is-active' : ''}" data-action="switch-view" data-view="info">
                        <span class="sbn-viz-tab-icon">📖</span>
                        <span class="sbn-viz-tab-label">Song Info</span>
                    </button>`;
            }
            
            // Theme toggle + Guide Tone toggle (only for fretboard view)
            if (hasVoicings && this.activeView === 'fretboard') {
                html += `
                    <button class="sbn-fretboard-guide-toggle${this.guideToneMode ? ' is-active' : ''}" data-action="toggle-guide-tones" title="${this.guideToneMode ? 'Hide guide tone movements' : 'Show guide tone movements'}">
                        <span class="sbn-guide-toggle-label">GT</span>
                    </button>
                    <button class="sbn-fretboard-theme-toggle" data-action="toggle-fretboard-theme" title="Toggle light/dark fretboard">
                        ${this.fretboardTheme === 'dark' ? '☀️' : '🌙'}
                    </button>`;
            }
            
            html += '</div>';
            
            // View content
            html += '<div class="sbn-leadsheet-viz-content">';
            
            if (this.activeView === 'fretboard' && hasVoicings) {
                html += this.renderFretboard();
            } else if (this.activeView === 'rhythm' && hasRhythm) {
                html += this.renderRhythmGridContent(this.song.rhythmPattern);
            } else if (this.activeView === 'allvoicings' && hasVoicings) {
                html += this.renderAllVoicingsGrid();
            } else if (this.activeView === 'info' && hasInfo) {
                html += this.renderInfoPanel();
            } else if (hasRhythm) {
                html += this.renderRhythmGridContent(this.song.rhythmPattern);
            } else if (hasVoicings) {
                html += this.renderFretboard();
            } else if (hasInfo) {
                html += this.renderInfoPanel();
            }
            
            html += '</div></div>';
            return html;
        }
        
        /**
         * Render a section-level info overlay (shown below section header when info btn clicked)
         */
        renderSectionInfoOverlay(section, sectionIdx) {
            const isVisible = !!(this.sectionInfoVisible && this.sectionInfoVisible[sectionIdx]);
            const infoSections = [
                { key: 'info', icon: '📝', label: 'Overview', content: section.info },
                { key: 'harmonyNotes', icon: '🎵', label: 'Harmony', content: section.harmonyNotes },
                { key: 'formNotes', icon: '📐', label: 'Form', content: section.formNotes },
                { key: 'voicingNotes', icon: '🎸', label: 'Voicings', content: section.voicingNotes },
            ].filter(s => !!s.content);
            
            if (!infoSections.length) return '';
            
            let html = `<div class="sbn-section-info-overlay${isVisible ? ' is-visible' : ''}" data-section-info="${sectionIdx}">`;
            html += '<div class="sbn-section-info-overlay-inner">';
            infoSections.forEach(s => {
                html += `<div class="sbn-section-info-item">
                    <span class="sbn-section-info-item-label">${s.icon} ${s.label}</span>
                    <span class="sbn-section-info-item-text">${this.escapeHtml(s.content).replace(/\n/g, '<br>')}</span>
                </div>`;
            });
            html += '</div></div>';
            return html;
        }
        
        /**
         * Escape HTML for safe rendering
         */
        escapeHtml(str) {
            if (!str) return '';
            return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        }
        
        /**
         * Render the All Voicings grid — chord cards for all voicings in the song
         */
        renderAllVoicingsGrid() {
            const voicings = this.song.chordVoicings;
            if (!voicings || !Object.keys(voicings).length) return '';

            // ── Build shape groups ──────────────────────────────────────────
            // archetypeKey = baseQuality|voicingCategory|rootString|inversion
            // Each group collects all extension variants of that shape.
            // extensionVariants: Map<extensions string, { name, voicing }>
            // We keep one representative voicing per extension variant.

            const shapeGroups = new Map(); // archetypeKey → group object

            Object.entries(voicings).forEach(([name, voicing]) => {
                if (name.includes('@')) return;

                const bq  = voicing.baseQuality     || voicing.quality || '';
                const vc  = voicing.voicingCategory  || '';
                const rs  = voicing.rootString       || '';
                const inv = voicing.inversion        || 'root';
                const ext = voicing.extensions       || '';

                let archetypeKey;
                if (bq && vc && rs) {
                    archetypeKey = [bq, vc, rs, inv].join('|');
                } else {
                    archetypeKey = 'frets|' + (voicing.frets || '') + '|' + (voicing.position || 1);
                }

                if (!shapeGroups.has(archetypeKey)) {
                    shapeGroups.set(archetypeKey, {
                        baseQuality: bq,
                        voicingCategory: vc,
                        rootString: rs,
                        inversion: inv,
                        variants: new Map(), // ext → { name, voicing }
                        archetypeKey,
                    });
                }
                const group = shapeGroups.get(archetypeKey);
                // Store one representative per extension (first encountered wins)
                if (!group.variants.has(ext)) {
                    group.variants.set(ext, { name, voicing });
                }
            });

            // ── Summary stats ───────────────────────────────────────────────
            const qualityCounts   = new Map(); // baseQuality → count of shapes
            const categorySet     = new Set();
            const extensionSet    = new Set();

            shapeGroups.forEach(g => {
                if (g.baseQuality) qualityCounts.set(g.baseQuality, (qualityCounts.get(g.baseQuality) || 0) + 1);
                if (g.voicingCategory) categorySet.add(g.voicingCategory);
                g.variants.forEach((_, ext) => { if (ext) extensionSet.add(ext); });
            });

            const catLabels = { drop2: 'Drop 2', drop3: 'Drop 3', drop2and4: 'Drop 2&4', shell: 'Shell', rootless: 'Rootless', close: 'Close', spread: 'Spread' };
            const qLabels   = { maj7: 'maj7', m7: 'm7', dom7: 'dom7', '7': '7', m7b5: 'm7♭5', dim7: 'dim7', mmaj7: 'mMaj7', maj: 'maj', m: 'm' };

            // ── Init extension state if needed ──────────────────────────────
            if (!this._voicingExtState) this._voicingExtState = {};
            shapeGroups.forEach((g, key) => {
                if (!(key in this._voicingExtState)) this._voicingExtState[key] = 0;
            });

            // ── Render ──────────────────────────────────────────────────────
            let html = '<div class="sbn-chord-types">';

            // Summary
            html += '<div class="sbn-chord-types-summary">';
            const qualityPills = [...qualityCounts.keys()].map(q =>
                '<span class="sbn-ct-quality-pill">' + this.escapeHtml(qLabels[q] || q) + '</span>'
            ).join('');
            const catNames = [...categorySet].map(c => catLabels[c] || c).join(', ');
            const extNames = extensionSet.size ? [...extensionSet].map(e => this.escapeHtml(e)).join(', ') : null;

            if (qualityPills) html += '<div class="sbn-ct-quality-row">' + qualityPills + '</div>';
            html += '<div class="sbn-ct-summary-text">';
            html += shapeGroups.size + ' voicing shape' + (shapeGroups.size !== 1 ? 's' : '');
            if (catNames) html += ' &nbsp;·&nbsp; ' + this.escapeHtml(catNames);
            if (extNames) html += ' &nbsp;·&nbsp; extensions: ' + extNames;
            html += '</div>';
            html += '</div>'; // .sbn-chord-types-summary

            // Card grid
            html += '<div class="sbn-chord-types-grid">';

            shapeGroups.forEach((g, key) => {
                const variantList = [...g.variants.entries()]; // [[ext, {name,voicing}], ...]
                const idx = Math.min(this._voicingExtState[key] || 0, variantList.length - 1);
                const [curExt, curVariant] = variantList[idx];
                const hasMultiple = variantList.length > 1;

                const shapeLabel = this.getShapeLabel(curVariant.voicing);

                html += '<div class="sbn-ct-card" data-archetype="' + this.escapeHtml(key) + '">';

                // Chord name — always shown at top
                html += '<div class="sbn-ct-card-chord-name">' + this.escapeHtml(curVariant.name) + '</div>';

                // Diagram (clickable → chord detail)
                html += '<div class="sbn-ct-card-diagram" data-action="chord-info" data-chord="'
                      + this.escapeHtml(curVariant.name) + '" title="Click for chord detail">'
                      + renderChordDiagram(curVariant.voicing, 62, false, { showPosition: false })
                      + '</div>';

                // Shape label (voicing type) — only if available
                if (shapeLabel) {
                    html += '<div class="sbn-ct-card-shape">' + this.escapeHtml(shapeLabel) + '</div>';
                }

                // Extension row: prev · ext badge · next
                if (hasMultiple) {
                    html += '<div class="sbn-ct-card-ext-row">'
                          + '<button class="sbn-ct-ext-btn" data-action="voicing-ext-prev" data-archetype="' + this.escapeHtml(key) + '" title="Previous extension">‹</button>'
                          + '<span class="sbn-ct-ext-badge">' + this.escapeHtml(curExt || 'plain') + '</span>'
                          + '<button class="sbn-ct-ext-btn" data-action="voicing-ext-next" data-archetype="' + this.escapeHtml(key) + '" title="Next extension">›</button>'
                          + '</div>';
                } else if (curExt) {
                    html += '<div class="sbn-ct-card-ext-row"><span class="sbn-ct-ext-badge">' + this.escapeHtml(curExt) + '</span></div>';
                }

                html += '</div>'; // .sbn-ct-card
            });

            html += '</div>'; // .sbn-chord-types-grid
            html += '</div>'; // .sbn-chord-types
            return html;
        }

        /**
         * Get a compact shape label for a voicing card (quality + type, no root)
         */
        getShapeLabel(voicing) {
            const parts = [];
            if (voicing.voicingCategory) {
                // Abbreviate common voicing types
                const catMap = { drop2: 'Drop 2', drop3: 'Drop 3', drop2and4: 'Drop 2&4', shell: 'Shell', rootless: 'Rootless', close: 'Close', spread: 'Spread', custom: '' };
                const cat = catMap[voicing.voicingCategory] !== undefined ? catMap[voicing.voicingCategory] : voicing.voicingCategory.replace(/_/g, ' ');
                if (cat) parts.push(cat);
            }
            if (voicing.inversion && voicing.inversion !== 'root') {
                const invMap = { inv1: '1st inv', inv2: '2nd inv', inv3: '3rd inv' };
                parts.push(invMap[voicing.inversion] || voicing.inversion);
            }
            return parts.join(' · ') || (voicing.quality || '');
        }
        
        /**
         * Render the song-level info / educational content panel
         */
        renderInfoPanel() {
            const desc = this.song.description;
            if (!desc) {
                return '<div class="sbn-info-panel"><p class="sbn-info-empty">No song info added yet.</p></div>';
            }
            return '<div class="sbn-info-panel"><div class="sbn-info-panel-section-content">'
                + this.escapeHtml(desc).replace(/\n/g, '<br>')
                + '</div></div>';
        }
        /**
         * Render the horizontal fretboard visualization
         */
        renderFretboard() {
            const currentChord = this.getCurrentChordName();
            const voicing = currentChord && this.song.chordVoicings ? this.song.chordVoicings[currentChord] : null;
            
            const numFrets = this.fretboardFrets;
            // Strings from top to bottom: high E (0) to low E (5) - standard view
            const stringNames = ['e', 'B', 'G', 'D', 'A', 'E'];
            const fretMarkers = [3, 5, 7, 9, 12];
            
            // Parse voicing data
            let frets = [];
            let fingers = [];
            let position = 1;
            
            if (voicing) {
                position = voicing.position || 1;
                // Use shared parser for multi-digit fret support
                frets = (typeof SbnChordCard !== 'undefined' && SbnChordCard.parseFretString)
                    ? SbnChordCard.parseFretString(voicing.frets || '', position)
                    : (voicing.frets ? voicing.frets.split('') : []);
                fingers = voicing.fingers ? voicing.fingers.split('') : [];
            }
            
            // Determine right-hand finger assignments based on which strings are played
            // Convention: p (pulgar/thumb) = bass, i (index), m (middle), a (ring) for upper strings
            const rightHandFingers = this.getRightHandFingers(frets);
            
            let html = `<div class="sbn-fretboard" data-position="${position}">`;
            
            // Fretboard grid
            html += '<div class="sbn-fretboard-grid">';
            
            // String labels column (high E at top)
            html += '<div class="sbn-fretboard-string-labels">';
            stringNames.forEach((name, displayIdx) => {
                const stringIdx = 5 - displayIdx;
                const isHighlighted = this.fretboardHighlightStrings.includes(stringIdx);
                html += `<div class="sbn-fretboard-string-label${isHighlighted ? ' is-highlighted' : ''}" data-string="${stringIdx}">${name}</div>`;
            });
            html += '</div>';
            
            // Open strings column (fret 0)
            html += '<div class="sbn-fretboard-open-fret">';
            stringNames.forEach((name, displayIdx) => {
                const stringIdx = 5 - displayIdx;
                const fretValue = frets[stringIdx];
                const isOpen = fretValue === 0 || fretValue === '0';
                const isMuted = fretValue === 'x' || fretValue === 'X';
                const isHighlighted = this.isPlaying && this.fretboardHighlightStrings.includes(stringIdx);
                
                html += `<div class="sbn-fretboard-string sbn-fretboard-open-string${isOpen ? ' is-open' : ''}${isMuted ? ' is-muted' : ''}${isHighlighted ? ' is-highlighted' : ''}" data-string="${stringIdx}">`;
                if (isOpen) {
                    html += '<div class="sbn-fretboard-open-dot"></div>';
                }
                html += '</div>';
            });
            html += '</div>';
            
            // Nut
            html += '<div class="sbn-fretboard-nut"></div>';
            
            // Frets
            html += '<div class="sbn-fretboard-frets">';
            for (let f = 1; f <= numFrets; f++) {
                const hasMarker = fretMarkers.includes(f);
                const isDoubleMarker = f === 12;
                
                html += `<div class="sbn-fretboard-fret${hasMarker ? ' has-marker' : ''}${isDoubleMarker ? ' double-marker' : ''}" data-fret="${f}">`;
                
                // Strings within this fret (top to bottom = high E to low E)
                stringNames.forEach((name, displayIdx) => {
                    const stringIdx = 5 - displayIdx;
                    const fretValue = frets[stringIdx];
                    const fingerValue = fingers[stringIdx];
                    const fretNum = parseInt(fretValue);
                    const hasDot = !isNaN(fretNum) && fretNum === f;
                    const isHighlighted = this.isPlaying && this.fretboardHighlightStrings.includes(stringIdx);
                    
                    html += `<div class="sbn-fretboard-string${hasDot ? ' has-dot' : ''}${isHighlighted ? ' is-highlighted' : ''}" data-string="${stringIdx}">`;
                    if (hasDot) {
                        const fingerNum = parseInt(fingerValue);
                        const fingerDisplay = fingerNum > 0 ? fingerNum : '';
                        html += `<div class="sbn-fretboard-dot${fingerDisplay ? ' has-finger' : ''}">${fingerDisplay}</div>`;
                    }
                    html += '</div>';
                });
                
                // Fret number (only show certain ones)
                if (f === 1 || f === 3 || f === 5 || f === 7 || f === 9 || f === 12) {
                    html += `<div class="sbn-fretboard-fret-num">${f}</div>`;
                }
                html += '</div>';
            }
            html += '</div>'; // .sbn-fretboard-frets
            
            // Right-hand fingering column (p, i, m, a)
            html += '<div class="sbn-fretboard-rh-fingers">';
            stringNames.forEach((name, displayIdx) => {
                const stringIdx = 5 - displayIdx;
                const rhFinger = rightHandFingers[stringIdx] || '';
                const isHighlighted = this.isPlaying && this.fretboardHighlightStrings.includes(stringIdx);
                const isMuted = frets[stringIdx] === 'x' || frets[stringIdx] === 'X';
                
                html += `<div class="sbn-fretboard-rh-finger${isHighlighted ? ' is-highlighted' : ''}${isMuted ? ' is-muted' : ''}" data-string="${stringIdx}">${rhFinger}</div>`;
            });
            html += '</div>';
            
            html += '</div>'; // .sbn-fretboard-grid
            html += '</div>'; // .sbn-fretboard
            
            return html;
        }
        
        /**
         * Determine right-hand finger assignments for current chord voicing
         * Returns object mapping string index to finger letter (p, i, m, a)
         * String indices: 0=low E, 1=A, 2=D, 3=G, 4=B, 5=high e
         */
        getRightHandFingers(frets) {
            const fingers = {};
            if (!frets || frets.length === 0) return fingers;
            
            // Find which strings are played (not muted)
            const playedStrings = [];
            frets.forEach((fret, idx) => {
                if (fret !== 'x' && fret !== 'X') {
                    playedStrings.push(idx);
                }
            });
            
            // Sort by string number ascending (0=low E first, 5=high e last)
            playedStrings.sort((a, b) => a - b);
            
            if (playedStrings.length === 0) return fingers;
            
            // Thumb (p) gets the lowest pitched string (lowest index = bass)
            const bassString = playedStrings[0];
            fingers[bassString] = 'p';
            
            // Upper strings get i, m, a (from lowest to highest of remaining)
            const upperStrings = playedStrings.slice(1);
            const fingerNames = ['i', 'm', 'a'];
            
            // Take the last 3 strings (highest pitched) for i, m, a
            const trebleStrings = upperStrings.slice(-3);
            trebleStrings.forEach((stringIdx, i) => {
                fingers[stringIdx] = fingerNames[i];
            });
            
            return fingers;
        }
        
        /**
         * Get the current chord name based on playback position
         */
        getCurrentChordName() {
            if (!this.song.sections) return null;
            
            let measureCount = 0;
            for (const section of this.song.sections) {
                for (const measure of section.measures || []) {
                    if (measureCount === this.currentMeasure) {
                        const chords = measure.chords || [];
                        if (chords.length === 0) return null;
                        
                        // Figure out which chord in the measure based on subbeat
                        const numChords = chords.length;
                        const subBeatsPerChord = 8 / numChords;
                        const chordIdx = Math.floor(this.currentSubBeat / subBeatsPerChord);
                        const chord = chords[Math.min(chordIdx, numChords - 1)];
                        return chord ? chord.name : null;
                    }
                    measureCount++;
                }
            }
            return null;
        }

        /**
         * Get the next chord name from the current playback position.
         * Looks ahead in the current measure first, then next measure.
         */
        getNextChordName() {
            if (!this.song.sections) return null;

            let measureCount = 0;
            for (const section of this.song.sections) {
                for (let mi = 0; mi < (section.measures || []).length; mi++) {
                    const measure = section.measures[mi];
                    if (measureCount === this.currentMeasure) {
                        const chords = measure.chords || [];
                        if (chords.length === 0) return null;

                        const numChords = chords.length;
                        const subBeatsPerChord = 8 / numChords;
                        const chordIdx = Math.floor(this.currentSubBeat / subBeatsPerChord);

                        // Next chord in same measure?
                        if (chordIdx + 1 < numChords) {
                            return chords[chordIdx + 1].name;
                        }

                        // First chord of next measure
                        let nextMeasure = null;
                        let nm = measureCount + 1;
                        // Walk forward through remaining measures in this section
                        for (let j = mi + 1; j < section.measures.length; j++) {
                            nextMeasure = section.measures[j];
                            break;
                        }
                        // If not found, try next section
                        if (!nextMeasure) {
                            let foundSection = false;
                            for (const s of this.song.sections) {
                                if (foundSection && s.measures && s.measures.length) {
                                    nextMeasure = s.measures[0];
                                    break;
                                }
                                if (s === section) foundSection = true;
                            }
                        }
                        if (nextMeasure && nextMeasure.chords && nextMeasure.chords.length) {
                            return nextMeasure.chords[0].name;
                        }
                        return null;
                    }
                    measureCount++;
                }
            }
            return null;
        }

        /**
         * Build pitch map for a voicing: [{midi, label, note, string, fret}]
         * Uses interval_labels from the voicing metadata + fret positions.
         * Falls back to computing intervals from chord name if labels unavailable.
         *
         * interval_labels format: "x,R,5,b7,b3,x" — one per string (6 total),
         * where 'x' = muted string.
         */
        getVoicingPitchMap(voicing, chordName) {
            if (!voicing || !voicing.frets) return [];

            const OPEN_MIDI = [40, 45, 50, 55, 59, 64]; // strings 0-5: E A D G B e
            const NOTE_SEMI = {
                'C':0,'C#':1,'Db':1,'D':2,'D#':3,'Eb':3,'E':4,'Fb':4,
                'F':5,'E#':5,'F#':6,'Gb':6,'G':7,'G#':8,'Ab':8,
                'A':9,'A#':10,'Bb':10,'B':11,'Cb':11
            };
            const SEMI_TO_INTERVAL = {
                0:'R', 1:'b9', 2:'9', 3:'b3', 4:'3', 5:'4',
                6:'b5', 7:'5', 8:'b13', 9:'6', 10:'b7', 11:'maj7'
            };

            const position = voicing.position || 1;

            // Parse frets
            const frets = (typeof SbnChordCard !== 'undefined' && SbnChordCard.parseFretString)
                ? SbnChordCard.parseFretString(voicing.frets, position)
                : voicing.frets.split('');

            // interval_labels: always 6 entries, one per string (includes 'x' for muted)
            const allLabels = (voicing.intervalLabels || '').split(',');
            const hasLabels = allLabels.some(l => l && l !== 'x' && l !== 'X' && l.trim() !== '');

            // If no labels from DB, compute from chord name
            let rootSemi = null;
            if (!hasLabels && chordName) {
                // Parse root from chord name: "Dm7" → "D", "F#m7b5" → "F#", "Bbmaj7" → "Bb"
                const rootMatch = chordName.match(/^([A-G][#b]?)/);
                if (rootMatch) {
                    rootSemi = NOTE_SEMI[rootMatch[1]];
                }
            }

            const result = [];

            for (let s = 0; s < 6; s++) {
                const f = frets[s];
                if (f === 'x' || f === 'X' || f === undefined || f === null) continue;
                const fretNum = parseInt(f, 10);
                if (isNaN(fretNum)) continue;

                const midi = OPEN_MIDI[s] + fretNum;
                let label = '';

                if (hasLabels) {
                    const rawLabel = (allLabels[s] || '').trim();
                    label = (rawLabel === 'x' || rawLabel === 'X') ? '' : rawLabel;
                } else if (rootSemi !== null) {
                    // Compute interval from MIDI pitch
                    const pitchClass = ((midi % 12) - rootSemi + 12) % 12;
                    label = SEMI_TO_INTERVAL[pitchClass] || '';
                }

                result.push({
                    midi:   midi,
                    label:  label,
                    string: s,
                    fret:   fretNum,
                });
            }
            return result;
        }

        /**
         * Render rhythm grid content (without outer wrapper - used inside viz area)
         */
        renderRhythmGridContent(pattern) {
            const thumbHits = pattern.thumb.split('');
            const fingerHits = pattern.fingers.split('');
            const patternBeats = pattern.beats || 8;
            
            // Generate beat labels based on time signature and pattern length
            // 2/4 with 8 beats (16th notes): 1e+a2e+a
            // 2/4 with 16 beats: 1e+a2e+a | 1e+a2e+a
            // 4/4 with 8 beats (8th notes): 1+2+3+4+
            // 4/4 with 16 beats: 1+2+3+4+ | 1+2+3+4+
            const timeSig = this.song.timeSignature || '4/4';
            const [beatsNum] = timeSig.split('/').map(Number);
            
            let labels;
            if (beatsNum === 2) {
                // 2/4 time - 16th note subdivisions: 1e+a2e+a
                const oneBar = ['1', 'e', '+', 'a', '2', 'e', '+', 'a'];
                labels = [];
                for (let m = 0; m < this.measuresPerPattern; m++) {
                    labels.push(...oneBar);
                }
            } else {
                // 4/4 time - 8th note subdivisions: 1+2+3+4+
                const oneBar = ['1', '+', '2', '+', '3', '+', '4', '+'];
                labels = [];
                for (let m = 0; m < this.measuresPerPattern; m++) {
                    labels.push(...oneBar);
                }
            }

            let html = `<div class="sbn-leadsheet-rhythm-inner">
                <div class="sbn-leadsheet-rhythm-name">${pattern.name || 'Rhythm Pattern'}${this.measuresPerPattern > 1 ? ` <span class="sbn-rhythm-measures">(${this.measuresPerPattern} bars)</span>` : ''}</div>
                <div class="sbn-leadsheet-rhythm-grid">`;

            // Calculate current pattern position for highlighting
            const currentPatternSubbeat = this.isPlaying ? this.currentPatternSubbeat : -1;

            // Fingers row
            html += '<div class="sbn-leadsheet-rhythm-row"><span class="sbn-leadsheet-rhythm-label">Fingers</span>';
            fingerHits.forEach((hit, i) => {
                const isHit = hit === 'x' || hit === 'X';
                const isCurrent = currentPatternSubbeat === i;
                html += `<div class="sbn-leadsheet-rhythm-cell${isHit ? ' is-hit' : ''}${isCurrent ? ' is-current' : ''}">${isHit ? '●' : ''}</div>`;
            });
            html += '</div>';

            // Thumb row
            html += '<div class="sbn-leadsheet-rhythm-row"><span class="sbn-leadsheet-rhythm-label">Thumb</span>';
            thumbHits.forEach((hit, i) => {
                const isHit = hit === 'x' || hit === 'X';
                const isCurrent = currentPatternSubbeat === i;
                html += `<div class="sbn-leadsheet-rhythm-cell is-thumb${isHit ? ' is-hit' : ''}${isCurrent ? ' is-current' : ''}">${isHit ? '●' : ''}</div>`;
            });
            html += '</div>';

            // Labels row
            html += '<div class="sbn-leadsheet-rhythm-row"><span class="sbn-leadsheet-rhythm-label"></span>';
            labels.forEach((label, i) => {
                const isCurrent = currentPatternSubbeat === i;
                html += `<div class="sbn-leadsheet-rhythm-cell is-beat-num${isCurrent ? ' is-current' : ''}">${label}</div>`;
            });
            html += '</div>';

            html += '</div></div>';
            return html;
        }

        renderMeasure(measure, idx, localIdx, sectionId) {
            const isCurrent = idx === this.currentMeasure;
            const chords = measure.chords || [];
            const numChords = chords.length || 1;
            const subBeatsPerChord = 8 / numChords;
            const displayBeats = this.song.displayBeats || 4;
            const displayBeatsPerChord = displayBeats / numChords;
            
            // Check for repeat markers on this measure
            const hasRepeatStart = this.repeatMarkers && this.repeatMarkers[idx.toString()] && this.repeatMarkers[idx.toString()].start;
            const hasRepeatEnd = this.repeatMarkers && this.repeatMarkers[idx.toString()] && this.repeatMarkers[idx.toString()].end;

            // Check if this measure falls within an active progression highlight
            let highlightCat = null;
            if (this.activeProgHighlight && sectionId !== undefined && localIdx !== undefined) {
                const hl = this.activeProgHighlight;
                if (hl.sectionId === sectionId) {
                    const sectionProgs = (this.song.detectedProgressions && this.song.detectedProgressions[sectionId]) || [];
                    sectionProgs.forEach(occ => {
                        if (String(occ.progression_id) === String(hl.progressionId)) {
                            const start = parseInt(occ.start_measure);
                            const len   = parseInt(occ.length_measures);
                            if (localIdx >= start && localIdx < start + len) {
                                highlightCat = occ.category || 'jazz';
                            }
                        }
                    });
                }
            }
            
            // Build CSS classes
            let measureClasses = 'sbn-leadsheet-measure';
            if (isCurrent) measureClasses += ' is-current';
            if (hasRepeatStart) measureClasses += ' has-repeat-start';
            if (hasRepeatEnd) measureClasses += ' has-repeat-end';
            if (highlightCat) measureClasses += ' is-prog-highlighted sbn-prog-cat-' + highlightCat;

            let html = `<div class="${measureClasses}" data-measure="${idx}" data-action="seek-measure">
                <div class="sbn-leadsheet-measure-num">${idx + 1}</div>
                <div class="sbn-leadsheet-measure-content">`;

            chords.forEach((chord, ci) => {
                const startSub = ci * subBeatsPerChord;
                const endSub = (ci + 1) * subBeatsPerChord;
                const isActive = isCurrent && this.currentSubBeat >= startSub && this.currentSubBeat < endSub;
                
                let beatInChord = -1;
                if (isActive) {
                    const subInChord = this.currentSubBeat - startSub;
                    const subsPerBeat = subBeatsPerChord / displayBeatsPerChord;
                    beatInChord = Math.floor(subInChord / subsPerBeat);
                }

                const voicing = this.song.chordVoicings ? this.song.chordVoicings[chord.name] : null;

                html += `<div class="sbn-leadsheet-chord${isActive ? ' is-active' : ''}" data-chord="${chord.name}">
                    <span class="sbn-chord-symbol sbn-chord-link" data-action="chord-info" data-chord="${chord.name}">${formatChordName(chord.name)}</span>`;

                if (this.showDiagrams && voicing) {
                    html += `<div class="sbn-leadsheet-diagram-inline">${renderChordDiagram(voicing, 60, isActive && this.isPlaying)}</div>`;
                }

                // Beat dots
                html += '<div class="sbn-leadsheet-beats">';
                const numBeats = Math.round(displayBeatsPerChord);
                for (let b = 0; b < numBeats; b++) {
                    const dotActive = isActive && beatInChord === b && this.isPlaying;
                    html += `<div class="sbn-leadsheet-beat${dotActive ? ' is-current' : ''}"></div>`;
                }
                html += '</div></div>';
            });

            html += '</div></div>';
            return html;
        }

        renderProgressionPanel(sectionIdx) {
            const song = this.song;
            const section = song.sections[sectionIdx];
            if (!section) return '';
            const sectionProgs = (song.detectedProgressions && song.detectedProgressions[section.id])
                ? song.detectedProgressions[section.id] : [];

            // De-duplicate by progression_id, collect occurrence counts
            const byId = {};
            sectionProgs.forEach(p => {
                if (!byId[p.progression_id]) byId[p.progression_id] = { ...p, count: 0 };
                byId[p.progression_id].count++;
            });
            const progs = Object.values(byId);
            if (!progs.length) return '';

            const config = typeof sbnLeadsheetConfig !== 'undefined' ? sbnLeadsheetConfig : null;
            const libUrl = config ? config.progressionLibraryUrl : '';

            let html = '<div class="sbn-prog-panel" data-section-prog-panel="' + sectionIdx + '">';
            html += '<button class="sbn-prog-panel-close" data-action="close-prog-panel" data-section-idx="' + sectionIdx + '">×</button>';
            html += '<div class="sbn-prog-panel-header">Chord Progressions — ' + this.escapeHtml(section.id || section.name || 'Section') + '</div>';
            html += '<div class="sbn-prog-panel-list">';

            progs.forEach(p => {
                const numerals = (p.numerals || '').split(',');
                const numeralHtml = numerals.map(n => '<span class="sbn-prog-numeral-chip">' + this.escapeHtml(n.trim()) + '</span>').join('');
                const countBadge = p.count > 1 ? '<span class="sbn-prog-panel-count">×' + p.count + ' in section</span>' : '';
                const viewLink = libUrl
                    ? '<a class="sbn-prog-panel-link" href="' + libUrl + '#prog-' + p.progression_id + '" target="_blank">View in library →</a>'
                    : '';
                const isHl = this.activeProgHighlight &&
                    String(this.activeProgHighlight.progressionId) === String(p.progression_id) &&
                    this.activeProgHighlight.sectionId === section.id;
                const hlBtnLabel = isHl ? '&#9724; Hide highlights' : '&#9724; Highlight in score';

                html += '<div class="sbn-prog-panel-item sbn-prog-cat-' + (p.category || 'jazz') + (isHl ? ' is-highlighted' : '') + '">';
                html += '<div class="sbn-prog-panel-item-header">';
                html += '<span class="sbn-prog-panel-name">' + this.escapeHtml(p.name) + '</span>';
                html += '<span class="sbn-prog-cat-badge sbn-prog-cat-' + (p.category || 'jazz') + '">' + (p.category || 'jazz') + '</span>';
                html += countBadge;
                html += '</div>';
                html += '<div class="sbn-prog-panel-numerals">' + numeralHtml + '</div>';
                if (p.description) {
                    html += '<div class="sbn-prog-panel-desc">' + this.escapeHtml(p.description) + '</div>';
                }
                html += '<div class="sbn-prog-panel-footer">';
                html += '<button class="sbn-prog-highlight-btn' + (isHl ? ' is-active' : '') + '" data-action="highlight-prog" data-section-idx="' + sectionIdx + '" data-section-id="' + this.escapeHtml(section.id) + '" data-prog-id="' + p.progression_id + '">' + hlBtnLabel + '</button>';
                if (viewLink) html += viewLink;
                html += '</div>';
                html += '</div>';
            });

            html += '</div></div>';
            return html;
        }

        updateProgPanel(sectionIdx) {
            // Remove any existing prog panel
            this.container.querySelectorAll('.sbn-prog-panel').forEach(el => el.remove());
            if (this.sectionProgVisible && this.sectionProgVisible[sectionIdx]) {
                const sectionEl = this.container.querySelector('.sbn-leadsheet-section[data-section="' + sectionIdx + '"]');
                if (sectionEl) {
                    sectionEl.insertAdjacentHTML('afterbegin', this.renderProgressionPanel(sectionIdx));
                }
            }
        }

        renderControls() {
            const anyPlaying = this.isPlaying || this.tabIsPlaying;
            const rp = this.song && this.song.rhythmPattern;
            const hasPerc = rp && (rp.percTop !== 'none' || rp.percBass !== 'none');

            const layerControls = hasPerc ? `
                <div class="sbn-leadsheet-layers">
                    <label class="sbn-layer-ctrl" title="Percussion backing track">
                        <button class="sbn-layer-toggle${this.percEnabled ? ' is-on' : ''}" data-action="toggle-perc">🥁</button>
                        <input type="range" class="sbn-layer-vol" min="0" max="100" value="${Math.round(this.percVol * 100)}" data-action="vol-perc" aria-label="Percussion volume">
                    </label>
                    <label class="sbn-layer-ctrl" title="Guitar chord playback">
                        <button class="sbn-layer-toggle${this.guitarEnabled ? ' is-on' : ''}" data-action="toggle-guitar">🎸</button>
                        <input type="range" class="sbn-layer-vol" min="0" max="100" value="${Math.round(this.guitarVol * 100)}" data-action="vol-guitar" aria-label="Guitar volume">
                    </label>
                    <div class="sbn-layer-ghost">
                        <span class="sbn-ghost-min">basic</span>
                        <input type="range" class="sbn-ghost-slider" min="0" max="100" value="${Math.round(this.ghostDensity * 100)}" data-action="ghost-density" aria-label="Ghost note density">
                        <span class="sbn-ghost-max">full band</span>
                    </div>
                </div>` : '';

            return `<div class="sbn-leadsheet-controls">
                <button class="sbn-leadsheet-play${anyPlaying ? ' is-playing' : ''}" data-action="play-pause" title="${this.playbackMode === 'tab' ? 'Play tab' : 'Play rhythm'}">
                    ${anyPlaying ? '❚❚' : '▶'}
                </button>
                <div class="sbn-leadsheet-progress">
                    <span class="sbn-leadsheet-bar-display">Bar ${this.currentMeasure + 1}/${this.totalMeasures}</span>
                    <input type="range" class="sbn-leadsheet-slider" min="0" max="${this.totalMeasures - 1}" value="${this.currentMeasure}" data-action="seek">
                </div>
                <div class="sbn-leadsheet-tempo">
                    <span class="sbn-leadsheet-tempo-icon">♩</span>
                    <input type="range" class="sbn-leadsheet-tempo-slider" min="60" max="200" value="${this.tempo}" data-action="tempo">
                    <span class="sbn-leadsheet-tempo-value">${this.tempo}</span>
                </div>
                ${layerControls}
                <button class="sbn-leadsheet-loop${this.loopEnabled ? ' is-active' : ''}" data-action="toggle-loop">⟳</button>
                <span class="sbn-audio-status" title="Audio not started">🔇</span>
            </div>`;
        }

        renderDetailPanel() {
            const name = this.selectedChord;
            const voicing = this.song.chordVoicings ? this.song.chordVoicings[name] : null;
            const config = typeof sbnLeadsheetConfig !== 'undefined' ? sbnLeadsheetConfig : null;
            const libraryUrl = config ? config.chordLibraryUrl : '';
            const songLibraryUrl = config ? config.songLibraryUrl : '';

            // Parse chord for quality-based library link
            const qualityMatch = name ? name.match(/^[A-G][#b]?(.*)$/) : null;
            const quality = qualityMatch ? qualityMatch[1] || '' : '';
            const qualityLabel = (typeof SbnChordCard !== 'undefined' && quality)
                ? (SbnChordCard.QUALITY_LABELS[quality] || quality)
                : quality;

            let html = `<div class="sbn-leadsheet-detail">
                <button class="sbn-leadsheet-detail-close" data-action="close-detail">×</button>
                <div class="sbn-leadsheet-detail-name">
                    <span class="sbn-chord-symbol">${formatChordName(name)}</span>
                </div>`;

            // Song voicing diagram
            if (voicing) {
                html += `<div class="sbn-leadsheet-detail-diagram">${renderChordDiagram(voicing, 110, true)}</div>`;
            }

            // Links
            html += `<div class="sbn-leadsheet-detail-links">`;

            // 1. More info — opens chord library modal directly
            if (libraryUrl) {
                html += `<a class="sbn-leadsheet-detail-link" href="${libraryUrl}?chord=${encodeURIComponent(name)}&modal=1" target="_blank">
                    <span class="sbn-detail-link-icon">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/></svg>
                    </span>
                    About this chord
                </a>`;
            }

            // 2. More voicings of same quality
            if (libraryUrl && quality) {
                html += `<a class="sbn-leadsheet-detail-link" href="${libraryUrl}?chord=${encodeURIComponent(name)}" target="_blank">
                    <span class="sbn-detail-link-icon">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M4 8h4V4H4v4zm6 12h4v-4h-4v4zm-6 0h4v-4H4v4zm0-6h4v-4H4v4zm6 0h4v-4h-4v4zm6-10v4h4V4h-4zm-6 4h4V4h-4v4zm6 6h4v-4h-4v4zm0 6h4v-4h-4v4z"/></svg>
                    </span>
                    More ${qualityLabel || name} voicings
                </a>`;
            }

            // 3. Songs with this chord
            if (songLibraryUrl) {
                html += `<a class="sbn-leadsheet-detail-link" href="${songLibraryUrl}?chord=${encodeURIComponent(name)}" target="_blank">
                    <span class="sbn-detail-link-icon">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M12 3v10.55c-.59-.34-1.27-.55-2-.55-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4V7h4V3h-6z"/></svg>
                    </span>
                    Songs with ${name}
                </a>`;
            }

            // 4. Browse library
            if (libraryUrl) {
                html += `<a class="sbn-leadsheet-detail-link sbn-leadsheet-detail-link-subtle" href="${libraryUrl}" target="_blank">
                    <span class="sbn-detail-link-icon">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M10 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg>
                    </span>
                    Browse chord library
                </a>`;
            }

            html += `</div></div>`;
            return html;
        }

        /**
         * Fetch library voicings for the selected chord and render as cards.
         * (Kept for potential future use but not called by current detail panel)
         */
        fetchLibraryVoicings(chordName) {
            const config = typeof sbnLeadsheetConfig !== 'undefined' ? sbnLeadsheetConfig : null;
            if (!config || !config.ajaxUrl || !config.chordSearchNonce) return;

            if (this.chordLibraryCache[chordName]) {
                this.renderLibraryCards(chordName, this.chordLibraryCache[chordName]);
                return;
            }

            const formData = new FormData();
            formData.append('action', 'sbn_search_chords');
            formData.append('nonce', config.chordSearchNonce);
            formData.append('query', chordName);

            fetch(config.ajaxUrl, { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.data.results) {
                        this.chordLibraryCache[chordName] = data.data.results;
                        this.renderLibraryCards(chordName, data.data.results);
                    } else {
                        this.renderLibraryEmpty(chordName);
                    }
                })
                .catch(() => {
                    this.renderLibraryEmpty(chordName);
                });
        }

        renderLibraryCards(chordName, results) {
            const container = this.container.querySelector(
                `.sbn-leadsheet-detail-library[data-chord="${chordName}"] .sbn-leadsheet-detail-library-cards`
            );
            if (!container || !results || results.length === 0) return;

            const shown = results.slice(0, 6);
            container.innerHTML = '';

            shown.forEach(chord => {
                if (typeof SbnChordCard === 'undefined') return;
                const card = SbnChordCard.createCard({
                    name: chord.name || chordName,
                    nameHtml: chord.name_html || null,
                    quality: chord.quality || '',
                    voicingCategory: chord.voicing_category || '',
                    diagramData: chord.diagram_data || '',
                    startFret: chord.start_fret || 1,
                    intervalLabels: chord.interval_labels || '',
                    notes: chord.notes || '',
                    diagramId: chord.id || '',
                }, { variant: 'mini', showControls: false, showBadge: true });
                container.appendChild(card);
            });
        }

        renderLibraryEmpty(chordName) {
            const container = this.container.querySelector(
                `.sbn-leadsheet-detail-library[data-chord="${chordName}"] .sbn-leadsheet-detail-library-cards`
            );
            if (container) container.innerHTML = '<div class="sbn-leadsheet-detail-empty">No library voicings found</div>';
        }

        // --------------------------------------------------------------------
        // EVENTS (bound ONCE via delegation)
        // --------------------------------------------------------------------

        handleClick(e) {
            // Close detail panel when clicking anywhere outside it
            // (but not when clicking the panel itself or a chord-info link)
            if (this.selectedChord) {
                const detailPanel = e.target.closest('.sbn-leadsheet-detail');
                const chordLink = e.target.closest('[data-action="chord-info"]');
                if (!detailPanel && !chordLink) {
                    this.selectedChord = null;
                    this.updateDetailPanel();
                    // Don't return — let the click also do its normal thing (seek, etc.)
                }
            }

            // Handle data-action buttons
            const action = e.target.closest('[data-action]');
            if (action) {
                const actionName = action.dataset.action;
                
                // Prevent slider clicks from triggering twice
                if (actionName === 'seek' || actionName === 'tempo') {
                    return;
                }
                
                this.handleAction(actionName, e);
                return;
            }

            // Measure click (but not if clicking on a chord within it)
            const measure = e.target.closest('.sbn-leadsheet-measure');
            if (measure) {
                const idx = parseInt(measure.dataset.measure);
                this.seek(idx);
            }
        }

        handleInput(e) {
            const action = e.target.dataset.action;
            
            if (action === 'seek') {
                this.seek(parseInt(e.target.value));
            } else if (action === 'tempo') {
                this.tempo = parseInt(e.target.value);
                const display = this.container.querySelector('.sbn-leadsheet-tempo-value');
                if (display) display.textContent = this.tempo;
                if (this.isPlaying) {
                    this.stopPlayback();
                    this.startPlayback();
                }
            } else if (action === 'ghost-density') {
                this.ghostDensity = parseInt(e.target.value) / 100;
                // No master volume scaling — ghost gain is baked into playHit call
            } else if (action === 'vol-perc') {
                this.percVol = parseInt(e.target.value) / 100;
                if (window.SbnPercussion) SbnPercussion.setVolume(this.percVol);
            } else if (action === 'vol-guitar') {
                this.guitarVol = parseInt(e.target.value) / 100;
                // SbnAudio has no per-instance volume; scale via synth volume
                if (window.SbnAudio && SbnAudio.synth) {
                    // Map 0-1 to roughly -30dB to -10dB
                    const db = -30 + this.guitarVol * 20;
                    SbnAudio.synth.set({ volume: db });
                }
            }
        }

        bindEvents() {
            // Remove any existing listeners first (safety for re-initialization)
            this.container.removeEventListener('click', this._boundHandleClick);
            this.container.removeEventListener('input', this._boundHandleInput);
            
            // Add delegated event listeners ONCE on the container
            this.container.addEventListener('click', this._boundHandleClick);
            this.container.addEventListener('input', this._boundHandleInput);
        }

        handleAction(action, e) {
            switch (action) {
                case 'play-pause':
                    this.togglePlayback();
                    break;
                case 'toggle-loop':
                    this.loopEnabled = !this.loopEnabled;
                    this.updateLoopButton();
                    break;
                case 'toggle-diagrams':
                    this.showDiagrams = !this.showDiagrams;
                    this.safeRender();
                    break;
                case 'toggle-rhythm':
                    this.rhythmCollapsed = !this.rhythmCollapsed;
                    this.updateRhythmCollapse();
                    break;
                case 'toggle-fullscreen':
                    this.toggleFullscreen();
                    break;
                case 'switch-view':
                    const viewTarget = e.target.closest('[data-view]');
                    if (viewTarget) {
                        this.activeView = viewTarget.dataset.view;
                        this.updateVisualizationArea();
                        if (this.activeView === 'fretboard') this.updateFretboard();
                    }
                    break;
                case 'toggle-tab':
                    // Stop whichever playback engine is currently running before switching modes
                    if (this.isPlaying) this.stopPlayback();
                    if (this.tabIsPlaying) this.stopTabPlayback();
                    this.showTab = !this.showTab;
                    this.render();
                    break;
                case 'seek-measure':
                    // Click on a measure to jump to it (works during playback too)
                    const measureEl = e.target.closest('[data-measure]');
                    if (measureEl) {
                        const targetMeasure = parseInt(measureEl.dataset.measure);
                        this.currentMeasure = targetMeasure;
                        this.currentSubBeat = 0;
                        this.updateDisplay();
                    }
                    break;
                case 'toggle-fretboard-theme':
                    this.fretboardTheme = this.fretboardTheme === 'dark' ? 'light' : 'dark';
                    this.updateVisualizationArea();
                    break;
                case 'toggle-perc':
                    this.percEnabled = !this.percEnabled;
                    // Re-render controls so button state updates
                    const percCtrlEl = this.container.querySelector('.sbn-leadsheet-controls');
                    if (percCtrlEl) percCtrlEl.outerHTML = this.renderControls();
                    this.bindEvents();
                    break;
                case 'toggle-guitar':
                    this.guitarEnabled = !this.guitarEnabled;
                    const gtCtrlEl = this.container.querySelector('.sbn-leadsheet-controls');
                    if (gtCtrlEl) gtCtrlEl.outerHTML = this.renderControls();
                    this.bindEvents();
                    break;
                case 'toggle-guide-tones':
                    this.guideToneMode = !this.guideToneMode;
                    this.updateVisualizationArea();
                    this.updateFretboard();
                    break;
                case 'close-detail':
                    this.selectedChord = null;
                    this.updateDetailPanel();
                    break;
                case 'chord-info':
                    const chordEl = e.target.closest('[data-chord]');
                    if (chordEl) {
                        const chordName = chordEl.dataset.chord;
                        // Toggle: click same chord again closes panel
                        if (this.selectedChord === chordName) {
                            this.selectedChord = null;
                        } else {
                            this.selectedChord = chordName;
                        }
                        this.updateDetailPanel();
                    }
                    break;
                case 'voicing-ext-prev':
                case 'voicing-ext-next': {
                    const btn = e.target.closest('[data-archetype]');
                    if (!btn) break;
                    const archKey = btn.dataset.archetype;
                    // Count variants for this archetype by re-scanning voicings
                    const vv = this.song.chordVoicings || {};
                    const extsSeen = new Set();
                    Object.entries(vv).forEach(([n, v]) => {
                        if (n.includes('@')) return;
                        const bq = v.baseQuality || v.quality || '';
                        const vc = v.voicingCategory || '';
                        const rs = v.rootString || '';
                        const inv = v.inversion || 'root';
                        const key = (bq && vc && rs)
                            ? [bq, vc, rs, inv].join('|')
                            : 'frets|' + (v.frets || '') + '|' + (v.position || 1);
                        if (key === archKey) extsSeen.add(v.extensions || '');
                    });
                    const total = extsSeen.size;
                    if (total <= 1) break;
                    if (!this._voicingExtState) this._voicingExtState = {};
                    const cur = this._voicingExtState[archKey] || 0;
                    this._voicingExtState[archKey] = actionName === 'voicing-ext-next'
                        ? (cur + 1) % total
                        : (cur - 1 + total) % total;
                    // Re-render just the allvoicings view
                    const vizContent = this.container.querySelector('.sbn-leadsheet-viz-content');
                    if (vizContent) vizContent.innerHTML = this.renderAllVoicingsGrid();
                    break;
                }
                case 'play-arpeggio':
                    this.playArpeggio();
                    break;
                case 'toggle-section-loop':
                    const sectionIdxStr = e.target.closest('[data-section-idx]')?.dataset.sectionIdx;
                    if (sectionIdxStr !== undefined) {
                        const sIdx = parseInt(sectionIdxStr);
                        if (this.loopingSectionIdx === sIdx) {
                            // Disable section loop
                            this.loopingSectionIdx = null;
                            this.repeatNav.clearSectionLoop();
                        } else {
                            // Enable section loop
                            this.loopingSectionIdx = sIdx;
                            this.repeatNav.loopSection(sIdx);
                            // Jump to section start
                            const section = this.song.sections[sIdx];
                            if (section) {
                                this.currentMeasure = section.startMeasure || 0;
                                this.currentSubBeat = 0;
                            }
                        }
                        this.render();
                    }
                    break;
                case 'toggle-section-collapse':
                    const collapseEl = e.target.closest('[data-section-idx]');
                    if (collapseEl) {
                        const cIdx = parseInt(collapseEl.dataset.sectionIdx);
                        this.collapsedSections[cIdx] = !this.collapsedSections[cIdx];
                        this.render();
                    }
                    break;
                case 'toggle-section-info':
                    const infoBtn = e.target.closest('[data-section-idx]');
                    if (infoBtn) {
                        const iIdx = parseInt(infoBtn.dataset.sectionIdx);
                        if (!this.sectionInfoVisible) this.sectionInfoVisible = {};
                        this.sectionInfoVisible[iIdx] = !this.sectionInfoVisible[iIdx];
                        this.render();
                    }
                    break;
                case 'toggle-section-prog': {
                    const progBtn = e.target.closest('[data-section-idx]');
                    if (progBtn) {
                        const pIdx = parseInt(progBtn.dataset.sectionIdx);
                        if (!this.sectionProgVisible) this.sectionProgVisible = {};
                        // Close all other open panels first
                        Object.keys(this.sectionProgVisible).forEach(k => {
                            if (parseInt(k) !== pIdx) this.sectionProgVisible[k] = false;
                        });
                        this.sectionProgVisible[pIdx] = !this.sectionProgVisible[pIdx];
                        // Re-render just the affected section header button state + panel
                        this.render();
                    }
                    break;
                }
                case 'highlight-prog': {
                    const pillEl = e.target.closest('[data-prog-id]');
                    if (pillEl) {
                        const progId  = pillEl.dataset.progId;
                        const secId   = pillEl.dataset.sectionId;
                        const secIdx  = pillEl.dataset.sectionIdx !== undefined
                            ? parseInt(pillEl.dataset.sectionIdx) : null;
                        // Toggle: clicking same pill again clears the highlight
                        if (this.activeProgHighlight &&
                            String(this.activeProgHighlight.progressionId) === String(progId) &&
                            this.activeProgHighlight.sectionId === secId) {
                            this.activeProgHighlight = null;
                        } else {
                            this.activeProgHighlight = { progressionId: progId, sectionId: secId };
                            // Auto-expand the section so measures are visible to highlight
                            if (secIdx !== null && this.collapsedSections && this.collapsedSections[secIdx]) {
                                this.collapsedSections[secIdx] = false;
                            }
                        }
                        this.render();
                    }
                    break;
                }
                case 'close-prog-panel': {
                    const closeBtn = e.target.closest('[data-section-idx]');
                    if (closeBtn) {
                        const cIdx = parseInt(closeBtn.dataset.sectionIdx);
                        if (this.sectionProgVisible) this.sectionProgVisible[cIdx] = false;
                        this.render();
                    }
                    break;
                }
            }
        }
        
        // Safe render that preserves fullscreen state
        safeRender() {
            if (this.isFullscreen) {
                // Exit fullscreen, render, then re-enter
                const el = this.container.querySelector('.sbn-leadsheet');
                if (document.exitFullscreen && document.fullscreenElement) {
                    document.exitFullscreen().then(() => {
                        this.render();
                        setTimeout(() => {
                            if (el && el.requestFullscreen) {
                                el.requestFullscreen().catch(() => {});
                            }
                        }, 50);
                    }).catch(() => this.render());
                } else {
                    this.render();
                }
            } else {
                this.render();
            }
        }
        
        // Update only the visualization area (fretboard/rhythm tabs)
        updateVisualizationArea() {
            const vizContainer = this.container.querySelector('.sbn-leadsheet-viz');
            if (vizContainer) {
                vizContainer.outerHTML = this.renderVisualizationArea();
            }
        }
        
        // Update only the detail panel
        updateDetailPanel() {
            const existing = this.container.querySelector('.sbn-leadsheet-detail');
            if (existing) {
                existing.remove();
            }
            
            if (this.selectedChord) {
                const leadsheet = this.container.querySelector('.sbn-leadsheet');
                if (leadsheet) {
                    leadsheet.insertAdjacentHTML('beforeend', this.renderDetailPanel());
                }
            }
        }
        
        // Targeted UI updates (no re-render needed)
        updateLoopButton() {
            const btn = this.container.querySelector('.sbn-leadsheet-loop');
            if (btn) {
                btn.classList.toggle('is-active', this.loopEnabled);
            }
        }
        
        updateRhythmCollapse() {
            const rhythm = this.container.querySelector('.sbn-leadsheet-rhythm');
            if (rhythm) {
                rhythm.classList.toggle('is-collapsed', this.rhythmCollapsed);
            }
        }

        // --------------------------------------------------------------------
        // PLAYBACK
        // --------------------------------------------------------------------

        // Returns 'tab' when tab is open and tab data exists, 'rhythm' otherwise.
        get playbackMode() {
            return (this.showTab && this.hasTabData) ? 'tab' : 'rhythm';
        }

        async togglePlayback() {
            // Init audio on first play (shared by both modes)
            if (!this.audioReady) {
                this.audioReady = await AudioEngine.init();
            }

            // Init percussion engine once, after Tone.js is up (shares same AudioContext)
            if (!this._percInitialized && window.SbnPercussion) {
                const samplesUrl = (window.sbnLeadsheetConfig && sbnLeadsheetConfig.samplesBaseUrl)
                    ? sbnLeadsheetConfig.samplesBaseUrl
                    : '';
                if (samplesUrl) {
                    SbnPercussion.init(samplesUrl);
                    SbnPercussion.resume();
                    SbnPercussion.setVolume(this.percVol);
                }
                this._percInitialized = true;
            }

            if (this.playbackMode === 'tab') {
                if (this.tabIsPlaying) {
                    this.stopTabPlayback();
                } else {
                    this.startTabPlayback();
                }
            } else {
                if (this.isPlaying) {
                    this.stopPlayback();
                } else {
                    this.startPlayback();
                }
            }

            // Poll until sampler finishes loading, updating the status icon
            if (AudioEngine.initialized && !AudioEngine.samplerReady) {
                this._pollSamplerReady();
            }
        }

        _pollSamplerReady() {
            if (AudioEngine.samplerReady) {
                this.updatePlayButton(); // Refresh 🔇 → 🔊
                return;
            }
            this.updatePlayButton(); // Show ⏳
            setTimeout(() => this._pollSamplerReady(), 300);
        }

        startPlayback() {
            if (this.isPlaying) return; // Already playing
            
            this.isPlaying = true;
            AudioEngine.releaseAll();
            
            // Reset repeat navigation for fresh playback
            this.repeatNav.reset();
            if (this.loopingSectionIdx !== null) {
                this.repeatNav.loopSection(this.loopingSectionIdx);
            }
            
            // Calculate subdivision timing based on time signature
            const timeSig = this.song.timeSignature || '4/4';
            const beatsPerMeasure = parseInt(timeSig.split('/')[0]) || 4;
            const subbeatsPerBeat = this.subbeatsPerMeasure / beatsPerMeasure;
            const subdivisionMs = (60000 / this.tempo) / subbeatsPerBeat;
            
            // Create local copies for the tick function
            let subBeat = this.currentSubBeat;
            let measure = this.currentMeasure;
            let globalSubbeat = measure * this.subbeatsPerMeasure + subBeat;
            
            const tick = () => {
                // Get current chord — use section-aware lookup
                const chordName = this.getChordAtPosition(measure, subBeat);
                const voicing = chordName && this.song.chordVoicings ? this.song.chordVoicings[chordName] : null;

                // Calculate pattern position for multi-measure patterns
                // Use section-specific rhythm pattern if available
                const currentRhythm = this.getRhythmForMeasure(measure);
                const patternMeasureIndex = measure % this.measuresPerPattern;
                const patternSubbeat = patternMeasureIndex * this.subbeatsPerMeasure + subBeat;

                // Play rhythm pattern sounds — two independent layers
                if (currentRhythm) {
                    const { thumb, fingers } = currentRhythm;
                    const cell      = fingers[patternSubbeat] || '.';
                    const thumbCell = thumb[patternSubbeat]   || '.';
                    const isFingers = cell.toLowerCase()      === 'x';
                    const isAccent  = cell                    === 'X';
                    const isThumb   = thumbCell.toLowerCase() === 'x';

                    // --- Guitar layer (demonstration) ---
                    if (this.guitarEnabled && voicing) {
                        if (isThumb)   AudioEngine.playBass(voicing);
                        if (isFingers) AudioEngine.playFingers(voicing);
                    }

                    // --- Percussion layer (backing track) ---
                    // Mirrors RhythmPlayer.playSynthBeat() exactly:
                    // perc_top: hits on finger cells (accent-aware) + ghost notes on empty cells
                    // perc_bass: hits on thumb cells only, never ghosted
                    if (this.percEnabled && window.SbnPercussion && SbnPercussion.ready) {
                        const percTop  = currentRhythm.percTop  || 'none';
                        const percBass = currentRhythm.percBass || 'none';
                        const now = (typeof Tone !== 'undefined') ? Tone.now() : SbnPercussion._audioCtx.currentTime;

                        if (percTop !== 'none') {
                            if (isFingers) {
                                const gain = isAccent ? 1.0 : 0.78;
                                SbnPercussion.playHit(percTop, isAccent, now, gain);
                            } else if (this.ghostDensity > 0) {
                                const ghostGain = this.ghostDensity * 0.18;
                                SbnPercussion.playHit(percTop, false, now, ghostGain);
                            }
                        }

                        if (percBass !== 'none' && isThumb) {
                            SbnPercussion.playHit(percBass, false, now, 0.85);
                        }
                    }
                }
                
                // NOTE: Melody/tab audio is handled exclusively by startTabPlayback().
                // Rhythm playback only sounds the chord accompaniment pattern.

                // Update state
                this.currentSubBeat = subBeat;
                this.currentMeasure = measure;
                this.currentPatternSubbeat = patternSubbeat;
                this.updateDisplay();

                // Advance
                subBeat++;
                globalSubbeat++;
                if (subBeat >= this.subbeatsPerMeasure) {
                    subBeat = 0;
                    
                    // Use RepeatNavigator to determine next measure
                    const nextMeasure = this.repeatNav.getNextMeasure(measure);
                    
                    if (nextMeasure === -1) {
                        // Song complete
                        if (this.loopEnabled) {
                            measure = 0;
                            globalSubbeat = 0;
                            this.repeatNav.reset();
                            if (this.loopingSectionIdx !== null) {
                                this.repeatNav.loopSection(this.loopingSectionIdx);
                            }
                        } else {
                            this.stopPlayback();
                            return;
                        }
                    } else {
                        // If we jumped (not linear), recalculate globalSubbeat for melody
                        if (nextMeasure !== measure + 1) {
                            globalSubbeat = nextMeasure * this.subbeatsPerMeasure;
                        }
                        measure = nextMeasure;
                    }
                }
            };

            // First tick immediately
            tick();
            
            // Then set interval
            this.playbackTimer = setInterval(tick, subdivisionMs);

            // Update UI
            this.updatePlayButton();
        }

        stopPlayback() {
            if (this.playbackTimer) {
                clearInterval(this.playbackTimer);
                this.playbackTimer = null;
            }
            
            this.isPlaying = false;
            AudioEngine.releaseAll();
            
            // Update UI
            this.updatePlayButton();
        }

        // --------------------------------------------------------------------
        // TAB PLAYBACK ENGINE
        // Tab playback is independent of rhythm playback. It uses the same
        // subbeat clock resolution but drives audio from tab note data rather
        // than chord voicings + rhythm patterns.
        // --------------------------------------------------------------------

        startTabPlayback() {
            if (this.tabIsPlaying || !this.hasTabData) return;

            this.tabIsPlaying = true;
            AudioEngine.releaseAll();

            // Reset repeat navigation
            this.repeatNav.reset();
            if (this.loopingSectionIdx !== null) {
                this.repeatNav.loopSection(this.loopingSectionIdx);
            }

            // Build a flat tick-indexed map from tabData for fast lookup.
            // Each entry: tick (absolute) → [ { pitch, octave, ticks, voice } ]
            // We rebuild this lazily if not cached.
            if (!this._tabTickMap) {
                this._buildTabTickMap();
            }

            const timeSig = this.song.timeSignature || '4/4';
            const beatsPerMeasure = parseInt(timeSig.split('/')[0]) || 4;
            const ticksPerBeat = 480;
            const ticksPerBeatUnit = ticksPerBeat * (4 / (parseInt(timeSig.split('/')[1]) || 4));
            const ticksPerMeasure = ticksPerBeatUnit * beatsPerMeasure;
            const subbeatsPerBeat = this.subbeatsPerMeasure / beatsPerMeasure;
            const subdivisionMs = (60000 / this.tempo) / subbeatsPerBeat;

            // Ticks per subbeat — maps our subbeat grid onto MIDI tick space
            const ticksPerSubbeat = ticksPerMeasure / this.subbeatsPerMeasure;

            let measure = this.tabCurrentMeasure;
            let subBeat = this.tabCurrentSubBeat;

            const tick = () => {
                // Absolute tick for this subbeat position
                const absoluteTick = measure * ticksPerMeasure + subBeat * ticksPerSubbeat;

                // Find and play all tab notes that start within this subbeat window
                const windowStart = absoluteTick;
                const windowEnd = absoluteTick + ticksPerSubbeat;

                if (this._tabTickMap) {
                    const events = this._tabTickMap.getInRange(windowStart, windowEnd);
                    if (events && events.length > 0) {
                        this._playTabNotes(events);
                    }
                }

                // Update display state so highlighting tracks with tab playback
                this.currentMeasure = measure;
                this.currentSubBeat = subBeat;
                this.tabCurrentMeasure = measure;
                this.tabCurrentSubBeat = subBeat;
                this.updateDisplay();

                // Advance
                subBeat++;
                if (subBeat >= this.subbeatsPerMeasure) {
                    subBeat = 0;
                    const nextMeasure = this.repeatNav.getNextMeasure(measure);
                    if (nextMeasure === -1) {
                        if (this.loopEnabled) {
                            measure = 0;
                            this.repeatNav.reset();
                            if (this.loopingSectionIdx !== null) {
                                this.repeatNav.loopSection(this.loopingSectionIdx);
                            }
                        } else {
                            this.stopTabPlayback();
                            return;
                        }
                    } else {
                        measure = nextMeasure;
                    }
                }
            };

            tick();
            this.tabPlaybackTimer = setInterval(tick, subdivisionMs);
            this.updatePlayButton();
        }

        stopTabPlayback() {
            if (this.tabPlaybackTimer) {
                clearInterval(this.tabPlaybackTimer);
                this.tabPlaybackTimer = null;
            }
            this.tabIsPlaying = false;
            AudioEngine.releaseAll();
            this.updatePlayButton();
        }

        /**
         * Build a range-queryable tick map from tabData for tab playback.
         * Stored as this._tabTickMap with a getInRange(start, end) method.
         */
        _buildTabTickMap() {
            // Flat array of { tick, pitch, octave, ticks, voice } sorted by tick
            const events = [];
            if (!this.song.melody) { this._tabTickMap = { getInRange: () => [] }; return; }

            this.song.melody.forEach(note => {
                if (note.isRest || !note.pitch) return;
                // Skip pure tieStop notes — they were already sounding
                if (note.tieStop && !note.tieStart) return;
                events.push({
                    tick: note.tick,
                    ticks: note.ticks,
                    pitch: note.pitch,
                    octave: note.octave,
                    duration: note.duration,
                    voice: note.voice || 1
                });
            });

            events.sort((a, b) => a.tick - b.tick);

            this._tabTickMap = {
                events,
                getInRange(start, end) {
                    // Binary search for start, then collect until end
                    let lo = 0, hi = events.length;
                    while (lo < hi) {
                        const mid = (lo + hi) >> 1;
                        if (events[mid].tick < start) lo = mid + 1;
                        else hi = mid;
                    }
                    const result = [];
                    for (let i = lo; i < events.length && events[i].tick < end; i++) {
                        result.push(events[i]);
                    }
                    return result;
                }
            };
        }

        /**
         * Play a set of tab note events through the audio engine.
         * Notes are grouped by voice; each voice fires with a tiny time offset
         * to simulate natural finger independence on the guitar.
         */
        _playTabNotes(events) {
            if (!AudioEngine.initialized) return;

            const durMap = {
                'w': '1n', 'wd': '1n.',
                'h': '2n', 'hd': '2n.',
                'q': '4n', 'qd': '4n.',
                'e': '8n', 'ed': '8n.',
                's': '16n', 'sd': '16n.',
                't': '32n'
            };

            // Group by voice so notes within a voice fire together
            const byVoice = {};
            events.forEach(ev => {
                const v = ev.voice || 1;
                if (!byVoice[v]) byVoice[v] = [];
                byVoice[v].push(ev);
            });

            const now = Tone.now();

            Object.entries(byVoice).forEach(([voice, notes], voiceIdx) => {
                // ~10ms offset between voices — imperceptible as separate attacks,
                // but avoids exact sample-simultaneous triggers on the same buffer
                const voiceOffset = voiceIdx * 0.012;
                const noteNames = notes.map(n => n.pitch + n.octave);
                const dur = durMap[notes[0].duration] || '8n';
                AudioEngine.playTabNotes(noteNames, dur, now + voiceOffset);
            });
        }

        seek(measureIndex) {
            if (this.playbackMode === 'tab') {
                // Seek in tab playback
                const wasTabPlaying = this.tabIsPlaying;
                if (wasTabPlaying) this.stopTabPlayback();
                this.tabCurrentMeasure = measureIndex;
                this.tabCurrentSubBeat = 0;
                this.currentMeasure = measureIndex; // Keep in sync for display
                this.currentSubBeat = 0;
                this.updateDisplay();
            } else {
                // Seek in rhythm playback
                if (this.isPlaying) this.stopPlayback();
                this.currentMeasure = measureIndex;
                this.currentSubBeat = 0;
                this.updateDisplay();
            }
        }

        getChordAtPosition(measureIdx, subBeat) {
            let count = 0;
            for (const section of this.song.sections) {
                for (const measure of section.measures || []) {
                    if (count === measureIdx) {
                        const chords = measure.chords || [];
                        const numChords = chords.length || 1;
                        const subBeatsPerChord = 8 / numChords;
                        const chordIdx = Math.floor(subBeat / subBeatsPerChord);
                        const chord = chords[Math.min(chordIdx, numChords - 1)];
                        return chord ? chord.name : null;
                    }
                    count++;
                }
            }
            return null;
        }
        
        /**
         * Get the active rhythm pattern for a given measure.
         * Section-specific rhythms override the song default.
         */
        getRhythmForMeasure(measureIdx) {
            // Find which section this measure belongs to
            if (this.song.sections) {
                for (let i = this.song.sections.length - 1; i >= 0; i--) {
                    const section = this.song.sections[i];
                    const start = section.startMeasure || 0;
                    if (measureIdx >= start) {
                        // Section-specific rhythm takes priority
                        if (section.rhythmPattern) return section.rhythmPattern;
                        break;
                    }
                }
            }
            // Fall back to song-level rhythm
            return this.song.rhythmPattern || null;
        }

        updateDisplay() {
            // Bar display
            const barDisplay = this.container.querySelector('.sbn-leadsheet-bar-display');
            if (barDisplay) {
                barDisplay.textContent = `Bar ${this.currentMeasure + 1}/${this.totalMeasures}`;
            }

            // Slider
            const slider = this.container.querySelector('.sbn-leadsheet-slider');
            if (slider) {
                slider.value = this.currentMeasure;
            }

            // Clear ALL chord highlights first, then apply current
            this.container.querySelectorAll('.sbn-leadsheet-chord.is-active').forEach(el => {
                el.classList.remove('is-active');
            });
            
            // Clear all beat highlights
            this.container.querySelectorAll('.sbn-leadsheet-beat.is-current').forEach(el => {
                el.classList.remove('is-current');
            });

            // Measure highlights — use data-measure attribute, not DOM order index.
            // DOM order includes empty placeholder cells which would shift the count.
            this.container.querySelectorAll('.sbn-leadsheet-measure[data-measure]').forEach(el => {
                const measureIdx = parseInt(el.dataset.measure);
                el.classList.toggle('is-current', measureIdx === this.currentMeasure);
                // Re-apply progression highlight class if it was lost (don't stomp it)
                // The is-prog-highlighted class is set during render(); we just preserve it here.
            });

            // Chord highlights within current measure only
            const currentMeasureEl = this.container.querySelector(`.sbn-leadsheet-measure[data-measure="${this.currentMeasure}"]`);
            if (currentMeasureEl) {
                const chords = currentMeasureEl.querySelectorAll('.sbn-leadsheet-chord');
                const numChords = chords.length || 1;
                const subBeatsPerChord = 8 / numChords;
                
                // Get displayBeats from song data
                const displayBeats = this.song.displayBeats || 4;
                const displayBeatsPerChord = displayBeats / numChords;
                
                chords.forEach((el, ci) => {
                    const start = ci * subBeatsPerChord;
                    const end = (ci + 1) * subBeatsPerChord;
                    const isActive = this.currentSubBeat >= start && this.currentSubBeat < end;
                    el.classList.toggle('is-active', isActive);
                    
                    // Update beat dots within this chord
                    if (isActive && this.isPlaying) {
                        const beatDots = el.querySelectorAll('.sbn-leadsheet-beat');
                        const subBeatInChord = this.currentSubBeat - start;
                        const subsPerDisplayBeat = subBeatsPerChord / displayBeatsPerChord;
                        const currentBeatInChord = Math.floor(subBeatInChord / subsPerDisplayBeat);
                        
                        beatDots.forEach((dot, bi) => {
                            dot.classList.toggle('is-current', bi === currentBeatInChord);
                        });
                    }
                });
            }

            // Rhythm cells - handle the 3 rows (fingers, thumb, labels)
            // Use currentPatternSubbeat for multi-measure patterns
            const patternSubbeat = this.currentPatternSubbeat !== undefined ? this.currentPatternSubbeat : this.currentSubBeat;
            const rhythmRows = this.container.querySelectorAll('.sbn-leadsheet-rhythm-row');
            rhythmRows.forEach(row => {
                const cells = row.querySelectorAll('.sbn-leadsheet-rhythm-cell');
                cells.forEach((cell, idx) => {
                    cell.classList.toggle('is-current', this.isPlaying && idx === patternSubbeat);
                });
            });
            
            // Update fretboard if visible
            this.updateFretboard();
            
            // Update tab highlighting if in tab view
            this.updateTabHighlighting();
        }
        
        /**
         * Update the fretboard visualization during playback
         */
        updateFretboard() {
            const fretboard = this.container.querySelector('.sbn-fretboard');
            if (!fretboard) return;
            
            const currentChord = this.getCurrentChordName();
            const voicing = currentChord && this.song.chordVoicings ? this.song.chordVoicings[currentChord] : null;
            
            // Clear all dots and highlights
            fretboard.querySelectorAll('.sbn-fretboard-dot').forEach(el => el.remove());
            fretboard.querySelectorAll('.sbn-fretboard-open-dot').forEach(el => el.remove());
            fretboard.querySelectorAll('.sbn-fretboard-ghost-dot').forEach(el => el.remove());
            fretboard.querySelectorAll('.sbn-gt-arrows').forEach(el => el.remove());
            fretboard.querySelectorAll('.sbn-fretboard-string').forEach(el => {
                el.classList.remove('has-dot', 'is-highlighted', 'is-open', 'is-muted');
            });
            fretboard.querySelectorAll('.sbn-fretboard-string-label').forEach(el => {
                el.classList.remove('is-highlighted');
            });
            fretboard.querySelectorAll('.sbn-fretboard-rh-finger').forEach(el => {
                el.classList.remove('is-highlighted');
                el.textContent = '';
            });
            
            if (!voicing || !voicing.frets) return;
            
            const position = voicing.position || 1;
            const frets = (typeof SbnChordCard !== 'undefined' && SbnChordCard.parseFretString)
                ? SbnChordCard.parseFretString(voicing.frets, position)
                : voicing.frets.split('');
            const fingers = voicing.fingers ? voicing.fingers.split('') : [];
            
            // Get right-hand finger assignments
            const rightHandFingers = this.getRightHandFingers(frets);
            
            // Determine which strings to highlight based on rhythm
            // Use pattern subbeat for multi-measure patterns
            const patternSubbeat = this.currentPatternSubbeat !== undefined ? this.currentPatternSubbeat : this.currentSubBeat;
            let highlightStrings = [];
            if (this.isPlaying && this.song.rhythmPattern) {
                const pattern = this.song.rhythmPattern;
                const thumbHit = pattern.thumb[patternSubbeat] === 'x' || pattern.thumb[patternSubbeat] === 'X';
                const fingerHit = pattern.fingers[patternSubbeat] === 'x' || pattern.fingers[patternSubbeat] === 'X';
                
                // Use actual right-hand finger assignments to determine which strings to highlight
                if (thumbHit || fingerHit) {
                    Object.entries(rightHandFingers).forEach(([stringIdx, finger]) => {
                        const idx = parseInt(stringIdx);
                        if (finger === 'p' && thumbHit) {
                            highlightStrings.push(idx);
                        } else if ((finger === 'i' || finger === 'm' || finger === 'a') && fingerHit) {
                            highlightStrings.push(idx);
                        }
                    });
                }
            }
            
            // Build pitch map for current voicing (for guide tone mode)
            const currentPitchMap = this.guideToneMode ? this.getVoicingPitchMap(voicing, currentChord) : [];

            // Update open strings column
            frets.forEach((fretValue, stringIdx) => {
                const openStringEl = fretboard.querySelector(`.sbn-fretboard-open-string[data-string="${stringIdx}"]`);
                if (!openStringEl) return;
                
                const isOpen = fretValue === 0 || fretValue === '0';
                const isMuted = fretValue === 'x' || fretValue === 'X';
                const isHighlighted = highlightStrings.includes(stringIdx);
                
                openStringEl.classList.toggle('is-open', isOpen);
                openStringEl.classList.toggle('is-muted', isMuted);
                openStringEl.classList.toggle('is-highlighted', isHighlighted);
                
                if (isOpen) {
                    const dot = document.createElement('div');
                    dot.className = 'sbn-fretboard-open-dot';
                    if (isHighlighted) dot.classList.add('is-highlighted');

                    // Guide tone mode: color open dots too
                    const pitchInfo = currentPitchMap.find(p => p.string === stringIdx && p.fret === 0);
                    if (this.guideToneMode && pitchInfo && pitchInfo.label) {
                        const label = pitchInfo.label;
                        if (label === '3' || label === 'b3') dot.classList.add('gt-third');
                        else if (label === 'b7' || label === '7' || label === 'maj7') dot.classList.add('gt-seventh');
                        else if (label === 'R') dot.classList.add('gt-root');
                        else if (label === '9' || label === 'b9') dot.classList.add('gt-ninth');
                        else if (label === '5') dot.classList.add('gt-fifth');
                        dot.textContent = label;
                        dot.style.fontSize = '7px';
                        dot.style.fontWeight = '800';
                    }

                    openStringEl.appendChild(dot);
                }
            });
            
            // Update string labels - highlight entire string when played
            highlightStrings.forEach(stringIdx => {
                const label = fretboard.querySelector(`.sbn-fretboard-string-label[data-string="${stringIdx}"]`);
                if (label) label.classList.add('is-highlighted');
                
                // Highlight entire string across all frets
                fretboard.querySelectorAll(`.sbn-fretboard-string[data-string="${stringIdx}"]`).forEach(el => {
                    el.classList.add('is-highlighted');
                });
            });
            
            // Update right-hand finger indicators
            Object.entries(rightHandFingers).forEach(([stringIdx, finger]) => {
                const rhEl = fretboard.querySelector(`.sbn-fretboard-rh-finger[data-string="${stringIdx}"]`);
                if (rhEl) {
                    rhEl.textContent = finger;
                    if (highlightStrings.includes(parseInt(stringIdx))) {
                        rhEl.classList.add('is-highlighted');
                    }
                }
            });

            // Add dots on fretboard
            frets.forEach((fretValue, stringIdx) => {
                const fretNum = parseInt(fretValue);
                if (isNaN(fretNum) || fretNum === 0) return;
                
                const fretEl = fretboard.querySelector(`.sbn-fretboard-fret[data-fret="${fretNum}"]`);
                if (!fretEl) return;
                
                const stringEl = fretEl.querySelector(`.sbn-fretboard-string[data-string="${stringIdx}"]`);
                if (!stringEl) return;
                
                stringEl.classList.add('has-dot');
                
                const isHighlighted = highlightStrings.includes(stringIdx);
                stringEl.classList.toggle('is-highlighted', isHighlighted);
                
                // Create dot
                const dot = document.createElement('div');
                dot.className = 'sbn-fretboard-dot';
                if (isHighlighted) dot.classList.add('is-highlighted');

                // Guide tone mode: color-code by interval function
                const pitchInfo = currentPitchMap.find(p => p.string === stringIdx && p.fret === fretNum);
                if (this.guideToneMode && pitchInfo) {
                    const label = pitchInfo.label;
                    if (label === '3' || label === 'b3') {
                        dot.classList.add('gt-third');
                        dot.textContent = label;
                    } else if (label === 'b7' || label === '7' || label === 'maj7') {
                        dot.classList.add('gt-seventh');
                        dot.textContent = label;
                    } else if (label === 'R') {
                        dot.classList.add('gt-root');
                        dot.textContent = 'R';
                    } else if (label === '9' || label === 'b9') {
                        dot.classList.add('gt-ninth');
                        dot.textContent = label;
                    } else if (label === '5') {
                        dot.classList.add('gt-fifth');
                        dot.textContent = '5';
                    } else {
                        // Non-guide tone: show label but muted
                        dot.textContent = label || '';
                    }
                } else {
                    const fingerNum = parseInt(fingers[stringIdx]);
                    if (fingerNum > 0) {
                        dot.classList.add('has-finger');
                        dot.textContent = fingerNum;
                    }
                }
                stringEl.appendChild(dot);
            });

            // ── Guide Tone Ghost Dots: show next chord's resolution targets ──
            if (this.guideToneMode) {
                this.renderGuideToneGhosts(fretboard, voicing, currentPitchMap);
            }
        }

        /**
         * Render ghost dots showing where current guide tones resolve to
         * in the next chord voicing.
         */
        renderGuideToneGhosts(fretboard, currentVoicing, currentPitchMap) {
            const nextChordName = this.getNextChordName();
            if (!nextChordName) return;

            const nextVoicing = this.song.chordVoicings ? this.song.chordVoicings[nextChordName] : null;
            if (!nextVoicing) return;

            const nextPitchMap = this.getVoicingPitchMap(nextVoicing, nextChordName);
            if (!nextPitchMap.length) return;

            // Find resolution pairs: current guide tone → nearest next-chord target
            const SEVENTH = { 'b7': 1, '7': 1, 'maj7': 1 };
            const THIRD   = { '3': 1, 'b3': 1 };
            const ROOT    = { 'R': 1 };

            const pairs = [];

            // b7 → 3/b3 of next
            const currSevenths = currentPitchMap.filter(p => SEVENTH[p.label]);
            const nextThirds   = nextPitchMap.filter(p => THIRD[p.label]);

            currSevenths.forEach(seventh => {
                let best = null, bestDist = 99;
                nextThirds.forEach(third => {
                    const d = Math.abs(seventh.midi - third.midi);
                    if (d < bestDist) { bestDist = d; best = third; }
                });
                if (best && bestDist <= 7) {
                    pairs.push({ from: seventh, to: best, type: 'seventh-to-third' });
                }
            });

            // 3 → R, b7, or maj7 of next
            const currThirds  = currentPitchMap.filter(p => THIRD[p.label]);
            const nextRoots   = nextPitchMap.filter(p => ROOT[p.label]);
            const nextSevenths = nextPitchMap.filter(p => SEVENTH[p.label]);

            currThirds.forEach(third => {
                let best = null, bestDist = 99;
                [...nextRoots, ...nextSevenths].forEach(target => {
                    const d = Math.abs(third.midi - target.midi);
                    if (d < bestDist) { bestDist = d; best = target; }
                });
                if (best && bestDist <= 7) {
                    pairs.push({ from: third, to: best, type: 'third-to-root' });
                }
            });

            // Render ghost dots and connection lines
            pairs.forEach(pair => {
                const toFret = pair.to.fret;
                const toString = pair.to.string;

                if (toFret === 0) {
                    // Ghost in open string column
                    const openEl = fretboard.querySelector(`.sbn-fretboard-open-string[data-string="${toString}"]`);
                    if (openEl) {
                        const ghost = document.createElement('div');
                        ghost.className = 'sbn-fretboard-ghost-dot ' + pair.type;
                        ghost.textContent = pair.to.label;
                        openEl.appendChild(ghost);
                    }
                } else {
                    const fretEl = fretboard.querySelector(`.sbn-fretboard-fret[data-fret="${toFret}"]`);
                    if (!fretEl) return;
                    const stringEl = fretEl.querySelector(`.sbn-fretboard-string[data-string="${toString}"]`);
                    if (!stringEl) return;

                    const ghost = document.createElement('div');
                    ghost.className = 'sbn-fretboard-ghost-dot ' + pair.type;
                    ghost.textContent = pair.to.label;
                    stringEl.appendChild(ghost);
                }

                // Draw SVG arrow between from-dot and to-ghost
                this.drawGuideToneArrow(fretboard, pair);
            });
        }

        /**
         * Draw an SVG arrow on the fretboard connecting a guide tone to its resolution target.
         */
        drawGuideToneArrow(fretboard, pair) {
            const fromFret = pair.from.fret;
            const fromString = pair.from.string;
            const toFret = pair.to.fret;
            const toString = pair.to.string;

            // Find DOM positions
            const getPos = (stringIdx, fretNum) => {
                if (fretNum === 0) {
                    const el = fretboard.querySelector(`.sbn-fretboard-open-string[data-string="${stringIdx}"]`);
                    return el;
                }
                const fretEl = fretboard.querySelector(`.sbn-fretboard-fret[data-fret="${fretNum}"]`);
                if (!fretEl) return null;
                return fretEl.querySelector(`.sbn-fretboard-string[data-string="${stringIdx}"]`);
            };

            const fromEl = getPos(fromString, fromFret);
            const toEl = getPos(toString, toFret);
            if (!fromEl || !toEl) return;

            // Get positions relative to the fretboard grid
            const gridEl = fretboard.querySelector('.sbn-fretboard-grid');
            if (!gridEl) return;

            const gridRect = gridEl.getBoundingClientRect();
            const fromRect = fromEl.getBoundingClientRect();
            const toRect = toEl.getBoundingClientRect();

            const x1 = fromRect.left + fromRect.width / 2 - gridRect.left;
            const y1 = fromRect.top + fromRect.height / 2 - gridRect.top;
            const x2 = toRect.left + toRect.width / 2 - gridRect.left;
            const y2 = toRect.top + toRect.height / 2 - gridRect.top;

            // Create or find SVG overlay
            let svg = fretboard.querySelector('.sbn-gt-arrows');
            if (!svg) {
                svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                svg.setAttribute('class', 'sbn-gt-arrows');
                svg.style.cssText = 'position:absolute;top:0;left:0;width:100%;height:100%;pointer-events:none;z-index:10;overflow:visible;';
                // Ensure the grid is positioned
                if (getComputedStyle(gridEl).position === 'static') {
                    gridEl.style.position = 'relative';
                }
                gridEl.appendChild(svg);
            }

            // Set viewBox to match grid dimensions
            svg.setAttribute('viewBox', `0 0 ${gridRect.width} ${gridRect.height}`);

            const color = pair.type === 'seventh-to-third' ? '#f59e0b' : '#3b82f6';

            const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
            line.setAttribute('x1', x1);
            line.setAttribute('y1', y1);
            line.setAttribute('x2', x2);
            line.setAttribute('y2', y2);
            line.setAttribute('stroke', color);
            line.setAttribute('stroke-width', '2');
            line.setAttribute('stroke-dasharray', '4 3');
            line.setAttribute('opacity', '0.7');
            svg.appendChild(line);

            // Arrowhead
            const angle = Math.atan2(y2 - y1, x2 - x1);
            const headLen = 7;
            const arrow = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
            const ax = x2 - headLen * Math.cos(angle - 0.4);
            const ay = y2 - headLen * Math.sin(angle - 0.4);
            const bx = x2 - headLen * Math.cos(angle + 0.4);
            const by = y2 - headLen * Math.sin(angle + 0.4);
            arrow.setAttribute('points', `${x2},${y2} ${ax},${ay} ${bx},${by}`);
            arrow.setAttribute('fill', color);
            arrow.setAttribute('opacity', '0.7');
            svg.appendChild(arrow);
        }
        
        /**
         * Highlight a string on the fretboard (kept for compatibility)
         */
        highlightFretboardString(fretboard, stringIdx) {
            // Now handled directly in updateFretboard
        }

        updatePlayButton() {
            // Either playback engine being active counts as "playing" for the UI
            const anyPlaying = this.isPlaying || this.tabIsPlaying;
            const btn = this.container.querySelector('.sbn-leadsheet-play');
            if (btn) {
                btn.classList.toggle('is-playing', anyPlaying);
                btn.innerHTML = anyPlaying ? '❚❚' : '▶';
            }
            
            const status = this.container.querySelector('.sbn-audio-status');
            if (status) {
                const samplerReady = AudioEngine.samplerReady;
                const engineInit = AudioEngine.initialized;
                // Three states: not started (🔇), loading samples (⏳), ready (🔊)
                const label = !engineInit ? '🔇' : (!samplerReady ? '⏳' : '🔊');
                status.classList.toggle('is-ready', samplerReady);
                status.textContent = label;
                status.title = !engineInit ? 'Audio not started' : (!samplerReady ? 'Loading guitar samples…' : 'Guitar samples ready');
            }
        }

        async playArpeggio() {
            if (!this.audioReady) {
                this.audioReady = await AudioEngine.init();
            }
            if (this.selectedChord && this.song.chordVoicings) {
                const voicing = this.song.chordVoicings[this.selectedChord];
                if (voicing) {
                    AudioEngine.playArpeggio(voicing);
                }
            }
        }

        toggleFullscreen() {
            const el = this.container.querySelector('.sbn-leadsheet');
            if (!el) return;

            if (!this.isFullscreen) {
                if (el.requestFullscreen) {
                    el.requestFullscreen().catch(() => {});
                } else if (el.webkitRequestFullscreen) {
                    el.webkitRequestFullscreen();
                }
            } else {
                if (document.exitFullscreen && document.fullscreenElement) {
                    document.exitFullscreen().catch(() => {});
                } else if (document.webkitExitFullscreen && document.webkitFullscreenElement) {
                    document.webkitExitFullscreen();
                }
            }
            // Note: actual state change is handled by the fullscreenchange listener
        }
        
        handleFullscreenChange() {
            const isNowFullscreen = !!(document.fullscreenElement || document.webkitFullscreenElement);
            this.isFullscreen = isNowFullscreen;
            
            const el = this.container.querySelector('.sbn-leadsheet');
            if (el) {
                el.classList.toggle('is-fullscreen', this.isFullscreen);
            }
            
            const btn = this.container.querySelector('.sbn-fullscreen-btn');
            if (btn) {
                btn.innerHTML = this.isFullscreen ? '⊠' : '⛶';
            }
        }

        // Clean up
        destroy() {
            this.stopPlayback();
            this.container.removeEventListener('click', this._boundHandleClick);
            this.container.removeEventListener('input', this._boundHandleInput);
            document.removeEventListener('fullscreenchange', this._boundHandleFullscreenChange);
            document.removeEventListener('webkitfullscreenchange', this._boundHandleFullscreenChange);
            this.container.innerHTML = '';
        }
    }

    // ========================================================================
    // INITIALIZATION
    // ========================================================================

    function initLeadsheetPlayers() {
        document.querySelectorAll('.sbn-leadsheet-container').forEach(container => {
            if (container.dataset.initialized) return;
            
            try {
                new SBNLeadsheetPlayer(container);
                container.dataset.initialized = 'true';
            } catch (e) {
                console.error('[SBN Leadsheet] Init failed:', e);
            }
        });
    }

    function initLeadsheetsInContainer(scope) {
        if (!scope) return;
        scope.querySelectorAll('.sbn-leadsheet-container').forEach(container => {
            if (container.dataset.initialized) return;
            
            try {
                new SBNLeadsheetPlayer(container);
                container.dataset.initialized = 'true';
            } catch (e) {
                console.error('[SBN Leadsheet] Init failed:', e);
            }
        });
    }

    // DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => setTimeout(initLeadsheetPlayers, 100));
    } else {
        setTimeout(initLeadsheetPlayers, 100);
    }

    // Export for external use (AJAX/lazy loading)
    window.SBNLeadsheetPlayer = SBNLeadsheetPlayer;
    window.initSBNLeadsheetPlayers = initLeadsheetPlayers;
    window.initSBNLeadsheetsInContainer = initLeadsheetsInContainer;

})();
