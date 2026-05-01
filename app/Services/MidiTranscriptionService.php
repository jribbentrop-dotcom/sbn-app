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

    public function __construct()
    {
        $this->pythonPath = base_path('python_env/python.exe');
        $this->scriptPath = base_path('scripts/transcribe.py');
        $this->ytDlpPath = base_path('yt-dlp.exe');
    }

    /**
     * Transcribe a YouTube video into a sequence of chord-ready MIDI buckets.
     */
    public function transcribe(string $youtubeId): array
    {
        // 1. Download Audio
        $audioPath = $this->downloadAudio($youtubeId);
        
        if (!$audioPath) {
            throw new \Exception("Failed to download audio from YouTube.");
        }

        // 2. Run Python Transcription
        $result = $this->runPythonTranscription($audioPath);

        // 3. Cleanup
        if (file_exists($audioPath)) {
            unlink($audioPath);
        }

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
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            Log::error("yt-dlp failed: " . implode("\n", $output));
            return null;
        }

        // Find the file that was actually downloaded (could be .m4a, .webm, etc)
        $files = glob("{$tempDir}/{$youtubeId}.*");
        return !empty($files) ? $files[0] : null;
    }

    protected function runPythonTranscription(string $audioPath): array
    {
        $cmd = "\"{$this->pythonPath}\" \"{$this->scriptPath}\" \"{$audioPath}\"";
        
        Log::info("Running Python transcription: {$cmd}");
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            Log::error("Python transcription failed: " . implode("\n", $output));
            throw new \Exception("Transcription engine failed.");
        }

        $json = implode("", $output);
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error("Invalid JSON from Python: {$json}");
            throw new \Exception("Invalid output from transcription engine.");
        }

        return $data;
    }
}
