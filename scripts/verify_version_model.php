<?php
// Parity check: the new LeadsheetVersion accessors must match the legacy
// Leadsheet accessors for the same data. Run: php artisan tinker < this, or
// via a throwaway artisan command. Standalone bootstrap:

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Leadsheet;

$mismatches = 0;
$checked = 0;

foreach (Leadsheet::with('defaultVersion')->get() as $ls) {
    $v = $ls->defaultVersion;
    if (!$v) {
        echo "NO DEFAULT VERSION: leadsheet {$ls->id} {$ls->title}\n";
        $mismatches++;
        continue;
    }
    $checked++;

    // Compare legacy (leadsheet) vs new (version) accessors.
    $pairs = [
        'parsed_data sections' => [count($ls->parsed_data['sections'] ?? []), count($v->parsed_data['sections'] ?? [])],
        'sectionCount'         => [$ls->section_count, $v->section_count],
        'voicingCount'         => [$ls->voicing_count, $v->voicing_count],
        'hasMelody'            => [$ls->has_melody, $v->has_melody],
        'chordNames count'     => [count($ls->getChordNames()), count($v->getChordNames())],
        'computeMeasureCount'  => [$ls->computeMeasureCount(), $v->computeMeasureCount()],
    ];

    foreach ($pairs as $label => [$old, $new]) {
        if ($old != $new) {
            echo "MISMATCH [{$ls->id} {$ls->title}] {$label}: legacy={$old} version={$new}\n";
            $mismatches++;
        }
    }
}

echo "\nchecked {$checked} leadsheets, {$mismatches} mismatches\n";
echo $mismatches === 0 ? "PARITY OK\n" : "PARITY FAILED\n";
