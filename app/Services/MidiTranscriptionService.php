<?php

namespace App\Services;

use App\Models\Leadsheet;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class MidiTranscriptionService
{
    protected string $pythonPath;
    protected string $scriptPath;
    protected string $separateScriptPath;
    protected string $ytDlpPath;
    protected string $ffmpegPath;

    public function __construct()
    {
        $this->pythonPath = base_path('python_env/python.exe');
        $this->scriptPath = base_path('scripts/transcribe.py');
        $this->separateScriptPath = base_path('scripts/separate_stem.py');
        $this->ytDlpPath = base_path('yt-dlp.exe');
        $this->ffmpegPath = base_path('ffmpeg.exe');
    }

    /**
     * Transcribe a YouTube video into a sequence of chord-ready MIDI buckets.
     *
     * @param array $detectionParams  basic-pitch tuning knobs from the import
     *        modal: onset_threshold, frame_threshold, minimum_note_length,
     *        minimum_frequency, maximum_frequency. Empty = basic-pitch defaults.
     *        Also carries the control flag `separate_stem` (bool) — NOT a
     *        basic-pitch param, stripped before it reaches transcribe.py.
     */
    public function transcribe(string $youtubeId, array $detectionParams = []): array
    {
        // 1. Download Audio (Raw format from YT)
        $rawPath = $this->downloadAudio($youtubeId);

        if (!$rawPath) {
            throw new \Exception("Failed to download audio from YouTube.");
        }

        // 2. Convert to standard WAV (Ensures Python/librosa compatibility)
        $wavPath = $this->convertToWav($rawPath);

        // 2b. Optional: isolate the guitar stem (Demucs) before transcription.
        // Track every intermediate path so cleanup below never leaks files.
        $stemWavPath = null;
        $redownconvertedPath = null;
        if (!empty($detectionParams['separate_stem'])) {
            $stemWavPath = $this->separateStem($wavPath);
            if ($stemWavPath !== $wavPath) {
                // Guarantee basic-pitch still receives 22050Hz mono — the
                // guitar stem demucs writes is 44.1kHz stereo. Explicit output
                // path so we don't ask ffmpeg to overwrite its own input (the
                // stem already ends in .wav, so an extension-swap would collide).
                $redownconvertedPath = $this->convertToWav(
                    $stemWavPath,
                    preg_replace('/\.wav$/i', '.22k.wav', $stemWavPath)
                );
            }
        }
        $transcribePath = $redownconvertedPath ?? $stemWavPath ?? $wavPath;

        // 3. Run Python Transcription
        $result = $this->runPythonTranscription($transcribePath, $this->stripControlFlags($detectionParams));

        // 3b. Preserve a copy of the FULL original recording so the editor can
        // blend the synth transcription against it (MIDI-vs-original A/B). Kept
        // past the cleanup below; the caller moves it into public storage and
        // deletes this temp copy.
        $result['source_audio_path'] = $this->preserveSourceAudio($wavPath);

        // 4. Cleanup
        if (file_exists($rawPath)) unlink($rawPath);
        if (file_exists($wavPath)) unlink($wavPath);
        if ($stemWavPath && $stemWavPath !== $wavPath && file_exists($stemWavPath)) unlink($stemWavPath);
        if ($redownconvertedPath && file_exists($redownconvertedPath)) unlink($redownconvertedPath);

        return $result;
    }

    /**
     * Copy a WAV to a stable temp path that survives the transcription method's
     * own cleanup, so createFromLookup() can persist it as the leadsheet's
     * source audio. Returns the temp path, or null on failure (non-fatal — the
     * blend feature just won't have an original to play).
     */
    protected function preserveSourceAudio(string $wavPath): ?string
    {
        if (!is_file($wavPath)) return null;
        $dir = storage_path('app/temp_audio');
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $dest = $dir . '/source_' . bin2hex(random_bytes(6)) . '.wav';
        return @copy($wavPath, $dest) ? $dest : null;
    }

    protected function downloadAudio(string $youtubeId): ?string
    {
        $tempDir = storage_path('app/temp_audio');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0777, true);
        }

        $filenameTemplate = "{$tempDir}/{$youtubeId}.%(ext)s";

        // Remove existing files for this ID. A prior run may have left a Demucs
        // scratch *directory* (…​.demucs_out); glob matches it too, so recurse
        // into directories rather than unlink() them (unlink on a dir throws).
        foreach (glob("{$tempDir}/{$youtubeId}.*") as $oldFile) {
            if (is_dir($oldFile)) {
                $this->rrmdir($oldFile);
            } else {
                unlink($oldFile);
            }
        }

        // Use -f "ba" (best audio) to avoid needing ffmpeg for conversion
        $cmd = "\"{$this->ytDlpPath}\" -f \"ba\" -o \"{$filenameTemplate}\" \"https://www.youtube.com/watch?v={$youtubeId}\"";
        
        Log::info("Downloading audio: {$cmd}");
        
        $env = getenv();
        $env['PATH'] = base_path() . ';' . ($env['PATH'] ?? '');
        $env['TF_CPP_MIN_LOG_LEVEL'] = '3'; // Suppress TF logging
        
        // Ensure Windows temp directories are set
        $temp = sys_get_temp_dir();
        $env['TEMP'] = $temp;
        $env['TMP'] = $temp;

        $process = proc_open($cmd, [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, $env);

        if (!is_resource($process)) {
            Log::error("Could not start yt-dlp process.");
            return null;
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $returnCode = proc_close($process);

        if ($returnCode !== 0) {
            Log::error("yt-dlp failed (code {$returnCode}): " . $stderr);
            return null;
        }

        // Find the file that was actually downloaded (could be .m4a, .webm, etc)
        $files = glob("{$tempDir}/{$youtubeId}.*");
        return !empty($files) ? $files[0] : null;
    }

    protected function convertToWav(string $inputPath, ?string $outputPath = null): string
    {
        // Default: swap the input's extension for .wav. Callers that must avoid
        // an in-place collision (e.g. re-downconverting a *.wav stem) pass an
        // explicit distinct $outputPath — ffmpeg refuses to write over its own
        // input, which would otherwise silently no-op the conversion.
        $outputPath = $outputPath ?? preg_replace('/\.[^.]+$/', '.wav', $inputPath);

        // Loud, specific warning if the binary is missing — this exact silent
        // failure (ffmpeg.exe absent → convertToWav returns the raw download)
        // masked a bug for a long time: basic-pitch tolerates raw .webm/.m4a,
        // but Demucs does not, so stem separation broke downstream instead.
        if (!is_file($this->ffmpegPath)) {
            Log::error("FFmpeg binary not found at {$this->ffmpegPath} — audio will NOT be downconverted to 22050Hz mono. Stem separation requires a real WAV. Returning raw input.");
            return $inputPath;
        }

        // Use ffmpeg to convert to standard mono 22050Hz WAV (ideal for basic-pitch)
        $cmd = "\"{$this->ffmpegPath}\" -y -i \"{$inputPath}\" -ar 22050 -ac 1 \"{$outputPath}\"";

        Log::info("Converting to WAV: {$cmd}");

        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            Log::error("FFmpeg conversion failed (returning raw input): " . implode("\n", $output));
            return $inputPath; // Fallback to raw if conversion fails
        }

        return $outputPath;
    }

    /**
     * Transcribe a locally-uploaded audio file (mp3/wav/m4a/ogg).
     * Converts to WAV via ffmpeg then runs the same Python pipeline as transcribe().
     */
    public function transcribeLocalFile(string $uploadedPath, array $detectionParams = []): array
    {
        $wavPath = $this->convertToWav($uploadedPath);

        $stemWavPath = null;
        $redownconvertedPath = null;

        try {
            if (!empty($detectionParams['separate_stem'])) {
                $stemWavPath = $this->separateStem($wavPath);
                if ($stemWavPath !== $wavPath) {
                    // Explicit distinct output — the stem ends in .wav, so an
                    // extension-swap would collide and ffmpeg would no-op.
                    $redownconvertedPath = $this->convertToWav(
                        $stemWavPath,
                        preg_replace('/\.wav$/i', '.22k.wav', $stemWavPath)
                    );
                }
            }
            $transcribePath = $redownconvertedPath ?? $stemWavPath ?? $wavPath;

            $result = $this->runPythonTranscription($transcribePath, $this->stripControlFlags($detectionParams));
            $result['source_audio_path'] = $this->preserveSourceAudio($wavPath);
        } finally {
            if (file_exists($wavPath) && $wavPath !== $uploadedPath) {
                unlink($wavPath);
            }
            if ($stemWavPath && $stemWavPath !== $wavPath && file_exists($stemWavPath)) {
                unlink($stemWavPath);
            }
            if ($redownconvertedPath && file_exists($redownconvertedPath)) {
                unlink($redownconvertedPath);
            }
        }

        return $result;
    }

    /**
     * Strip control flags (e.g. separate_stem) that must never reach
     * transcribe.py's *.params.json — that file is basic-pitch keyword args
     * only (onset/frame/minLen/frequency keys).
     */
    protected function stripControlFlags(array $detectionParams): array
    {
        unset($detectionParams['separate_stem']);
        return $detectionParams;
    }

    /**
     * Recursively delete a directory (used to clean up Demucs scratch trees
     * that a crashed separation run may leave behind).
     */
    protected function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * Isolate the guitar stem from a WAV via Demucs (htdemucs_6s), so
     * basic-pitch sees a cleaner signal on recordings with vocals/other
     * instruments. Runs on GPU (falls back to CPU inside the Python script if
     * CUDA isn't available).
     *
     * A bad/failed separation must NEVER block an import — on any failure
     * this logs a warning and returns the original $wavPath unchanged.
     */
    protected function separateStem(string $wavPath): string
    {
        // Only run on an actual WAV. If convertToWav() failed upstream it
        // returns the un-converted input (e.g. a .webm), and Demucs on that
        // produces an empty scratch tree that then breaks cleanup. Bail to the
        // original path instead — separation is best-effort, never a blocker.
        if (!preg_match('/\.wav$/i', $wavPath)) {
            Log::warning("Stem separation skipped: input is not a WAV ({$wavPath}). Falling back to original audio.");
            return $wavPath;
        }

        $inputPath = str_replace('\\', '/', $wavPath);
        $outputPath = preg_replace('/\.wav$/i', '', $inputPath) . '.guitar_stem.wav';
        $scratchDir = $inputPath . '.demucs_out'; // must match separate_stem.py

        $pythonPath = str_replace('\\', '/', $this->pythonPath);
        $scriptPath = str_replace('\\', '/', $this->separateScriptPath);

        $cmd = "\"{$pythonPath}\" \"{$scriptPath}\" \"{$inputPath}\" \"{$outputPath}\" --device cuda";

        Log::info("Running stem separation: {$cmd}");

        // Mirror the env injection used by runPythonTranscription()/downloadAudio().
        $env = getenv();
        $env['PATH'] = base_path() . ';' . ($env['PATH'] ?? '');
        $env['TF_CPP_MIN_LOG_LEVEL'] = '3';

        $temp = sys_get_temp_dir();
        $env['TEMP'] = $temp;
        $env['TMP'] = $temp;

        // Send stderr to a FILE, not a pipe. Demucs is verbose (tqdm progress
        // bars); a stderr pipe fills its OS buffer and deadlocks proc_open on
        // Windows (child blocks writing stderr while PHP blocks reading stdout).
        // A file sink has no buffer ceiling, so it can never block.
        $stderrFile = $inputPath . '.demucs_stderr.log';
        $process = proc_open($cmd, [
            1 => ['pipe', 'w'],
            2 => ['file', $stderrFile, 'w'],
        ], $pipes, null, $env);

        if (!is_resource($process)) {
            Log::warning("Stem separation: could not start process. Falling back to original audio.");
            @unlink($stderrFile);
            $this->rrmdir($scratchDir);
            return $wavPath;
        }

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $returnCode = proc_close($process);

        // Read stderr from the file only after the process has fully exited.
        $stderr = is_file($stderrFile) ? (file_get_contents($stderrFile) ?: '') : '';
        @unlink($stderrFile);

        if ($returnCode !== 0) {
            Log::warning("Stem separation failed (code {$returnCode}): {$stderr}. Falling back to original audio.");
            $this->rrmdir($scratchDir);
            return $wavPath;
        }

        $parts = explode("JSON_START", $stdout);
        $json = trim(end($parts));
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE || !($data['success'] ?? false)) {
            Log::warning("Stem separation returned failure: " . ($data['error'] ?? $stdout) . ". Falling back to original audio.");
            $this->rrmdir($scratchDir);
            return $wavPath;
        }

        if (!file_exists($outputPath)) {
            Log::warning("Stem separation reported success but output file is missing: {$outputPath}. Falling back to original audio.");
            $this->rrmdir($scratchDir);
            return $wavPath;
        }

        // Success — the Python script already removes its scratch tree, but
        // sweep again in case it exited before cleanup.
        $this->rrmdir($scratchDir);
        return $outputPath;
    }

    /**
     * The six stems htdemucs_6s produces. Must match STEM_NAMES in
     * separate_stem.py and the checkbox names in the import modal.
     */
    public const STEM_NAMES = ['guitar', 'bass', 'vocals', 'drums', 'piano', 'other'];

    /** Absolute path to a stem session directory (storage/app/stems/{session}). */
    public function stemSessionDir(string $session): string
    {
        // Guard the token so it can never escape storage/app/stems.
        $session = preg_replace('/[^A-Za-z0-9_-]/', '', $session);
        return storage_path('app/stems/' . $session);
    }

    /** Delete a stem session directory and everything in it. */
    public function removeStemSession(string $session): void
    {
        $this->rrmdir($this->stemSessionDir($session));
    }

    /**
     * PHASE 1 (audition): download/convert the source, run Demucs once, and
     * persist ALL six stems under storage/app/stems/{session}/ so the admin can
     * play each and pick which to transcribe. Does NOT transcribe.
     *
     * @param string      $source     YouTube id, or an absolute path to an uploaded file
     * @param string|null $sourceKind 'youtube' | 'upload'
     * @return array{success:bool, session?:string, stems?:array<string>, error?:string}
     */
    public function separateStemsToSession(string $source, string $sourceKind = 'youtube'): array
    {
        $rawPath = null;
        $wavPath = null;
        try {
            if ($sourceKind === 'youtube') {
                $rawPath = $this->downloadAudio($source);
                if (!$rawPath) {
                    return ['success' => false, 'error' => 'Failed to download audio from YouTube.'];
                }
                $wavPath = $this->convertToWav($rawPath);
            } else {
                $rawPath = $source; // caller owns cleanup of the uploaded temp file
                $wavPath = $this->convertToWav($source);
            }

            if (!preg_match('/\.wav$/i', $wavPath)) {
                return ['success' => false, 'error' => 'Audio could not be converted to WAV (is ffmpeg.exe present?). Stem separation needs a real WAV.'];
            }

            $session = bin2hex(random_bytes(8));
            $sessionDir = $this->stemSessionDir($session);
            if (!is_dir($sessionDir)) {
                mkdir($sessionDir, 0777, true);
            }

            $data = $this->runSeparateAll($wavPath, $sessionDir);
            if (!($data['success'] ?? false)) {
                $this->rrmdir($sessionDir);
                return ['success' => false, 'error' => $data['error'] ?? 'Stem separation failed.'];
            }

            // Keep the full original mix in the session so a later
            // transcribeFromSession() can persist it as the leadsheet's source
            // audio (blend against the synth), regardless of which stems the
            // admin picks to transcribe.
            @copy($wavPath, $sessionDir . '/_original.wav');

            return [
                'success' => true,
                'session' => $session,
                'stems'   => array_keys($data['stems'] ?? []),
            ];
        } catch (\Throwable $e) {
            Log::error('separateStemsToSession failed: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        } finally {
            // Clean up the download + full-mix WAV; the persisted stems live in
            // the session dir now. Uploaded temp file is the caller's to remove.
            if ($sourceKind === 'youtube' && $rawPath && file_exists($rawPath)) {
                @unlink($rawPath);
            }
            if ($wavPath && $wavPath !== $rawPath && file_exists($wavPath)) {
                @unlink($wavPath);
            }
        }
    }

    /**
     * PHASE 2 (transcribe): sum the chosen stems from a session dir into one
     * WAV, downconvert to 22050Hz mono, and run basic-pitch. Reuses the
     * persisted stems — no Demucs re-run.
     *
     * @param string   $session
     * @param string[] $stems           subset of STEM_NAMES
     * @param array    $detectionParams basic-pitch knobs (separate_stem stripped)
     */
    public function transcribeFromSession(string $session, array $stems, array $detectionParams = []): array
    {
        $sessionDir = $this->stemSessionDir($session);
        if (!is_dir($sessionDir)) {
            return ['success' => false, 'error' => "Stem session not found (it may have expired): {$session}"];
        }

        $stems = array_values(array_intersect($stems, self::STEM_NAMES));
        if (empty($stems)) {
            $stems = ['guitar']; // never transcribe silence
        }

        $summedPath = null;
        $redownconvertedPath = null;
        try {
            $summedPath = $sessionDir . '/_mix.wav';
            $sum = $this->runSumStems($sessionDir, $stems, $summedPath);
            if (!($sum['success'] ?? false)) {
                return ['success' => false, 'error' => $sum['error'] ?? 'Failed to mix stems.'];
            }

            // Demucs stems are 44.1kHz stereo — downconvert for basic-pitch.
            $redownconvertedPath = $this->convertToWav(
                $summedPath,
                preg_replace('/\.wav$/i', '.22k.wav', $summedPath)
            );

            $result = $this->runPythonTranscription(
                $redownconvertedPath ?? $summedPath,
                $this->stripControlFlags($detectionParams)
            );

            // Preserve the full original mix (kept in the session as _original.wav)
            // as the leadsheet's source audio. Falls back to the summed stems if
            // the original wasn't captured for some reason.
            $original = $sessionDir . '/_original.wav';
            $result['source_audio_path'] = $this->preserveSourceAudio(
                is_file($original) ? $original : $summedPath
            );

            return $result;
        } finally {
            if ($summedPath && file_exists($summedPath)) @unlink($summedPath);
            if ($redownconvertedPath && file_exists($redownconvertedPath)) @unlink($redownconvertedPath);
        }
    }

    /**
     * Re-inference on a WAV that already lives on disk — no download, no YouTube,
     * no stem separation. The shared primitive behind BOTH re-detect surfaces:
     *   - redetect (T9 Tier 2): the persisted sourceAudio (public/audio/source/…),
     *   - transcribe-stem: a summed session stem.
     * Downconverts to 22050Hz mono (basic-pitch's expected input — the resident
     * file may be 44.1kHz stereo, e.g. a persisted original or a demucs stem),
     * runs basic-pitch, and returns the raw Python result. Does NOT preserve a new
     * sourceAudio (the caller already has one — re-inference must never clobber the
     * blend original). The passed WAV is left in place; only the temp downconvert
     * is cleaned up.
     */
    public function transcribeResidentAudio(string $wavPath, array $detectionParams = []): array
    {
        if (!is_file($wavPath)) {
            return ['success' => false, 'error' => "Resident audio not found: {$wavPath}"];
        }

        $downconverted = null;
        try {
            // Always downconvert to a fresh temp copy — never overwrite the resident
            // source (it's the persisted blend original / an in-session stem).
            $tempDir = storage_path('app/temp_audio');
            if (!is_dir($tempDir)) mkdir($tempDir, 0777, true);
            $downconverted = $tempDir . '/redetect_' . bin2hex(random_bytes(6)) . '.wav';
            $downconverted = $this->convertToWav($wavPath, $downconverted);

            return $this->runPythonTranscription(
                $downconverted,
                $this->stripControlFlags($detectionParams)
            );
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        } finally {
            if ($downconverted && $downconverted !== $wavPath && file_exists($downconverted)) {
                @unlink($downconverted);
            }
        }
    }

    /**
     * Re-inference on a single audition-session stem (or a sum of them), reusing
     * the persisted audition session — no re-download / re-separate. Sums the
     * chosen stems, downconverts, runs basic-pitch. The session is NOT swept here
     * (the caller decides — the audition sidebar keeps it for further tries).
     */
    public function transcribeStemFromSession(string $session, array $stems, array $detectionParams = []): array
    {
        $sessionDir = $this->stemSessionDir($session);
        if (!is_dir($sessionDir)) {
            return ['success' => false, 'error' => "Stem session not found (it may have expired): {$session}"];
        }

        $stems = array_values(array_intersect($stems, self::STEM_NAMES));
        if (empty($stems)) {
            $stems = ['guitar'];
        }

        $summedPath = $sessionDir . '/_redetect_mix.wav';
        try {
            $sum = $this->runSumStems($sessionDir, $stems, $summedPath);
            if (!($sum['success'] ?? false)) {
                return ['success' => false, 'error' => $sum['error'] ?? 'Failed to mix stems.'];
            }
            return $this->transcribeResidentAudio($summedPath, $detectionParams);
        } finally {
            if (file_exists($summedPath)) @unlink($summedPath);
        }
    }

    /** Shell separate_stem.py --all-stems; returns its decoded JSON payload. */
    protected function runSeparateAll(string $wavPath, string $outputDir): array
    {
        $inputPath = str_replace('\\', '/', $wavPath);
        $outDir    = str_replace('\\', '/', $outputDir);
        $cmd = sprintf(
            '"%s" "%s" --all-stems "%s" "%s" --device cuda',
            str_replace('\\', '/', $this->pythonPath),
            str_replace('\\', '/', $this->separateScriptPath),
            $inputPath,
            $outDir
        );
        return $this->runSeparateCommand($cmd, $inputPath . '.demucs_out');
    }

    /** Shell separate_stem.py --sum; returns its decoded JSON payload. */
    protected function runSumStems(string $stemsDir, array $stems, string $outputPath): array
    {
        $cmd = sprintf(
            '"%s" "%s" --sum "%s" --stems-dir "%s" "%s"',
            str_replace('\\', '/', $this->pythonPath),
            str_replace('\\', '/', $this->separateScriptPath),
            implode(',', $stems),
            str_replace('\\', '/', $stemsDir),
            str_replace('\\', '/', $outputPath)
        );
        return $this->runSeparateCommand($cmd, null);
    }

    /**
     * Run a separate_stem.py invocation with the same env injection + stderr-to-
     * file discipline as separateStem(), and parse its JSON_START payload.
     * $scratchDir (if given) is swept on failure.
     */
    protected function runSeparateCommand(string $cmd, ?string $scratchDir): array
    {
        Log::info("Running stem command: {$cmd}");

        $env = getenv();
        $env['PATH'] = base_path() . ';' . ($env['PATH'] ?? '');
        $env['TF_CPP_MIN_LOG_LEVEL'] = '3';
        $temp = sys_get_temp_dir();
        $env['TEMP'] = $temp;
        $env['TMP'] = $temp;

        // stderr → file, never a pipe (Demucs tqdm floods it and deadlocks).
        $stderrFile = sys_get_temp_dir() . '/demucs_' . uniqid() . '.log';
        $process = proc_open($cmd, [
            1 => ['pipe', 'w'],
            2 => ['file', $stderrFile, 'w'],
        ], $pipes, null, $env);

        if (!is_resource($process)) {
            @unlink($stderrFile);
            if ($scratchDir) $this->rrmdir($scratchDir);
            return ['success' => false, 'error' => 'Could not start stem process.'];
        }

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $returnCode = proc_close($process);

        $stderr = is_file($stderrFile) ? (file_get_contents($stderrFile) ?: '') : '';
        @unlink($stderrFile);

        if ($returnCode !== 0) {
            if ($scratchDir) $this->rrmdir($scratchDir);
            return ['success' => false, 'error' => "Stem process failed (code {$returnCode}): {$stderr}"];
        }

        $parts = explode('JSON_START', $stdout);
        $data = json_decode(trim(end($parts)), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            if ($scratchDir) $this->rrmdir($scratchDir);
            return ['success' => false, 'error' => 'Stem process returned invalid JSON: ' . $stdout];
        }
        return $data;
    }

    /**
     * Sweep stem session dirs older than $maxAgeHours (orphaned separations
     * where the admin never completed a transcription). Called by a scheduled
     * command. Returns the number of sessions removed.
     */
    public function sweepStaleStemSessions(int $maxAgeHours = 6): int
    {
        $base = storage_path('app/stems');
        if (!is_dir($base)) {
            return 0;
        }
        $cutoff = time() - ($maxAgeHours * 3600);
        $removed = 0;
        foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (filemtime($dir) < $cutoff) {
                $this->rrmdir($dir);
                $removed++;
            }
        }
        return $removed;
    }

    protected function runPythonTranscription(string $audioPath, array $detectionParams = []): array
    {
        // Use forward slashes even on Windows for Python/Librosa stability
        $audioPath = str_replace('\\', '/', $audioPath);
        $scriptPath = str_replace('\\', '/', $this->scriptPath);
        $pythonPath = str_replace('\\', '/', $this->pythonPath);

        $cmd = "\"{$pythonPath}\" \"{$scriptPath}\" \"{$audioPath}\"";

        // Pass detection knobs via a temp JSON file, NOT an inline argument.
        // The shell strips the double quotes inside `{"k":v}` when JSON is
        // passed as a bare arg, so the params would arrive corrupt and the
        // script would silently fall back to basic-pitch defaults.
        $paramsPath = null;
        if (!empty($detectionParams)) {
            $paramsPath = $audioPath . '.params.json';
            file_put_contents($paramsPath, json_encode($detectionParams));
            $cmd .= " \"" . str_replace('\\', '/', $paramsPath) . "\"";
        }
        
        Log::info("Running Python transcription: {$cmd}");
        
        // Add project root to PATH so the script can find ffmpeg.exe/ffprobe.exe
        $env = getenv();
        $env['PATH'] = base_path() . ';' . ($env['PATH'] ?? '');
        $env['TF_CPP_MIN_LOG_LEVEL'] = '3'; // Suppress TF logging
        
        // Ensure Windows temp directories are set
        $temp = sys_get_temp_dir();
        $env['TEMP'] = $temp;
        $env['TMP'] = $temp;

        $process = proc_open($cmd, [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, $env);

        if (!is_resource($process)) {
            throw new \Exception("Could not start transcription process.");
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $returnCode = proc_close($process);

        if ($paramsPath && file_exists($paramsPath)) {
            unlink($paramsPath);
        }

        if ($returnCode !== 0) {
            Log::error("Python transcription failed (code {$returnCode}): " . $stderr);
            throw new \Exception("Transcription engine failed: " . $stderr);
        }

        // Find the JSON part in the output
        $parts = explode("JSON_START", $stdout);
        $json = trim(end($parts));

        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Invalid JSON output from transcription engine.");
        }

        return $data;
    }
}
