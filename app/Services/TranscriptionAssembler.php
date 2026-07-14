<?php

namespace App\Services;

use App\Services\VoicingCrossref;
use Illuminate\Support\Facades\Log;

/**
 * Assembles a raw basic-pitch transcription result (beat-grid + note list)
 * into a leadsheet's tab/melody/chord/videoSync data.
 *
 * Extracted from Admin/LeadsheetController (SBN-Security-Audit-2026-07-09.md
 * finding #5) — this cluster had zero dependency on the controller's
 * constructor-injected properties or any other controller helper, so the
 * move is a pure relocation with no behavior change. Entry points used by
 * the controller: resolveDetectionParams(), assembleTranscription(),
 * melodyPositionHints(). Everything else here is a private implementation
 * detail of assembleTranscription().
 */
class TranscriptionAssembler
{
    private function timeToTicks($time, $beatTimes)
    {
        $ppq = 480;
        $numBeats = count($beatTimes);
        if ($time <= $beatTimes[0]) return 0;
        
        $lo = 0; $hi = $numBeats - 1;
        while ($lo < $hi - 1) {
            $mid = (int)(($lo + $hi) / 2);
            if ($beatTimes[$mid] <= $time) $lo = $mid;
            else $hi = $mid;
        }
        
        $aTime = $beatTimes[$lo];
        $bTime = $lo + 1 < $numBeats ? $beatTimes[$lo + 1] : $aTime + 0.5;
        
        $t = ($time - $aTime) / max(0.01, $bTime - $aTime);
        return (int)round(($lo + $t) * $ppq);
    }

    private function midiToNote($midi)
    {
        $names = ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'];
        return [
            'pitch' => $names[$midi % 12],
            'octave' => (int)floor($midi / 12) - 1
        ];
    }

    private function midiToTab($midi)
    {
        $stringPitches = [64, 59, 55, 50, 45, 40]; // 1(e) to 6(E)
        foreach ($stringPitches as $i => $base) {
            $fret = $midi - $base;
            if ($fret >= 0 && $fret <= 15) {
                return ['string' => $i + 1, 'fret' => $fret];
            }
        }
        return ['string' => 6, 'fret' => max(0, $midi - 40)];
    }

    private function ticksToDuration($ticks)
    {
        if ($ticks >= 1920) return 'w';
        if ($ticks >= 960)  return 'h';
        if ($ticks >= 480)  return 'q';
        if ($ticks >= 240)  return 'e';
        return 's';
    }

    private function quantizeToStandard(int $ticks): int
    {
        // Restricted to regular subdivisions (no dotted notes)
        $standards = [1920, 960, 480, 240, 120];
        $best = 120;
        $bestDiff = PHP_INT_MAX;
        foreach ($standards as $s) {
            $diff = abs($ticks - $s);
            // Bias: favor larger duration to fill gaps and align with beat boundaries
            if ($diff < $bestDiff || ($diff === $bestDiff && $s > $best)) {
                $bestDiff = $diff;
                $best = $s;
            }
        }
        return $best;
    }

    /**
     * Bass-snap beat correction — detect bass-note onsets and use them as
     * metric anchors to rebuild a tempo-following beat_times grid.
     *
     * Rationale: in jazz solo guitar the bass note (thumb) keeps far steadier
     * time than the rubato melody above it. beat_track() drifts on rubato; the
     * bass onsets do not. We don't assume each bass note is a downbeat — a bass
     * note may land on any quarter. Instead each bass onset is snapped to the
     * nearest subdivision of beat_track's *provisional* grid, producing
     * (audioTime → gridTick) anchor pairs. beat_track only needs to be locally
     * accurate to within an 8th note for the snap to pick the right
     * subdivision; anchoring then resets the accumulated drift at every bass
     * note. beat_times is rebuilt by interpolating between anchors.
     *
     * @param array $notes       Raw note events: [{ start, end, pitch }, …]
     * @param array $beatTimes   Provisional beat_track grid (one entry/quarter)
     * @return array  Corrected beat_times, or the original if too few anchors.
     */
    private function bassSnapBeatTimes(array $notes, array $beatTimes): array
    {
        if (count($beatTimes) < 4 || empty($notes)) {
            return $beatTimes;
        }

        // ── 1. Cluster note onsets; take the lowest pitch per cluster as bass.
        // Onsets within 70 ms are one attack (a strummed chord). The lowest
        // sounding pitch of that attack is the thumb / bass note.
        $sorted = $notes;
        usort($sorted, fn($a, $b) => $a['start'] <=> $b['start']);

        $clusters = [];               // [{ time, pitch }]
        $clusterStart = null;
        $clusterLowPitch = null;
        $clusterLowTime  = null;
        foreach ($sorted as $n) {
            if ($clusterStart === null || ($n['start'] - $clusterStart) > 0.07) {
                if ($clusterStart !== null) {
                    $clusters[] = ['time' => $clusterLowTime, 'pitch' => $clusterLowPitch];
                }
                $clusterStart    = $n['start'];
                $clusterLowPitch = $n['pitch'];
                $clusterLowTime  = $n['start'];
            } elseif ($n['pitch'] < $clusterLowPitch) {
                $clusterLowPitch = $n['pitch'];
                $clusterLowTime  = $n['start'];
            }
        }
        if ($clusterStart !== null) {
            $clusters[] = ['time' => $clusterLowTime, 'pitch' => $clusterLowPitch];
        }

        // ── 2. Drop only clusters whose lowest note is implausibly high — a
        // melody-only moment with no thumb note to anchor on. The lowest note
        // of a cluster already *is* the bass; we keep all of them and let
        // interpolation ride across the dropped (anchorless) gaps. The ceiling
        // is the median cluster pitch + 7 semitones, so a bass-heavy section
        // (all low) keeps every anchor while a pure single-line run is skipped.
        $pitches = array_map(fn($c) => $c['pitch'], $clusters);
        sort($pitches);
        $median = $pitches[(int)floor(count($pitches) / 2)] ?? 60;
        $ceil   = $median + 7;
        $bassOnsets = [];
        foreach ($clusters as $c) {
            if ($c['pitch'] <= $ceil) $bassOnsets[] = $c['time'];
        }
        if (count($bassOnsets) < 3) {
            return $beatTimes;   // not enough bass to anchor — keep beat_track
        }

        // ── 3. Snap each bass onset to the nearest 8th-note subdivision of the
        // provisional grid. The grid index is in *quarter* units; an 8th is 0.5.
        $lastIdx = count($beatTimes) - 1;
        $anchors = [];   // [ gridPos(float, quarter units) => audioTime ]
        foreach ($bassOnsets as $t) {
            if ($t < $beatTimes[0] || $t > $beatTimes[$lastIdx]) continue;

            // Locate the provisional quarter interval containing this onset.
            $lo = 0; $hi = $lastIdx;
            while ($lo < $hi - 1) {
                $mid = (int)(($lo + $hi) / 2);
                if ($beatTimes[$mid] <= $t) $lo = $mid; else $hi = $mid;
            }
            $span = max(1e-4, $beatTimes[$lo + 1] - $beatTimes[$lo]);
            $frac = ($t - $beatTimes[$lo]) / $span;          // 0..1 within quarter
            $gridPos = $lo + (round($frac * 2) / 2);          // snap to 8th
            // Keep the closest onset per grid position (strongest anchor wins
            // ties simply by replacement — order is already time-sorted).
            $anchors[(string)$gridPos] = $t;
        }
        if (count($anchors) < 3) {
            return $beatTimes;
        }

        return $this->buildCorrectedBeatTimes($anchors, $beatTimes);
    }

