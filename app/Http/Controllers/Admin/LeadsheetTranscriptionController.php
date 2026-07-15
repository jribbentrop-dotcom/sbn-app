<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AssemblesTranscriptions;
use App\Http\Controllers\Admin\Concerns\SerializesLeadsheets;
use App\Http\Controllers\Controller;
use App\Models\Leadsheet;
use App\Services\AnalysisToLeadsheet;
use App\Services\ChordVoicingSearch;
use App\Services\VoicingCrossref;
use Illuminate\Http\Request;

/**
 * Audio-transcription pipeline for leadsheets: stem separation/audition,
 * downbeat/detection re-tuning, and re-inference (redetect / transcribe-stem).
 * The shared basic-pitch → Analysis assembly they all call lives in the
 * AssemblesTranscriptions trait (also used by LeadsheetController::createFromLookup).
 *
 * Split out of LeadsheetController (2026-07 audit #5) — see
 * docs/SBN-Security-Audit-2026-07-09.md.
 */
class LeadsheetTranscriptionController extends Controller
{
    use SerializesLeadsheets;
    use AssemblesTranscriptions;

    protected ChordVoicingSearch $voicingSearch;

    public function __construct(ChordVoicingSearch $voicingSearch)
    {
        $this->voicingSearch = $voicingSearch;
    }

    /**
     * PHASE 1 of the audio import: run Demucs and persist all six stems so the
     * admin can audition each and pick which to transcribe. Returns a session
     * token + the list of available stems. No leadsheet is created yet.
     */
    public function separateStems(Request $request, \App\Services\MidiTranscriptionService $transcriber)
    {
        set_time_limit(600);

        $validated = $request->validate([
            'youtube_id'  => 'nullable|string',
            'local_audio' => 'nullable|file|mimes:mp3,wav,m4a,ogg,flac|max:102400',
            // Separate an existing leadsheet's persisted original recording
            // (from the editor's video-sync sidebar) — no re-upload.
            'leadsheet_id' => 'nullable|integer|exists:sbn_leadsheets,id',
        ]);

        $hasYoutube   = !empty($validated['youtube_id']);
        $hasLocal     = $request->hasFile('local_audio');
        $hasLeadsheet = !empty($validated['leadsheet_id']);
        if (!$hasYoutube && !$hasLocal && !$hasLeadsheet) {
            return response()->json(['success' => false, 'error' => 'Provide a YouTube id, upload an audio file, or a leadsheet with persisted source audio.'], 422);
        }

        if ($hasLeadsheet) {
            $ls = Leadsheet::find($validated['leadsheet_id']);
            $url = $ls?->parsed_data['sourceAudio']['url'] ?? null;
            $path = $url ? public_path(parse_url($url, PHP_URL_PATH)) : null;
            if (!$path || !is_file($path)) {
                return response()->json(['success' => false, 'error' => 'This leadsheet has no persisted source audio to separate.'], 422);
            }
            // Don't move/delete the persisted original — separateStemsToSession
            // treats an 'upload' source as caller-owned and won't unlink it.
            $result = $transcriber->separateStemsToSession($path, 'upload');
        } elseif ($hasLocal) {
            $uploadedFile = $request->file('local_audio');
            $tempDir = storage_path('app/temp_audio');
            if (!is_dir($tempDir)) mkdir($tempDir, 0777, true);
            $tempPath = $tempDir . '/' . uniqid('sep_', true) . '.' . $uploadedFile->getClientOriginalExtension();
            $uploadedFile->move($tempDir, basename($tempPath));
            try {
                $result = $transcriber->separateStemsToSession($tempPath, 'upload');
            } finally {
                if (file_exists($tempPath)) unlink($tempPath);
            }
        } else {
            $result = $transcriber->separateStemsToSession($validated['youtube_id'], 'youtube');
        }

        if (!($result['success'] ?? false)) {
            return response()->json(['success' => false, 'error' => $result['error'] ?? 'Stem separation failed.'], 422);
        }

        return response()->json([
            'success' => true,
            'session' => $result['session'],
            'stems'   => $result['stems'], // ordered subset of STEM_NAMES
        ]);
    }

