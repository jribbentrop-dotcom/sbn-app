<?php

namespace App\Services;

use App\Models\Leadsheet;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class MidiTranscriptionService
{
    protected string $pythonPath;
    protected string $scriptPath;
    protected string $ytDlpPath;
    protected string $ffmpegPath;

    public function __construct()
    {
        $this->pythonPath = base_path('python_env/python.exe');
        $this->scriptPath = base_path('scripts/transcribe.py');
        $this->ytDlpPath = base_path('yt-dlp.exe');
        $this->ffmpegPath = base_path('ffmpeg.exe');
    }

    /**
     * Transcribe a YouTube video into a sequence of chord-ready MIDI buckets.
     *
     * @param array $detectionParams  basic-pitch tuning knobs from the import
     *        modal: onset_threshold, frame_threshold, minimum_note_length,
     *        minimum_frequency, maximum_frequency. Empty = basic-pitch defaults.
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

        // 3. Run Python Transcription
        $result = $this->runPythonTranscription($wavPath, $detectionParams);

        // 4. Cleanup
        if (file_exists($rawPath)) unlink($rawPath);
        if (file_exists($wavPath)) unlink($wavPath);

        return $result;
    }

    protected function downloadAudio(string $youtubeId): ?string
    {
        $tempDir = storage_path('app/temp_audio');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0777, true);
        }

        $filenameTemplate = "{$tempDir}/{$youtubeId}.%(ext)s";

        // Remove existing files for this ID
        foreach (glob("{$tempDir}/{$youtubeId}.*") as $oldFile) {
            unlink($oldFile);
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

    protected function convertToWav(string $inputPath): string
    {
        $outputPath = preg_replace('/\.[^.]+$/', '.wav', $inputPath);
        
        // Use ffmpeg to convert to standard mono 22050Hz WAV (ideal for basic-pitch)
        $cmd = "\"{$this->ffmpegPath}\" -y -i \"{$inputPath}\" -ar 22050 -ac 1 \"{$outputPath}\"";
        
        Log::info("Converting to WAV: {$cmd}");
        
        exec($cmd, $output, $returnCode);
        
        if ($returnCode !== 0) {
            Log::error("FFmpeg conversion failed: " . implode("\n", $output));
            return $inputPath; // Fallback to raw if conversion fails
        }
        
        return $outputPath;
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