    /**
     * Rebuild beat_times by piecewise-linear interpolation between bass
     * anchors. Each anchor pins a grid position (in quarter units) to an audio
     * time; quarter positions between/around anchors are interpolated, so the
     * grid follows local tempo and melody-only stretches ride smoothly across
     * the gap between the nearest anchored bass notes.
     *
     * @param array $anchors    [ gridPos(string float) => audioTime ]
     * @param array $fallback   Provisional grid, used to size the output.
     * @return array  One audio time per quarter-note grid index.
     */
    private function buildCorrectedBeatTimes(array $anchors, array $fallback): array
    {
        // Sorted anchor list of [gridPos => time].
        $pts = [];
        foreach ($anchors as $pos => $time) {
            $pts[] = ['pos' => (float)$pos, 'time' => (float)$time];
        }
        usort($pts, fn($a, $b) => $a['pos'] <=> $b['pos']);

        $count = count($fallback);
        $out   = [];
        for ($q = 0; $q < $count; $q++) {
            // Find the anchor pair bracketing quarter index $q.
            $before = null; $after = null;
            foreach ($pts as $p) {
                if ($p['pos'] <= $q) $before = $p;
                if ($p['pos'] >= $q && $after === null) $after = $p;
            }

            if ($before && $after && $before['pos'] !== $after['pos']) {
                // Interpolate between the two bracketing anchors.
                $t = ($q - $before['pos']) / ($after['pos'] - $before['pos']);
                $out[$q] = $before['time'] + $t * ($after['time'] - $before['time']);
            } elseif ($before && $after && $before['pos'] === $after['pos']) {
                $out[$q] = $before['time'];
            } else {
                // $q lies outside the anchored range — extrapolate at the local
                // tempo of the nearest anchor pair.
                $ref = $before ?: $after;       // the single nearest anchor
                $pair = $before
                    ? [$pts[count($pts) - 2] ?? $pts[0], end($pts)]
                    : [$pts[0], $pts[1] ?? end($pts)];
                $p0 = $pair[0]; $p1 = $pair[1];
                $secPerQuarter = ($p1['pos'] !== $p0['pos'])
                    ? ($p1['time'] - $p0['time']) / ($p1['pos'] - $p0['pos'])
                    : 0.5;
                $out[$q] = $ref['time'] + ($q - $ref['pos']) * $secPerQuarter;
            }
        }

        // Front-edge clamp: extrapolating before the first anchor can run the
        // leading beats to negative audio time (no audio exists there). Clamp
        // any negative entry to 0 — the monotonic pass below then re-spaces the
        // collapsed run into a small non-negative ramp.
        for ($q = 0; $q < $count; $q++) {
            if ($out[$q] < 0) $out[$q] = 0.0;
            else break;
        }

        // Guarantee monotonic non-decreasing times (interpolation can't break
        // this, but extrapolation / front-clamp can collapse leading entries).
        for ($q = 1; $q < $count; $q++) {
            if ($out[$q] <= $out[$q - 1]) {
                $out[$q] = $out[$q - 1] + 0.01;
            }
        }
        return $out;
    }

    /**
     * Re-group note events into per-beat pitch buckets against a (corrected)
     * beat_times grid. Mirrors the bucketing transcribe.py does in Python — it
     * must be re-run in PHP after bass-snap rewrites beat_times, otherwise the
     * chord region detector reads buckets aligned to the stale grid.
     *
     * @param array $notes      Raw note events: [{ start, end, pitch }, …]
     * @param array $beatTimes  Corrected grid, one entry per quarter note.
     * @return array  beats[] in Python's shape: { start, end, notes, note_durations }
     */
    private function rebucketBeats(array $notes, array $beatTimes, ?array $filter = null): array
    {
        // Default 50 ms floor matches transcribe.py's MIN_NOTE_DURATION. The
        // optional $filter (T9 live detection tuning) overrides it and can add a
        // MIDI range clamp, so the chord-region buckets can be re-derived from the
        // cached full note set without re-running Python. Null $filter ⇒ exactly
        // the original behaviour (regression-safe).
        $minDur   = isset($filter['min_note_length_ms']) ? ((float)$filter['min_note_length_ms'] / 1000.0) : 0.05;
        $midiMin  = isset($filter['midi_min']) ? (int)$filter['midi_min'] : null;
        $midiMax  = isset($filter['midi_max']) ? (int)$filter['midi_max'] : null;
        $count = count($beatTimes);
        $beats = [];

        for ($i = 0; $i < $count; $i++) {
            $startT = $beatTimes[$i];
            $endT   = $i + 1 < $count
                ? $beatTimes[$i + 1]
                : $startT + ($beatTimes[$i] - ($beatTimes[$i - 1] ?? $startT - 0.5));

            // Lowest duration kept per pitch (dedupe within the beat).
            $pitchDur = [];
            foreach ($notes as $n) {
                $dur = $n['end'] - $n['start'];
                if ($n['start'] >= $startT && $n['start'] < $endT && $dur >= $minDur) {
                    $p = (int)$n['pitch'];
                    if ($midiMin !== null && $p < $midiMin) continue;
                    if ($midiMax !== null && $p > $midiMax) continue;
                    if (!isset($pitchDur[$p]) || $dur > $pitchDur[$p]) {
                        $pitchDur[$p] = $dur;
                    }
                }
            }

            $beats[] = [
                'start'          => $startT,
                'end'            => $endT,
                'notes'          => array_map('intval', array_keys($pitchDur)),
                'note_durations' => $pitchDur,
            ];
        }
        return $beats;
    }