    /**
     * Stream one separated stem for in-browser audition. Both the session token
     * and the stem name are whitelisted so neither can escape storage/app/stems.
     */
    public function streamStem(string $session, string $stem, \App\Services\MidiTranscriptionService $transcriber)
    {
        if (!in_array($stem, \App\Services\MidiTranscriptionService::STEM_NAMES, true)) {
            abort(404);
        }
        $path = $transcriber->stemSessionDir($session) . '/' . $stem . '.wav';
        if (!is_file($path)) {
            abort(404);
        }
        return response()->file($path, [
            'Content-Type'  => 'audio/wav',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Persist a chosen audition stem as a leadsheet's hosted sync audio, so the
     * video-sync editor can follow (e.g.) the isolated guitar instead of the
     * full mix. Copies the session stem into public storage and returns its URL;
     * the frontend points videoSync at it. The ephemeral session may be swept
     * afterwards without affecting this persisted copy.
     */
    public function persistStemAsSync(Request $request, Leadsheet $leadsheet, \App\Services\MidiTranscriptionService $transcriber)
    {
        $validated = $request->validate([
            'session' => 'required|string|max:64',
            'stem'    => 'required|string|in:guitar,bass,vocals,drums,piano,other',
        ]);

        $src = $transcriber->stemSessionDir($validated['session']) . '/' . $validated['stem'] . '.wav';
        if (!is_file($src)) {
            return response()->json(['success' => false, 'error' => 'Stem not found (session may have expired).'], 422);
        }

        $dir = "audio/source/{$leadsheet->id}";
        $name = "stem-{$validated['stem']}.wav";
        $destDir = public_path($dir);
        if (!is_dir($destDir)) mkdir($destDir, 0777, true);
        if (!@copy($src, "{$destDir}/{$name}")) {
            return response()->json(['success' => false, 'error' => 'Could not persist the stem.'], 500);
        }

        return response()->json(['success' => true, 'url' => asset("{$dir}/{$name}")]);
    }

    /**
     * Re-assemble an audio-transcribed leadsheet with a user-chosen downbeat.
     *
     * The original raw Python transcription is cached in json_data.transcriptionRaw
     * on import. This endpoint re-runs assembleTranscription() with the chosen
     * offset — a *tick* shift (0..1919, 480 = 1 quarter) locating the true "1",
     * so an off-beat note can be the downbeat — rebuilds sections / melody /
     * videoSync, and persists. Content before the chosen "1" survives as a
     * leading pickup bar — nothing is lost.
     *
     * Idempotent: always re-shifts from the original cached beats, so the user
     * can re-pick freely. WARNING: full re-assembly discards any manual chord /
     * voicing edits made after import — this is meant as a "do this first" step.
     */
    public function reshiftDownbeat(
        Request $request,
        Leadsheet $leadsheet,
        AnalysisToLeadsheet $converter,
        VoicingCrossref $crossref
    ) {
        // `offset` is the downbeat shift in ticks (480 ticks = 1 quarter, one
        // 4/4 bar = 1920). Sub-beat values let the chosen "1" land exactly on
        // a note quantized to an 8th/16th, not just on a whole beat.
        // Note: reshiftDownbeat does NOT re-run Python, so it never re-runs
        // separation — separate_stem isn't accepted here; transcriptionRaw's
        // cached 'separateStem' flag is simply carried through untouched.
        $validated = $request->validate([
            'offset'    => 'required|integer|min:0|max:1919',
            'bass_snap' => 'nullable|boolean',
            'tab_position_style' => 'nullable|string|in:fretted,open',
            // Set true (by the client, right after reopen-tuning) to re-derive a
            // transcription the user had latched as "fixed". See §13.
            'force'     => 'nullable|boolean',
        ]);

        $parsed = $leadsheet->parsed_data;
        $raw    = $parsed['transcriptionRaw'] ?? null;

        if (empty($raw) || empty($raw['beats'])) {
            return response()->json([
                'success' => false,
                'error'   => 'This leadsheet has no cached audio transcription. The downbeat can only be re-shifted on sheets created via audio transcription after this feature was added.',
            ], 422);
        }

        // Fixed-transcription latch (§13): once the user commits the sheet as the
        // source of truth, re-deriving would silently clobber their edits. Refuse
        // unless the client explicitly forces it (which it does only after the
        // "this discards your edits" confirm has flipped the flag via reopen-tuning).
        if (($parsed['transcriptionFixed'] ?? false) && empty($validated['force'])) {
            return response()->json([
                'success' => false,
                'fixed'   => true,
                'error'   => 'This transcription is fixed. Re-open tuning first — re-deriving will discard edits made since.',
            ], 409);
        }

        // Bass-snap defaults to whatever produced the current assembly so a
        // plain downbeat re-shift doesn't silently turn correction off.
        $bassSnap = array_key_exists('bass_snap', $validated)
            ? !empty($validated['bass_snap'])
            : !empty($raw['bassSnap']);

        // Tab position style: explicit override, else whatever produced the
        // current assembly (cached in transcriptionRaw), else the default.
        $tabStyle = $validated['tab_position_style']
            ?? ($raw['tabPositionStyle'] ?? 'fretted');

        $analysis = $this->assembleTranscription($raw, [
            'title'      => $leadsheet->title,
            'key'        => $leadsheet->song_key ?: 'C',
            'youtube_id' => $raw['videoId'] ?? ($parsed['videoSync']['videoId'] ?? ''),
            'bass_snap'  => $bassSnap,
            'tab_position_style' => $tabStyle,
            // reshiftDownbeat never re-runs Python (no re-separation) — just
            // preserve whatever produced the current assembly for the record.
            'separate_stem' => $raw['separateStem'] ?? true,
            // Carry the stem source through untouched: a reshift re-buckets the
            // cached (stem) notes, so a later Re-run detection must still target
            // the same stem rather than reverting to the full mix.
            'stem_source'   => $raw['stemSource'] ?? null,
        ], (int)$validated['offset'], $crossref);

        $scaffold      = $converter->convert($analysis);
        $jsonDataArray = json_decode($scaffold['json_data'], true);
        $jsonDataArray = $this->carryForwardImportKeys($jsonDataArray, $parsed);

        $leadsheet->update([
            'measure_count'     => $scaffold['measure_count'],
            'shortcode_content' => $scaffold['shortcode_content'],
            'json_data'         => $this->normalizeChordNamesInJson(
                json_encode($jsonDataArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ),
            // Audio transcription melody lives in json_data.melody; the tab_xml
            // skeleton is regenerated from scratch on next save.
            'tab_xml'           => null,
        ]);

        return response()->json([
            'success'   => true,
            'offset'    => (int)$validated['offset'],
            'leadsheet' => $this->serializeLeadsheet(
                $leadsheet->fresh(),
                $this->backfillFingersFromCrossref($leadsheet->fresh()->parsed_data)
            ),
        ]);
    }

    /**
     * "Fix transcription" latch (§13). Commits an audio-transcribed leadsheet as
     * the source of truth: sets json_data.transcriptionFixed = true. From then on
     * the re-derive tools (downbeat re-shift, detection tuning) refuse to run
     * without an explicit force, so manual edits can't be silently clobbered.
     *
     * Merges the flag into parsed_data and re-persists json_data verbatim — every
     * other key (transcriptionRaw, sourceAudio, sections, melody, videoSync) is
     * preserved. transcriptionRaw is deliberately KEPT so re-tuning stays possible
     * (via reopenTuning + force), just gated. Idempotent.
     */
    public function fixTranscription(Request $request, Leadsheet $leadsheet)
    {
        return $this->setTranscriptionFixed($leadsheet, true);
    }

    /**
     * Unlatch (§13): set transcriptionFixed = false so the re-derive tools work
     * again. The editor calls this only after the user confirms "this discards my
     * edits", then immediately re-runs the re-derive with force=true.
     */
    public function reopenTuning(Request $request, Leadsheet $leadsheet)
    {
        return $this->setTranscriptionFixed($leadsheet, false);
    }

    /**
     * Shared writer for the transcriptionFixed latch. Uses the same
     * read-parsed_data → set key → json_encode → update idiom as sourceAudio
     * persistence, so all other json_data keys survive untouched.
     */
    private function setTranscriptionFixed(Leadsheet $leadsheet, bool $fixed): \Illuminate\Http\JsonResponse
    {
        $parsed = $leadsheet->parsed_data;

        if (empty($parsed['transcriptionRaw'])) {
            return response()->json([
                'success' => false,
                'error'   => 'Only audio-transcribed leadsheets can be fixed / re-opened.',
            ], 422);
        }

        $parsed['transcriptionFixed'] = $fixed;
        $leadsheet->update([
            'json_data' => json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return response()->json([
            'success' => true,
            'transcriptionFixed' => $fixed,
        ]);
    }

    /**
     * T9 Tier-1 — live detection re-tune (post-filter only, no re-inference).
     *
     * Re-derives the chord-region buckets + melody from the cached full note set
     * with a new min-note-length / MIDI-range filter, WITHOUT re-running Python.
     * Structurally identical to reshiftDownbeat (cached raw → assembleTranscription
     * → convert → persist → return fresh leadsheet), reusing the cached downbeat
     * offset / bass-snap / tab-position style so only the filter changes.
     *
     * Onset/frame thresholds are NOT here — they require re-inference (redetect,
     * Tier 2). Inherits the §13 fixed-transcription guard.
     */
    public function retuneDetection(
        Request $request,
        Leadsheet $leadsheet,
        AnalysisToLeadsheet $converter,
        VoicingCrossref $crossref
    ) {
        $validated = $request->validate([
            'min_note_length_ms' => 'nullable|numeric|min:0|max:2000',
            'midi_min'           => 'nullable|integer|min:0|max:127',
            'midi_max'           => 'nullable|integer|min:0|max:127',
            'force'              => 'nullable|boolean',
        ]);

        $parsed = $leadsheet->parsed_data;
        $raw    = $parsed['transcriptionRaw'] ?? null;

        if (empty($raw) || empty($raw['notes']) || empty($raw['beats'])) {
            return response()->json([
                'success' => false,
                'error'   => 'This leadsheet has no cached audio transcription to re-tune.',
            ], 422);
        }

        // §13 fixed-transcription latch.
        if (($parsed['transcriptionFixed'] ?? false) && empty($validated['force'])) {
            return response()->json([
                'success' => false,
                'fixed'   => true,
                'error'   => 'This transcription is fixed. Re-open tuning first — re-tuning will discard edits made since.',
            ], 409);
        }

        // Assemble the filter from provided keys only (empty ⇒ null ⇒ no re-bucket,
        // i.e. back to the pristine Python buckets).
        $filter = array_filter([
            'min_note_length_ms' => $validated['min_note_length_ms'] ?? null,
            'midi_min'           => $validated['midi_min'] ?? null,
            'midi_max'           => $validated['midi_max'] ?? null,
        ], fn($v) => $v !== null);
        $filter = empty($filter) ? null : $filter;

        $analysis = $this->assembleTranscription($raw, [
            'title'      => $leadsheet->title,
            'key'        => $leadsheet->song_key ?: 'C',
            'youtube_id' => $raw['videoId'] ?? ($parsed['videoSync']['videoId'] ?? ''),
            // Preserve everything else that produced the current assembly.
            'bass_snap'          => !empty($raw['bassSnap']),
            'tab_position_style' => $raw['tabPositionStyle'] ?? 'fretted',
            'separate_stem'      => $raw['separateStem'] ?? true,
            'detection_filter'   => $filter,
            // Carry the stem source through untouched (see reshiftDownbeat) so a
            // later Re-run detection still targets the same isolated stem.
            'stem_source'        => $raw['stemSource'] ?? null,
        ], (int)($raw['downbeatOffset'] ?? 0), $crossref);

        $scaffold      = $converter->convert($analysis);
        $jsonDataArray = json_decode($scaffold['json_data'], true);
        $jsonDataArray = $this->carryForwardImportKeys($jsonDataArray, $parsed);

        $leadsheet->update([
            'measure_count'     => $scaffold['measure_count'],
            'shortcode_content' => $scaffold['shortcode_content'],
            'json_data'         => $this->normalizeChordNamesInJson(
                json_encode($jsonDataArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ),
            'tab_xml'           => null,
        ]);

        return response()->json([
            'success'   => true,
            'filter'    => $filter,
            'leadsheet' => $this->serializeLeadsheet(
                $leadsheet->fresh(),
                $this->backfillFingersFromCrossref($leadsheet->fresh()->parsed_data)
            ),
        ]);
    }

    /**
     * Shared re-assembly for the re-inference endpoints (redetect / transcribe-stem):
     * take a FRESH raw Python result, run it through assembleTranscription() reusing
     * the settings that produced the current assembly (downbeat offset / bass-snap /
     * tab-position style), persist, and return the serialized leadsheet. Structurally
     * the same tail as reshiftDownbeat / retuneDetection, but the raw is new (a
     * re-inference) rather than the cached grid.
     *
     * @param array $prevRaw  the leadsheet's existing transcriptionRaw (for settings)
     */
    /**
     * A re-derive scaffold (reshift / retune / re-inference) only carries the
     * assembled grid — it does NOT re-emit the import-only keys. Blindly replacing
     * json_data would drop sourceAudio (written once at import) and backingTrack,
     * which the re-derive toolbar gates on, plus the transcriptionFixed latch.
     * Carry them forward from the existing parsed_data so re-deriving doesn't wipe
     * the toolbar out from under the user.
     */
    private function carryForwardImportKeys(array $jsonDataArray, array $prevParsed): array
    {
        foreach (['sourceAudio', 'backingTrack', 'transcriptionFixed'] as $key) {
            if (array_key_exists($key, $prevParsed)) {
                $jsonDataArray[$key] = $prevParsed[$key];
            }
        }
        return $jsonDataArray;
    }

    private function reassembleFromRawResult(
        Leadsheet $leadsheet,
        array $rawResult,
        array $prevRaw,
        array $separateStem,
        AnalysisToLeadsheet $converter,
        VoicingCrossref $crossref,
        ?array $stemSource = null
    ): array {
        $analysis = $this->assembleTranscription($rawResult, [
            'title'      => $leadsheet->title,
            'key'        => $leadsheet->song_key ?: 'C',
            'youtube_id' => $prevRaw['videoId'] ?? ($leadsheet->parsed_data['videoSync']['videoId'] ?? ''),
            // Reuse the settings that produced the current assembly, so a re-detect
            // only changes what detection surfaced — not bass-snap / hand-position.
            'bass_snap'          => !empty($prevRaw['bassSnap']),
            'tab_position_style' => $prevRaw['tabPositionStyle'] ?? 'fretted',
            'separate_stem'      => $separateStem,
            // Record the isolated-stem source (if this re-derive ran on one) so a
            // later Re-run detection re-inferences on the same stem, not the mix.
            'stem_source'        => $stemSource,
        ], (int)($prevRaw['downbeatOffset'] ?? 0), $crossref);

        $scaffold      = $converter->convert($analysis);
        $jsonDataArray = json_decode($scaffold['json_data'], true);
        $jsonDataArray = $this->carryForwardImportKeys($jsonDataArray, $leadsheet->parsed_data ?? []);

        $leadsheet->update([
            'measure_count'     => $scaffold['measure_count'],
            'shortcode_content' => $scaffold['shortcode_content'],
            'json_data'         => $this->normalizeChordNamesInJson(
                json_encode($jsonDataArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ),
            'tab_xml'           => null,
        ]);

        return $this->serializeLeadsheet(
            $leadsheet->fresh(),
            $this->backfillFingersFromCrossref($leadsheet->fresh()->parsed_data)
        );
    }

    /**
     * T9 Tier-2 — re-detect (re-inference). Re-runs basic-pitch on the RESIDENT
     * source audio (the persisted blend original, §12b) with new onset/frame
     * thresholds — the knobs that live *inside* predict() and can't be re-tuned
     * post-hoc like Tier 1. No re-download / re-YouTube / re-separate; reuses the
     * cached bass-snap / tab-position style / downbeat offset. Inherits §13's guard.
     *
     * 422 if the sheet has no persisted sourceAudio (pre-§12b imports).
     */
    public function redetect(
        Request $request,
        Leadsheet $leadsheet,
        AnalysisToLeadsheet $converter,
        VoicingCrossref $crossref,
        \App\Services\MidiTranscriptionService $transcriber
    ) {
        $validated = $request->validate([
            'detection_preset'      => 'nullable|string|in:balanced,sensitive,strict,custom',
            'onset_threshold'       => 'nullable|numeric|min:0.05|max:0.95',
            'frame_threshold'       => 'nullable|numeric|min:0.05|max:0.95',
            'minimum_note_length'   => 'nullable|numeric|min:10|max:500',
            'restrict_guitar_range' => 'nullable|boolean',
            'force'                 => 'nullable|boolean',
        ]);

        $parsed = $leadsheet->parsed_data;
        $raw    = $parsed['transcriptionRaw'] ?? null;

        if (empty($raw)) {
            return response()->json([
                'success' => false,
                'error'   => 'This leadsheet has no cached audio transcription to re-detect.',
            ], 422);
        }

        // §13 fixed-transcription latch.
        if (($parsed['transcriptionFixed'] ?? false) && empty($validated['force'])) {
            return response()->json([
                'success' => false,
                'fixed'   => true,
                'error'   => 'This transcription is fixed. Re-open tuning first — re-detecting will discard edits made since.',
            ], 409);
        }

        // basic-pitch knobs from preset + overrides (same resolver as import).
        $detectionParams = $this->resolveDetectionParams($validated);
        // Re-inference never re-separates: either the persisted original is already
        // the (possibly stem-blended) source, or we re-mix an existing audition
        // session below. So drop any separation flag from the resolved params.
        unset($detectionParams['separate_stem']);

        // If the current transcription came from "Transcribe this stem", keep
        // re-detecting on THAT isolated stem — otherwise the user's stem choice is
        // silently thrown away and detection reverts to the muddy full mix. Falls
        // back to the original recording if the audition session has been swept.
        $stemSource = $raw['stemSource'] ?? null;
        $usedStem   = null;
        if ($stemSource && !empty($stemSource['session'])
            && is_dir($transcriber->stemSessionDir($stemSource['session']))) {
            $stems = $stemSource['stems'] ?? ['guitar'];
            $rawResult = $transcriber->transcribeStemFromSession(
                $stemSource['session'], $stems, $detectionParams
            );
            if ($rawResult['success'] ?? false) {
                $usedStem = $stemSource; // record it again for the next re-detect
            }
            // On failure fall through to the original recording below.
        }

        if (!isset($rawResult) || !($rawResult['success'] ?? false)) {
            // Resolve the persisted original recording (Tier 2 needs it — cached
            // notes can't surface below the original detection floor; only
            // re-inference can). Also the fallback when a stem session has expired.
            $srcUrl  = $parsed['sourceAudio']['url'] ?? null;
            $srcPath = $srcUrl ? public_path(parse_url($srcUrl, PHP_URL_PATH)) : null;
            if (!$srcPath || !is_file($srcPath)) {
                return response()->json([
                    'success' => false,
                    'error'   => 'Re-detection needs the original recording, which wasn\'t saved for this sheet (imported before that feature). Re-import to use it.',
                ], 422);
            }
            $rawResult = $transcriber->transcribeResidentAudio($srcPath, $detectionParams);
            $usedStem  = null; // reverted to full mix — clear any stale stem source
        }

        if (!($rawResult['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'error'   => 'Re-detection failed: ' . ($rawResult['error'] ?? 'unknown error'),
            ], 500);
        }

        $leadsheetData = $this->reassembleFromRawResult(
            $leadsheet, $rawResult, $raw,
            $usedStem ? ($usedStem['stems'] ?? true) : ($raw['separateStem'] ?? true),
            $converter, $crossref,
            $usedStem // persist/clear the stem source for the next re-detect
        );

        // Tell the editor which audio this ran on, so it can warn when a re-detect
        // fell back to the full mix because the stem audition session had expired.
        $stemExpired = $stemSource && !$usedStem;

        return response()->json([
            'success'     => true,
            'leadsheet'   => $leadsheetData,
            'source'      => $usedStem ? 'stem' : 'original',
            'stems'       => $usedStem['stems'] ?? null,
            'stemExpired' => $stemExpired,
        ]);
    }

    /**
     * "Transcribe this stem" — re-inference on an isolated audition-session stem
     * (or a sum of the ticked stems) and REPLACE the transcription. The guitar-only
     * stem transcribes far cleaner than the full mix, so this is the re-derive to
     * reach for when the original import was muddied by vocals / piano / drums.
     *
     * Reuses the persisted audition session from the video-sync sidebar (feature
     * 12d) — no re-download / re-separate. Inherits §13's guard.
     */
    public function transcribeStem(
        Request $request,
        Leadsheet $leadsheet,
        AnalysisToLeadsheet $converter,
        VoicingCrossref $crossref,
        \App\Services\MidiTranscriptionService $transcriber
    ) {
        $validated = $request->validate([
            'session'               => 'required|string|max:64',
            'stems'                 => 'nullable|array',
            'stems.*'               => 'string|in:guitar,bass,vocals,drums,piano,other',
            'detection_preset'      => 'nullable|string|in:balanced,sensitive,strict,custom',
            'onset_threshold'       => 'nullable|numeric|min:0.05|max:0.95',
            'frame_threshold'       => 'nullable|numeric|min:0.05|max:0.95',
            'minimum_note_length'   => 'nullable|numeric|min:10|max:500',
            'restrict_guitar_range' => 'nullable|boolean',
            'force'                 => 'nullable|boolean',
        ]);

        $parsed = $leadsheet->parsed_data;
        $raw    = $parsed['transcriptionRaw'] ?? null;

        if (empty($raw)) {
            return response()->json([
                'success' => false,
                'error'   => 'This leadsheet has no cached audio transcription to replace.',
            ], 422);
        }

        // §13 fixed-transcription latch.
        if (($parsed['transcriptionFixed'] ?? false) && empty($validated['force'])) {
            return response()->json([
                'success' => false,
                'fixed'   => true,
                'error'   => 'This transcription is fixed. Re-open tuning first — re-transcribing will discard edits made since.',
            ], 409);
        }

        $stems = array_values(array_intersect(
            $validated['stems'] ?? ['guitar'],
            \App\Services\MidiTranscriptionService::STEM_NAMES
        ));
        if (empty($stems)) $stems = ['guitar'];

        $detectionParams = $this->resolveDetectionParams($validated);
        unset($detectionParams['separate_stem']); // stems are already isolated

        $rawResult = $transcriber->transcribeStemFromSession($validated['session'], $stems, $detectionParams);
        if (!($rawResult['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'error'   => 'Stem transcription failed: ' . ($rawResult['error'] ?? 'unknown error'),
            ], 500);
        }

        $leadsheetData = $this->reassembleFromRawResult(
            $leadsheet, $rawResult, $raw,
            $stems, // the assembly now reflects the stems that were transcribed
            $converter, $crossref,
            // Remember the audition session + stems so a later Re-run detection
            // re-inferences on THIS isolated stem, not the full-mix original.
            ['session' => $validated['session'], 'stems' => $stems]
        );

        return response()->json(['success' => true, 'leadsheet' => $leadsheetData]);
    }

}
