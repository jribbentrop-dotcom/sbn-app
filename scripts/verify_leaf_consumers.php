<?php
// Stage 4.4: confirm leaf consumers read the same data via the default-version path
// as they would off the legacy leadsheet columns.

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Leadsheet;

$mismatch = 0; $checked = 0;

foreach (Leadsheet::with('defaultVersion')->get() as $ls) {
    $v = $ls->defaultVersion;
    if (!$v) { echo "no default version: {$ls->slug}\n"; $mismatch++; continue; }
    $checked++;

    // parsed_data parity (HomeController / SyncedPlayer / CourseController read this)
    $legacyJson  = json_encode($ls->parsed_data);
    $versionJson = json_encode($v->parsed_data);
    if ($legacyJson !== $versionJson) {
        echo "  parsed_data mismatch: {$ls->slug}\n"; $mismatch++;
    }

    // melody tab parity (PdfController reads version->melody_tab_xml vs legacy tab_xml)
    $legacyTab  = (string) $ls->tab_xml;
    $versionTab = (string) $v->melody_tab_xml;
    if ($legacyTab !== $versionTab) {
        echo "  melody_tab mismatch: {$ls->slug} (legacy=" . strlen($legacyTab) . " version=" . strlen($versionTab) . ")\n";
        $mismatch++;
    }
}

echo "\nleaf parity: checked {$checked}, {$mismatch} mismatches\n";
echo $mismatch === 0 ? "STAGE 4.4 OK\n" : "STAGE 4.4 ISSUES\n";