    /**
     * Resolve basic-pitch detection knobs from the import modal's inputs.
     *
     * The modal offers a preset (balanced / sensitive / strict) for the common
     * cases plus optional fine-tune sliders. basic-pitch's defaults
     * (onset 0.5, frame 0.3, minNoteLen ~128ms) are tuned for clearly-
     * articulated input and badly under-detect soft / legato / orchestral
     * material — that is what the 'sensitive' preset addresses.
     *
     *   balanced  — basic-pitch defaults; dense, clearly-picked recordings.
     *   sensitive — lower thresholds; recovers soft jazz-guitar / legato runs.
     *   strict    — higher thresholds; rejects reverb tails / false positives.
     *
     * A 'custom' preset (or any slider present) lets explicit values win over
     * the preset baseline. restrict_guitar_range clamps detection to the
     * 6-string range (~E2..~E6), trimming sub-bass rumble and cymbal noise.
     *
     * Also folds in `separate_stem` — a legacy control flag (not a basic-pitch
     * param) for the one-shot path that isolates the guitar stem via Demucs
     * before transcription. Defaults FALSE now that stem separation is an
     * explicit two-phase step in the modal (separate → audition → transcribe);
     * the audition path sends `stem_session` and bypasses this entirely.
     *
     * Returns only the keys the user actually changed from basic-pitch's own
     * defaults (plus separate_stem), so an untouched modal yields
     * ['separate_stem' => false] and Python uses its defaults otherwise.
     */
    public function resolveDetectionParams(array $validated): array
    {
        $presets = [
            'balanced'  => ['onset_threshold' => 0.5,  'frame_threshold' => 0.3,  'minimum_note_length' => 127.7],
            'sensitive' => ['onset_threshold' => 0.3,  'frame_threshold' => 0.18, 'minimum_note_length' => 58.0],
            'strict'    => ['onset_threshold' => 0.7,  'frame_threshold' => 0.45, 'minimum_note_length' => 160.0],
        ];

        $preset = $validated['detection_preset'] ?? 'balanced';
        $base   = $presets[$preset] ?? $presets['balanced'];

        // Explicit slider values override the preset baseline.
        foreach (['onset_threshold', 'frame_threshold', 'minimum_note_length'] as $k) {
            if (isset($validated[$k]) && $validated[$k] !== '' && $validated[$k] !== null) {
                $base[$k] = (float) $validated[$k];
            }
        }

        // Optional: clamp detection to a 6-string guitar's pitch range.
        // E2 ≈ 82 Hz, two octaves above the 12th-fret high-E ≈ 1320 Hz.
        if (!empty($validated['restrict_guitar_range'])) {
            $base['minimum_frequency'] = 80.0;
            $base['maximum_frequency'] = 1320.0;
        }

        // Drop keys still equal to basic-pitch's defaults so an untouched
        // modal sends nothing and Python keeps its built-in behaviour.
        $defaults = ['onset_threshold' => 0.5, 'frame_threshold' => 0.3, 'minimum_note_length' => 127.7];
        foreach ($defaults as $k => $def) {
            if (isset($base[$k]) && abs($base[$k] - $def) < 1e-9) {
                unset($base[$k]);
            }
        }

        // Stem separation control flag for the LEGACY one-shot path (no
        // audition session). Defaults FALSE now that separation is an explicit
        // opt-in step in the modal: if the admin didn't run "Separate stems",
        // transcribe the raw audio rather than surprise-isolating the guitar.
        // The two-phase workflow never reaches this — it sends stem_session and
        // is dispatched to transcribeFromSession() before detection params are
        // consulted for separation.
        $base['separate_stem'] = array_key_exists('separate_stem', $validated)
            ? !empty($validated['separate_stem'])
            : false;

        return $base;
    }

