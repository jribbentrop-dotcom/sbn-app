<?php

namespace App\Console\Commands;

use App\Services\MidiTranscriptionService;
use App\Services\VoicingCrossref;
use Illuminate\Console\Command;

class TranscribeTest extends Command
{
    protected $signature = 'sbn:transcribe-test {filename : The filename in storage/app/temp_audio}';
    protected $description = 'Debug transcription logic on a local audio file';

    public function handle(MidiTranscriptionService $transcriber, VoicingCrossref $crossref)
    {
        $filename = $this->argument('filename');
        $path = storage_path('app/temp_audio/' . $filename);

        if (!file_exists($path)) {
            $this->error("File not found: {$path}");
            return 1;
        }

        $this->info("Starting transcription for: {$filename}...");
        $startTime = microtime(true);

        try {
            // We'll call runPythonTranscription directly to bypass the download/cleanup
            // We need to make it public or use a reflection, but for a test, let's just 
            // add a public method to the service or just use the private logic here.
            // Actually, I'll just add a public "transcribeLocal" to the service.
            
            $reflection = new \ReflectionClass($transcriber);
            $method = $reflection->getMethod('runPythonTranscription');
            $method->setAccessible(true);
            
            $result = $method->invoke($transcriber, $path);
            
            $duration = round(microtime(true) - $startTime, 2);
            $this->info("Transcription finished in {$duration}s");
            
            $this->info("Tempo: " . round($result['tempo']) . " BPM");
            $this->info("Duration: " . round($result['duration'], 2) . "s");
            $this->info("Total Beats: " . count($result['beats']));
            
            $headers = ['Beat', 'Pitches', 'Identified Chord', 'Confidence'];
            $rows = [];
            
            foreach (array_slice($result['beats'], 0, 20) as $i => $beat) {
                $pitches = implode(', ', $beat['notes']);
                $id = !empty($beat['notes']) ? $crossref->identifyFromMidi($beat['notes']) : ['name' => '/', 'confidence' => 'n/a'];
                
                $rows[] = [
                    $i + 1,
                    $pitches ?: '-',
                    $id['name'],
                    $id['confidence']
                ];
            }
            
            $this->table($headers, $rows);
            $this->info("... (showing first 20 beats)");

        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
