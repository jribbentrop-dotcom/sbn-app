<?php
/**
 * Render a TAB SVG for a slice of a leadsheet.
 * Usage: php render-item-tab.php <slug> <start> <end> [barsPerRow]
 *   start/end = 0-based measure indices (end is exclusive)
 */
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Leadsheet;
use App\Services\TabXmlParser;
use App\Http\Controllers\Admin\PdfController;

$slug       = $argv[1] ?? 'top10';
$start      = (int)($argv[2] ?? 0);
$end        = (int)($argv[3] ?? 4);
$barsPerRow = (int)($argv[4] ?? 4);

$ls = Leadsheet::where('slug', $slug)->first();
if (!$ls || !$ls->tab_xml) {
    fwrite(STDERR, "Leadsheet '$slug' not found or has no tab_xml\n");
    exit(1);
}

// Build chordNames map from json_data
$chordNamesMap = [];
if ($ls->json_data) {
    $json = json_decode($ls->json_data, true);
    foreach ($json['measures'] ?? [] as $i => $m) {
        $names = array_filter(array_map(fn($c) => $c['name'] ?? '', $m['chords'] ?? []));
        if ($names) $chordNamesMap[$i] = array_values($names);
    }
}

$parser  = new TabXmlParser();
$tabData = $parser->parse($ls->tab_xml, $chordNamesMap);
$all     = $tabData['measures'];
$measures = array_slice($all, $start, $end - $start);
foreach ($measures as $i => &$m) { $m['index'] = $i; }
unset($m);

$svg = PdfController::renderTabSvg($measures, $tabData['timeSig'], $barsPerRow, true);
echo $svg;