    /**
     * Assemble a raw Python transcription result into a standard Analysis array.
     *
     * Owns the beat→bar grouping, chord region detection, melody reconstruction
     * and videoSync mapping. Called once on initial import (offset 0) and again
     * by reshiftDownbeat() whenever the user re-picks the downbeat.
     *
     * @param array $rawResult  Raw Python output: beats[], notes[], beat_times[], tempo
     * @param array $opts       title, key, youtube_id, bass_snap, tab_position_style, separate_stem
     * @param int   $downbeatOffset  0..1919 — tick position (relative to the
     *              first busy beat; 480 = 1 quarter) of the true musical "1".
     *              When > 0, the content before it is kept as a leading pickup
     *              bar (a full measure with leading rests), so no content is
     *              lost. Sub-beat values re-PHASE the rhythm onto the grid —
     *              note ticks are re-snapped to the 120-tick lattice after the
     *              shift, they are not left de-quantized.
     */
    public function assembleTranscription(array $rawResult, array $opts, int $downbeatOffset, VoicingCrossref $crossref): array
    {
        $beatsPerBar = 4;

        // The cached transcriptionRaw must always hold the *original* Python
        // grid + buckets so a re-snap is reproducible. Capture them before the
        // bass-snap step mutates $rawResult.
        $origBeats     = $rawResult['beats'] ?? [];
        $origBeatTimes = $rawResult['beat_times'] ?? [];

        // ── Bass-snap beat correction (opt-in) ──────────────────────────────
        // When bass_snap is on, rebuild beat_times from bass-note anchors and
        // re-bucket beats[] against the new grid so chord region detection
        // stays aligned with the corrected timing.
        $bassSnapped = false;
        if (!empty($opts['bass_snap'])
            && !empty($rawResult['notes'])
            && !empty($rawResult['beat_times'])) {
            $corrected = $this->bassSnapBeatTimes($rawResult['notes'], $rawResult['beat_times']);
            if ($corrected !== $rawResult['beat_times']) {
                $rawResult['beat_times'] = $corrected;
                $rawResult['beats']      = $this->rebucketBeats($rawResult['notes'], $corrected);
                $bassSnapped = true;
            }
        }

        // ── Detection filter (T9 live tuning) — re-bucket chord regions ─────
        // The cached `notes` are the full unfiltered note set, so the chord-region
        // buckets can be re-derived post-hoc with a different min-note-length /
        // MIDI-range filter WITHOUT re-running basic-pitch. Only the post-filter
        // knobs live here; onset/frame thresholds need re-inference (see redetect).
        // Absent ⇒ no re-bucket, exactly the original behaviour.
        $detectionFilter = $opts['detection_filter'] ?? null;
        if (!empty($detectionFilter) && !empty($rawResult['notes']) && !empty($rawResult['beat_times'])) {
            $rawResult['beats'] = $this->rebucketBeats(
                $rawResult['notes'],
                $rawResult['beat_times'],
                $detectionFilter
            );
        }

        $analysis = [
            'title'         => $opts['title'] ?? 'Audio Transcription',
            'composer'      => '',
            'key'           => $opts['key'] ?? 'C',
            'tempo'         => (int)round($rawResult['tempo'] ?? 120),
            'timeSignature' => '4/4',
            'source_note'   => 'AI Audio Transcription from YouTube (ID: ' . ($opts['youtube_id'] ?? '') . ')',
            'sections'      => [],
            'videoSync'     => [
                'videoId'     => $opts['youtube_id'] ?? '',
                'videoType'   => 'youtube',
                'audioSource' => 'video',
                'mappings'    => [],
            ],
            // Cached *original* Python output + the settings that produced this
            // assembly, so the editor's downbeat / bass-snap tools can re-run
            // without re-transcribing. Always the pristine grid, never snapped.
            'transcriptionRaw' => [
                'beats'          => $origBeats,
                'beat_times'     => $origBeatTimes,
                'notes'          => $rawResult['notes'] ?? [],
                'tempo'          => $rawResult['tempo'] ?? 120,
                'downbeatOffset' => $downbeatOffset,
                'bassSnap'       => $bassSnapped,
                'tabPositionStyle' => $opts['tab_position_style'] ?? 'fretted',
                // Whether Demucs guitar-stem separation ran before this
                // transcription. reshiftDownbeat never re-runs Python, so it
                // just carries this flag through for the record — it doesn't
                // re-separate.
                'separateStem' => $opts['separate_stem'] ?? true,
                // If this assembly came from "Transcribe this stem", record which
                // audition session + stems it used so a later Re-run detection
                // re-inferences on the SAME isolated stem (with new onset/frame
                // thresholds) rather than silently reverting to the full-mix
                // original. Null for full-mix / original-recording transcriptions.
                // Carried through untouched by non-stem re-derives via $opts.
                'stemSource' => $opts['stem_source'] ?? null,
                // The basic-pitch knobs that produced this note set. Recorded
                // for the editor (so the user can see what was used and
                // re-import with different values if detection was off).
                'detectionParams' => $rawResult['detection_params'] ?? null,
                // T9 live-tuning post-filter (min-note-length / MIDI range) applied
                // to the cached note set at assembly time. Carried through so a
                // retune / reshift reuses it unless overridden.
                'detectionFilter' => $detectionFilter,
            ],
        ];

        $currentSection = ['label' => 'A', 'bars' => []];
        $tempBar        = ['chords' => []];
        $mappings       = [];

        // Remove leading silence by finding the first bar with musical content.
        $firstBusyBeatIdx = 0;
        foreach ($rawResult['beats'] as $idx => $beat) {
            if (!empty($beat['notes']) || !empty($beat['note_durations'])) {
                $firstBusyBeatIdx = $idx;
                break;
            }
        }
        $skipBars     = (int)floor($firstBusyBeatIdx / $beatsPerBar);
        $startBeatIdx = $skipBars * $beatsPerBar;
        $tickOffset   = $skipBars * $beatsPerBar * 480;

        // Downbeat offset → pickup padding. `$downbeatOffset` is a *tick* shift
        // (480 = 1 quarter, one bar = 1920). The chosen "1" sits `offset` ticks
        // after the first busy beat, so the grid is padded by the complement
        // (`barTicks - offset`) of leading rest-ticks: the chosen point then
        // lands on the downbeat of measure 1 and the pickup content fills the
        // tail of measure 0.
        //
        // The padding splits into two parts:
        //   $padBeats — whole pickup beats; drives bar grouping + chord regions,
        //               so it must stay an integer beat count.
        //   $padFrac  — the sub-beat remainder (0..479 ticks); a constant tick
        //               shift applied to note positions and the videoTime map.
        $barTicks       = $beatsPerBar * 480;
        $downbeatOffset = max(0, min($barTicks - 1, (int)$downbeatOffset));
        $padTicks       = $downbeatOffset > 0 ? ($barTicks - $downbeatOffset) : 0;
        $padBeats       = intdiv($padTicks, 480);
        $padFrac        = $padTicks % 480;

        // ── Non-AI path state: harmonic region grouping (Phase 2c) ──────────
        $regionPitches   = [];
        $regionStartBeat = 1;

        // ── T3: context-aware identifier plumbing ───────────────────────────
        // Each identified region is collected into an ordered sequence and,
        // after the whole grid is assembled, re-ranked as a sequence by the
        // ContextualReranker (key-fit + bigram + Viterbi — the Phase 3 engine,
        // previously only wired into the fret path). Identify-in-isolation stays
        // the first pass; the reranker only shifts *borderline* readings using
        // neighbour context. Each emitted chord entry carries a `_seq` id so its
        // label can be rewritten in place after reranking, then stripped.
        $chordSeq = [];
        $emitChord = function (array &$bar, array $regionPitches, int $beat) use ($crossref, &$chordSeq) {
            $idResult = $crossref->identifyFromMidi($regionPitches);
            if (empty($idResult['name'])) return;
            $seq = count($chordSeq);
            $chordSeq[$seq] = $idResult;
            $bar['chords'][] = ['label' => $idResult['name'], 'beat' => $beat, '_seq' => $seq];
        };

        // `g` is the padded grid index (0-based from the start of the pickup bar).
        for ($i = $startBeatIdx; $i < count($rawResult['beats']); $i++) {
            $beat = $rawResult['beats'][$i];
            $g    = ($i - $startBeatIdx) + $padBeats;

            if ($g % $beatsPerBar === 0) {
                // With a sub-beat downbeat shift the raw beat sits $padFrac
                // ticks *after* the true bar line, so interpolate the bar's
                // audio time backward toward the previous beat by that
                // fraction. ($padFrac === 0 → exact raw-beat time, as before.)
                $videoTime = (float)$beat['start'];
                if ($padFrac > 0 && $i > 0) {
                    $prevStart  = (float)$rawResult['beats'][$i - 1]['start'];
                    $videoTime -= ($beat['start'] - $prevStart) * ($padFrac / 480);
                }
                $mappings[] = [
                    'measureIndex' => (int)($g / $beatsPerBar),
                    'videoTime'    => $videoTime,
                ];
            }

            // Duration-weighted pitch selection (P1 fix)
            $rawPitches = [];
            foreach ($beat['note_durations'] ?? [] as $pitch => $dur) {
                if ($dur >= 0.1) $rawPitches[] = (int)$pitch;
            }
            if (empty($rawPitches)) $rawPitches = $beat['notes'] ?? [];

            $beatNum = ($g % $beatsPerBar) + 1;

            // Deterministic chord ID via harmonic region grouping (Phase 2c).
            // Runs for *both* paths — T4 moved chord identification entirely
            // into the deterministic engine; the AI no longer identifies
            // chords from pitch integers.
            if (empty($rawPitches)) {
                if (!empty($regionPitches)) {
                    $emitChord($tempBar, $regionPitches, $regionStartBeat);
                    $regionPitches = [];
                }
            } else {
                if (empty($regionPitches)) {
                    $regionPitches   = $rawPitches;
                    $regionStartBeat = $beatNum;
                } else {
                    $sim = $this->jaccardSimilarity($regionPitches, $rawPitches);
                    if ($sim >= 0.5) {
                        $regionPitches = array_values(array_unique(array_merge($regionPitches, $rawPitches)));
                    } else {
                        $emitChord($tempBar, $regionPitches, $regionStartBeat);
                        $regionPitches   = $rawPitches;
                        $regionStartBeat = $beatNum;
                    }
                }
            }

            // ── End of bar ──────────────────────────────────────────────────
            if (($g + 1) % $beatsPerBar === 0) {
                if (!empty($regionPitches)) {
                    $emitChord($tempBar, $regionPitches, $regionStartBeat);
                    $regionPitches = [];
                }

                if (empty($tempBar['chords'])) {
                    $tempBar['chords'][] = ['label' => '/', 'beat' => 1];
                }
                $currentSection['bars'][] = $tempBar;
                $tempBar = ['chords' => []];
            }
        }

        // Flush any trailing partial bar
        if (!empty($regionPitches)) {
            $emitChord($tempBar, $regionPitches, $regionStartBeat);
        }
        if (!empty($tempBar['chords'])) {
            $currentSection['bars'][] = $tempBar;
        }

        if (empty($currentSection['bars'])) {
            $currentSection['bars'][] = ['chords' => [['label' => '/', 'beat' => 1]]];
        }
        $analysis['sections'][] = $currentSection;
        $analysis['videoSync']['mappings'] = $mappings;

        // ── T3: sequence-level context re-identification ────────────────────
        // Re-rank the collected region results as an ordered sequence, then
        // write any reinterpreted labels back into their chord entries. The
        // reranker consumes the same per-slot shape identifyFromMidi already
        // returns (`name`, `candidates`, `pcs`, `bass_note`), so no adaptation
        // is needed. Non-fatal: on any failure the deterministic per-region
        // labels stand.
        $this->applyContextualChordReranking($analysis, $chordSeq, $crossref);

        // ── Reconstruct melody from raw MIDI notes (P0 & P1 fixes) ──────────
        $melody = [];
        if (!empty($rawResult['notes']) && !empty($rawResult['beat_times'])) {
            $ticksPerBar = $beatsPerBar * 480;

            // 1. Filter by guitar range and group by quantized tick (P0).
            //    `+ $padTicks` shifts content into measure 1, leaving the pickup
            //    bar's leading beats empty (rests inserted by the gap pass).
            //
            //    `$padTicks` carries a sub-beat remainder ($padFrac) when the
            //    user picks an off-beat note as the downbeat. A raw fractional
            //    shift would push every note off the 120/240 lattice and break
            //    duration quantization, so after shifting we re-snap to the
            //    grid: the sub-beat pick re-PHASES the rhythm, it doesn't
            //    de-quantize it.
            // Melody range: the guitar bound (MIDI 40–88) is a hard floor/ceiling
            // for the tab; a T9 detection filter can only *narrow* it further, so
            // removing sub-bass / high noise affects the melody as well as chords.
            $melMin = max(40, (int)($detectionFilter['midi_min'] ?? 40));
            $melMax = min(88, (int)($detectionFilter['midi_max'] ?? 88));

            $tickGroups = [];
            foreach ($rawResult['notes'] as $note) {
                if ($note['pitch'] < $melMin || $note['pitch'] > $melMax) continue;

                $rawStart  = $this->timeToTicks($note['start'], $rawResult['beat_times']);
                $startTick = (int)round($rawStart / 240) * 240;
                if (abs($rawStart - $startTick) > 60) {
                    $startTick = (int)round($rawStart / 120) * 120;
                }

                $startTick = $startTick - $tickOffset + $padTicks;
                // Re-snap onto the 120-tick lattice after the (possibly
                // fractional) downbeat shift.
                $startTick = (int)round($startTick / 120) * 120;
                if ($startTick >= 0) {
                    $tickGroups[$startTick][] = $note;
                }
            }

            // 2. Process all notes per tick (Restoring polyphony) and build events
            $sortedTicks = array_keys($tickGroups);
            sort($sortedTicks);

            $tempMelody = [];
            for ($i = 0; $i < count($sortedTicks); $i++) {
                $tick     = $sortedTicks[$i];
                $notes    = $tickGroups[$tick];
                $nextTick = ($i + 1 < count($sortedTicks)) ? $sortedTicks[$i + 1] : null;

                $barIdx  = (int)floor($tick / $ticksPerBar);
                $barEnd  = ($barIdx + 1) * $ticksPerBar;
                $beatEnd = ((int)floor($tick / 480) + 1) * 480;

                $limit = min($barEnd, $beatEnd);

                $availableSpace = $limit - $tick;
                if ($nextTick !== null && $nextTick < $limit) {
                    $availableSpace = $nextTick - $tick;
                }

                foreach ($notes as $note) {
                    $endTick = $this->timeToTicks($note['end'], $rawResult['beat_times']);
                    $endTick = (int)round($endTick / 120) * 120 - $tickOffset + $padTicks;
                    // Re-snap to the 120-tick lattice after the downbeat shift
                    // (matches the start-tick re-snap above).
                    $endTick = (int)round($endTick / 120) * 120;

                    $rawDuration = max(120, $endTick - $tick);

                    $clampedDuration = min($availableSpace, $rawDuration);
                    $quantizedTicks  = $this->quantizeToStandard($clampedDuration);

                    if ($quantizedTicks > $availableSpace) $quantizedTicks = $availableSpace;
                    if ($quantizedTicks < 120 && $availableSpace >= 120) $quantizedTicks = 120;
                    if ($quantizedTicks < 120) continue;

                    $noteInfo = $this->midiToNote($note['pitch']);
                    $tabInfo  = $this->midiToTab($note['pitch']);

                    $tempMelody[] = [
                        'tick'         => $tick,
                        'pitch'        => $noteInfo['pitch'],
                        'octave'       => $noteInfo['octave'],
                        'duration'     => $this->ticksToDuration($quantizedTicks),
                        'ticks'        => $quantizedTicks,
                        'string'       => $tabInfo['string'],
                        'fret'         => $tabInfo['fret'],
                        'isRest'       => false,
                        'voice'        => 1,
                        'tieStart'     => false,
                        'tieStop'      => false,
                        'isChordNote'  => false,
                        // Absolute MIDI pitch — consumed (and removed) by the
                        // T1 fretboard-optimisation pass below.
                        '_midi'        => (int)$note['pitch'],
                    ];
                }
            }

            // 3. Rest insertion pass (P1)
            $melodyWithRests = [];
            $lastEnd = 0;
            usort($tempMelody, fn($a, $b) => $a['tick'] <=> $b['tick']);

            foreach ($tempMelody as $m) {
                if ($m['tick'] > $lastEnd) {
                    $gap = $m['tick'] - $lastEnd;

                    if ($gap <= 120 && !empty($melodyWithRests)) {
                        $extended = false;
                        foreach ($melodyWithRests as &$prev) {
                            if (!($prev['isRest'] ?? false) && ($prev['tick'] + $prev['ticks'] == $lastEnd)) {
                                $newTicks  = $prev['ticks'] + $gap;
                                $quantized = $this->quantizeToStandard($newTicks);

                                $pBeatEnd = ((int)floor($prev['tick'] / 480) + 1) * 480;
                                if ($quantized > $prev['ticks'] && ($prev['tick'] + $quantized <= $pBeatEnd)) {
                                    $prev['ticks']    = $quantized;
                                    $prev['duration'] = $this->ticksToDuration($prev['ticks']);
                                    $extended = true;
                                }
                            }
                        }
                        unset($prev);
                        if ($extended) {
                            $lastEnd = $m['tick'];
                            $gap = 0;
                        }
                    }

                    while ($gap >= 120) {
                        $barIdx = (int)floor($lastEnd / $ticksPerBar);
                        $barEnd = ($barIdx + 1) * $ticksPerBar;

                        $restTicks = 120;
                        foreach ([1920, 960, 480, 240, 120] as $s) {
                            if ($s <= $gap && ($lastEnd + $s <= $barEnd)) {
                                $restTicks = $s;
                                break;
                            }
                        }

                        if ($lastEnd + $restTicks > $barEnd) $restTicks = $barEnd - $lastEnd;
                        if ($restTicks < 120) {
                            $lastEnd = $barEnd;
                            $gap = $m['tick'] - $lastEnd;
                            continue;
                        }

                        $melodyWithRests[] = [
                            'tick'     => $lastEnd,
                            'ticks'    => $restTicks,
                            'duration' => $this->ticksToDuration($restTicks),
                            'isRest'   => true,
                            'voice'    => 1,
                            'pitch'    => 'R',
                            'octave'   => 0,
                        ];
                        $lastEnd += $restTicks;
                        $gap -= $restTicks;
                    }
                }
                $melodyWithRests[] = $m;
                $lastEnd = max($lastEnd, $m['tick'] + $m['ticks']);
            }

            // Fill up to the end of the last measure (account for pickup padding)
            $totalBeats = (count($rawResult['beats']) - $startBeatIdx) + $padBeats;
            $totalTicks = $totalBeats * 480;
            $gap = $totalTicks - $lastEnd;

            if ($gap > 0 && $gap <= 120 && !empty($melodyWithRests)) {
                foreach ($melodyWithRests as &$prev) {
                    if (!($prev['isRest'] ?? false) && ($prev['tick'] + $prev['ticks'] == $lastEnd)) {
                        $prev['ticks'] += $gap;
                        $prev['duration'] = $this->ticksToDuration($prev['ticks']);
                    }
                }
                unset($prev);
                $lastEnd = $totalTicks;
                $gap = 0;
            }

            while ($gap >= 120) {
                $barIdx = (int)floor($lastEnd / $ticksPerBar);
                $barEnd = ($barIdx + 1) * $ticksPerBar;
                $restTicks = 120;
                foreach ([1920, 960, 480, 240, 120] as $s) {
                    if ($s <= $gap && ($lastEnd + $s <= $barEnd)) {
                        $restTicks = $s;
                        break;
                    }
                }
                if ($lastEnd + $restTicks > $barEnd) $restTicks = $barEnd - $lastEnd;
                if ($restTicks >= 120) {
                    $melodyWithRests[] = [
                        'tick'     => $lastEnd,
                        'ticks'    => $restTicks,
                        'duration' => $this->ticksToDuration($restTicks),
                        'isRest'   => true,
                        'voice'    => 1,
                        'pitch'    => 'R',
                        'octave'   => 0,
                    ];
                }
                $lastEnd += $restTicks;
                $gap = $totalTicks - $lastEnd;
            }

            $melody = $melodyWithRests;
        }

        // ── T1: fretboard position optimisation ─────────────────────────────
        // midiToTab() picked each note's string/fret in isolation, which yields
        // physically absurd tab (fret 2 → 14 → 5). Re-assign positions with a
        // Viterbi pass that locks one hand position per bar. The style bias
        // ('fretted' vs 'open') is the user's per-piece choice from the import
        // modal — jazz chord-melody wants fretted positions, classical /
        // fingerstyle wants open strings.
        //
        // Chord-position hints: each bar's identified chord suggests a neck
        // position (its root's low fret). T1 uses this as a soft tiebreak so
        // a G7 bar leans to 3rd position even when an open-position layout is
        // marginally cheaper note-for-note.
        $chordPosHints = $this->chordPositionHints($currentSection['bars']);

        $melody = $this->optimizeTabPositions(
            $melody,
            $opts['tab_position_style'] ?? 'fretted',
            $chordPosHints
        );

        $analysis['melody_data'] = $melody;

        return $analysis;
    }

    /**
     * Per-measure melody position hints for chord-voicing selection.
     *
     * Returns a `measureIndex => meanFret` map describing where the melody's
     * fretting hand sits in each bar. ProgressionBuilder uses it to choose
     * chord voicings in the same neck region as the melody — on a chord-melody
     * piece the chords and melody are one fretting hand, so an open Am next to
     * a melody at the 5th–8th fret is unplayable as written.
     *
     * Only fretted notes count (open strings and rests don't place the hand).
     * A measure with no fretted melody note simply gets no hint; the builder's
     * position-hint term is inert there. Expects post-T1 melody (440-tick grid,
     * 1920 ticks/bar).
     */
    public function melodyPositionHints(array $melody): array
    {
        $ticksPerBar = 1920;
        $byMeasure   = []; // measureIndex => [frets…]

        foreach ($melody as $note) {
            if (!empty($note['isRest'])) continue;
            $fret = $note['fret'] ?? null;
            if ($fret === null || $fret <= 0) continue; // open string places no hand

            $measure = (int) floor(($note['tick'] ?? 0) / $ticksPerBar);
            $byMeasure[$measure][] = (int) $fret;
        }

        $hints = [];
        foreach ($byMeasure as $measure => $frets) {
            $hints[$measure] = array_sum($frets) / count($frets);
        }
        return $hints;
    }

    /**
     * Per-bar neck-position hint from each bar's identified chord.
     *
     * A chord has a "home position" on the neck — the low fret where its root
     * sits on a bass string. T1 uses this as a *soft* bias so a bar's melody
     * leans toward where the chord is played: a G7 bar nudges toward the 3rd
     * position even when an open-position note layout is marginally cheaper.
     *
     * The position is the root pitch-class's fret on the low-E or A string,
     * whichever lands in a sensible 1–7 window (preferring the lower). For a
     * bar with multiple chords the first is used; a bar with no real chord
     * (`/`, unparseable) gets no hint and is left unbiased.
     *
     * @param array $bars  assembleTranscription()'s per-bar chord list
     *                     ([ ['chords'=>[ ['label'=>…], … ]], … ])
     * @return array  measureIndex => suggested index-finger fret
     */
    private function chordPositionHints(array $bars): array
    {
        // Root letter (+ accidental) → pitch class.
        $pcOf = [
            'C'=>0,'C#'=>1,'Db'=>1,'D'=>2,'D#'=>3,'Eb'=>3,'E'=>4,'F'=>5,
            'F#'=>6,'Gb'=>6,'G'=>7,'G#'=>8,'Ab'=>8,'A'=>9,'A#'=>10,'Bb'=>10,'B'=>11,
        ];
        $eOpenPc = 4;   // low-E string open pitch class
        $aOpenPc = 9;   // A string open pitch class

        $hints = [];
        foreach ($bars as $idx => $bar) {
            $label = $bar['chords'][0]['label'] ?? '';
            if ($label === '' || $label === '/') continue;

            // Root = first 2 chars if they name a sharp/flat root, else 1 char.
            $root = null;
            if (strlen($label) >= 2 && isset($pcOf[substr($label, 0, 2)])) {
                $root = substr($label, 0, 2);
            } elseif (strlen($label) >= 1 && isset($pcOf[strtoupper(substr($label, 0, 1))])) {
                $root = strtoupper(substr($label, 0, 1));
            }
            if ($root === null) continue;

            $pc      = $pcOf[$root];
            $eFret   = ($pc - $eOpenPc + 12) % 12;   // root fret on low-E
            $aFret   = ($pc - $aOpenPc + 12) % 12;   // root fret on A string
            // Prefer whichever sits low on the neck (1–7); ties → the lower.
            $cands = array_filter([$eFret, $aFret], fn($f) => $f >= 1 && $f <= 7);
            if (empty($cands)) continue;             // root only at open/high — no bias
            $hints[$idx] = min($cands);
        }
        return $hints;
    }

    /**
     * T1 — Fretboard position optimisation (bar-locked).
     *
     * midiToTab() maps each MIDI pitch to a string/fret greedily and in
     * isolation, so a line can leap across the neck for notes a guitarist
     * would play in one hand position. This pass re-derives string/fret so
     * each bar commits to a single hand position.
     *
     * The Viterbi runs over *bars*. Each bar's state is a candidate hand
     * position — the index-finger fret, 0–17, anchoring a 4-fret window
     * `pos..pos+3` (a real fretting hand covers ~4 frets, not the whole neck):
     *  - Node cost : sum over the bar's notes of the cost to play each from
     *                that window. A note picks the (string,fret) that keeps it
     *                *inside* the window; a note that cannot is pulled to its
     *                nearest fret and pays a stiff out-of-window penalty. So
     *                the chosen position is the one that compactly covers the
     *                whole bar — a 1st-position note and a 5th-position note
     *                can no longer both look "free" in one bar.
     *  - Transition: a flat shift cost + fret travel between consecutive bars,
     *                so the hand only relocates at a bar line when needed.
     *
     * `$style` is the user's per-piece bias from the import modal:
     *  - 'fretted' (default) — jazz chord-melody. An open string costs more the
     *    higher the hand sits, so a hand at the 5th position frets E4 at
     *    B-string 5 rather than leaving its grip for the open e.
     *  - 'open' — classical / fingerstyle. Open strings stay nearly free
     *    regardless of hand position, so the optimiser uses them freely.
     *
     * `$chordPosHints` (measureIndex => suggested fret) softly biases each
     * bar's position toward where its chord is played — see chordPositionHints().
     *
     * Rests pass through. The temporary '_midi' key is stripped before return.
     */
    private function optimizeTabPositions(
        array $melody,
        string $style = 'fretted',
        array $chordPosHints = []
    ): array {
        $stringPitches = [64, 59, 55, 50, 45, 40]; // string 1(e) … 6(E)
        $maxFret       = 17;
        $ticksPerBar   = 1920;
        // A hand position spans 4 frets: index at `pos`, pinky at `pos+3`.
        $handSpan      = 3;
        $preferOpen    = $style === 'open';
        // Per-fret cost of a bar's position differing from its chord hint.
        // Soft tiebreak — tune after eyeballing real imports.
        $chordBiasWeight = 0.18;

        // Candidate (string,fret) positions for a given absolute MIDI pitch.
        $candidates = function (int $midi) use ($stringPitches, $maxFret): array {
            $out = [];
            foreach ($stringPitches as $i => $base) {
                $fret = $midi - $base;
                if ($fret >= 0 && $fret <= $maxFret) {
                    $out[] = ['string' => $i + 1, 'fret' => $fret];
                }
            }
            return $out;
        };

        // Cost (and chosen string/fret) of playing one pitch from a hand
        // position anchored at `pos` (window pos..pos+handSpan). The candidate
        // is chosen to keep the note inside the window; one outside pays a
        // stiff per-fret penalty for how far the hand must stretch/shift.
        //
        // An open string's cost depends on $style. In 'fretted' mode it costs
        // `pos * 0.12` — free at the nut, but more awkward the higher the hand
        // sits, so it stays *cheaper than an out-of-window stretch* yet
        // *dearer than an in-window fretted alternative* (a hand at the 5th
        // position frets E4 at B-string 5 rather than reaching for the open
        // e). In 'open' mode it is a flat near-zero — classical / fingerstyle
        // uses open strings freely regardless of hand position.
        $playFromPos = function (int $midi, int $pos) use ($candidates, $handSpan, $preferOpen): ?array {
            $cands = $candidates($midi);
            if (empty($cands)) return null;
            $best = null;
            foreach ($cands as $c) {
                $fret = $c['fret'];
                if ($fret === 0) {
                    $cost = $preferOpen ? 0.05 : $pos * 0.12;
                } else {
                    // Distance outside the [pos, pos+handSpan] window.
                    $out  = $fret < $pos ? ($pos - $fret)
                          : ($fret > $pos + $handSpan ? $fret - ($pos + $handSpan) : 0);
                    $cost = $fret * 0.05 + $out * 1.5;  // height tiebreak + reach
                }
                if ($best === null || $cost < $best['cost']) {
                    $best = ['string' => $c['string'], 'fret' => $fret, 'cost' => $cost];
                }
            }
            return $best;
        };

        // Group pitched-note indices by bar.
        $bars    = [];   // barIdx => [melody indices]
        $anyNote = false;
        foreach ($melody as $i => $m) {
            if (empty($m['isRest']) && isset($m['_midi'])) {
                $bars[(int)floor(($m['tick'] ?? 0) / $ticksPerBar)][] = $i;
                $anyNote = true;
            }
        }
        if (!$anyNote) {
            foreach ($melody as &$m) { unset($m['_midi']); }
            unset($m);
            return $melody;
        }
        ksort($bars);

        $barKeys   = array_keys($bars);
        $positions = range(0, $maxFret);
        $shiftCost = 0.6;   // flat penalty for relocating between bars

        // Viterbi forward pass over bars. State = hand position (fret 0–maxFret).
        $prevCol = null;    // [ pos => ['cost','back'] ]
        $cols    = [];      // one column per bar
        foreach ($barKeys as $col => $barIdx) {
            $indices = $bars[$barIdx];
            // Suggested neck position for this bar from its chord, if any.
            $hintPos = $chordPosHints[$barIdx] ?? null;

            // Node cost of each candidate position = sum of per-note play costs
            // plus a soft pull toward the bar's chord position (if hinted).
            $nodeCost = [];
            foreach ($positions as $pos) {
                $sum   = 0.0;
                $valid = false;
                foreach ($indices as $mi) {
                    $play = $playFromPos($melody[$mi]['_midi'], $pos);
                    if ($play === null) continue;     // out-of-range note
                    $sum  += $play['cost'];
                    $valid = true;
                }
                if ($valid) {
                    if ($hintPos !== null) {
                        $sum += abs($pos - $hintPos) * $chordBiasWeight;
                    }
                    $nodeCost[$pos] = $sum;
                }
            }
            if (empty($nodeCost)) {                   // whole bar out of range
                $cols[$col] = [];
                $prevCol    = null;
                continue;
            }

            $states = [];
            foreach ($nodeCost as $pos => $nc) {
                if ($prevCol === null) {
                    $states[$pos] = ['cost' => $nc, 'back' => -1];
                    continue;
                }
                $bestCost = PHP_INT_MAX;
                $bestBack = -1;
                foreach ($prevCol as $pPos => $p) {
                    $move = $pPos === $pos ? 0.0 : ($shiftCost + abs($pos - $pPos));
                    $t    = $p['cost'] + $nc + $move;
                    if ($t < $bestCost) {
                        $bestCost = $t;
                        $bestBack = $pPos;
                    }
                }
                $states[$pos] = ['cost' => $bestCost, 'back' => $bestBack];
            }
            $cols[$col] = $states;
            $prevCol    = $states;
        }

        // Backtrace each contiguous chain of decided bar-columns.
        $col = count($barKeys) - 1;
        while ($col >= 0) {
            if (empty($cols[$col])) { $col--; continue; }

            // Cheapest final position in this chain.
            $bestPos  = null;
            $bestCost = PHP_INT_MAX;
            foreach ($cols[$col] as $pos => $s) {
                if ($s['cost'] < $bestCost) { $bestCost = $s['cost']; $bestPos = $pos; }
            }
            while ($col >= 0 && !empty($cols[$col])) {
                $state = $cols[$col][$bestPos];
                foreach ($bars[$barKeys[$col]] as $mi) {
                    $play = $playFromPos($melody[$mi]['_midi'], $bestPos);
                    if ($play !== null) {
                        $melody[$mi]['string'] = $play['string'];
                        $melody[$mi]['fret']   = $play['fret'];
                    }
                    // out-of-range note keeps midiToTab's fallback
                }
                $bestPos = $state['back'];
                $col--;
                if ($bestPos < 0) break;
            }
        }

        foreach ($melody as &$m) { unset($m['_midi']); }
        unset($m);

        return $melody;
    }

    /**
     * T3 — context-aware chord re-identification for the audio path.
     *
     * `assembleTranscription()` identifies each harmonic region *in isolation*
     * via `identifyFromMidi()`. That is a strong first pass but has no notion of
     * key or neighbour chords, so borderline regions (rootless voicings, tritone
     * ambiguity, enharmonic spelling) can read wrong. This runs the whole song's
     * regions through the Phase 3 `ContextualReranker` — the same engine already
     * wired into the fret path (`identifyVoicingsBatch`) — which applies key-fit,
     * bigram transition and Viterbi sequence scoring over the ordered slots and
     * only shifts a reading when sequence evidence outweighs a *near-tied* local
     * winner (its own minScoreRatio guards protect a dominant local reading).
     *
     * `$chordSeq` is the ordered list of the full `identifyFromMidi()` results
     * (index = the `_seq` id stamped onto each emitted chord entry). After
     * reranking, reinterpreted names are written back into the matching chord
     * entries in `$analysis['sections'][*]['bars'][*]['chords'][*]`, and the
     * `_seq` scratch key is stripped from every entry.
     *
     * Non-fatal: any exception leaves the deterministic per-region labels intact.
     *
     * @param array &$analysis   Assembled analysis (mutated in place)
     * @param array  $chordSeq   Ordered identifyFromMidi() results, keyed by _seq
     */
    private function applyContextualChordReranking(array &$analysis, array $chordSeq, VoicingCrossref $crossref): void
    {
        // Fewer than two chords → no sequence context to exploit. Still strip
        // the scratch key so `_seq` never leaks into persisted json_data.
        if (count($chordSeq) < 2) {
            $this->stripChordSeqKeys($analysis);
            return;
        }

        try {
            $reranker = app(\App\Services\HarmonicContext\ContextualReranker::class);

            // Reindex 0..n-1 (the reranker requires sequential slots) — $chordSeq
            // keys are already dense 0-based, but be defensive.
            $slots = array_values($chordSeq);

            // Audio imports default key to 'C' when none was inferred; pass it
            // through anyway. The key-dependent sub-passes are internally inert
            // on a neutral/absent key, and bigram + Viterbi don't use the key.
            $songKey = $analysis['key'] ?? null;

            $reranked = $reranker->rerank($slots, $songKey);

            // Map _seq → reinterpreted name (only where it actually changed).
            $relabel = [];
            foreach ($reranked as $seq => $slot) {
                $newName = $slot['name'] ?? null;
                $oldName = $chordSeq[$seq]['name'] ?? null;
                if ($newName && $newName !== $oldName) {
                    $relabel[$seq] = $newName;
                }
            }

            if (!empty($relabel)) {
                foreach ($analysis['sections'] as &$section) {
                    foreach ($section['bars'] as &$bar) {
                        foreach ($bar['chords'] as &$chord) {
                            $seq = $chord['_seq'] ?? null;
                            if ($seq !== null && isset($relabel[$seq])) {
                                $chord['label'] = $relabel[$seq];
                            }
                        }
                        unset($chord);
                    }
                    unset($bar);
                }
                unset($section);
            }
        } catch (\Throwable $e) {
            \Log::warning('T3 contextual chord reranking failed; keeping deterministic labels', [
                'error' => $e->getMessage(),
            ]);
        }

        // Always strip the scratch key, whether or not reranking ran/changed anything.
        $this->stripChordSeqKeys($analysis);
    }

    /** Remove the transient `_seq` scratch key from every chord entry. */
    private function stripChordSeqKeys(array &$analysis): void
    {
        if (empty($analysis['sections'])) return;
        foreach ($analysis['sections'] as &$section) {
            if (empty($section['bars'])) continue;
            foreach ($section['bars'] as &$bar) {
                if (empty($bar['chords'])) continue;
                foreach ($bar['chords'] as &$chord) {
                    unset($chord['_seq']);
                }
                unset($chord);
            }
            unset($bar);
        }
        unset($section);
    }

    /**
     * Jaccard similarity between two sets of MIDI pitches.
     * Used by the non-AI harmonic region grouping pass (Phase 2c).
     *
     * Returns 1.0 for identical sets, 0.0 for completely disjoint sets.
     * Threshold >= 0.5 means at least half the combined notes are shared.
     */
    private function jaccardSimilarity(array $setA, array $setB): float
    {
        $a = array_unique($setA);
        $b = array_unique($setB);

        if (empty($a) && empty($b)) return 1.0;
        if (empty($a) || empty($b)) return 0.0;

        $intersection = count(array_intersect($a, $b));
        $union        = count(array_unique(array_merge($a, $b)));

        return $union > 0 ? $intersection / $union : 0.0;
    }
}
