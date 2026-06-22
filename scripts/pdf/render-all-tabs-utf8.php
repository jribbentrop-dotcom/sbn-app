<?php
/**
 * Renders all required TAB SVGs and saves them as UTF-8 files.
 * Avoids PowerShell > redirect which produces UTF-16 LE.
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Leadsheet;
use App\Services\TabXmlParser;
use App\Http\Controllers\Admin\PdfController;

function renderTab(string $slug, int $start, int $end, int $barsPerRow = 4): string {
    $ls = Leadsheet::where('slug', $slug)->first();
    if (!$ls || !$ls->tab_xml) {
        throw new \Exception("Leadsheet '$slug' not found or has no tab_xml");
    }
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
    // start/end are 1-based bar numbers; convert to 0-based slice
    $measures = array_slice($all, $start - 1, $end - $start + 1);
    foreach ($measures as $i => &$m) { $m['index'] = $i; }
    unset($m);
    return PdfController::renderTabSvg($measures, $tabData['timeSig'], $barsPerRow, true);
}

$jobs = [
    'tab05'    => ['top10',      15, 16, 2],
    'tab06'    => ['fotografia',  1,  4, 4],
    'tab07'    => ['top10',      15, 18, 4],
    'tab08-g7' => ['top10',      22, 22, 4],
    'tab10'    => ['swonderful', 15, 18, 4],
];

foreach ($jobs as $name => [$slug, $start, $end, $bpr]) {
    echo "Rendering $name ($slug bars $start-$end, $bpr/row)... ";
    try {
        $svg = renderTab($slug, $start, $end, $bpr);
        if (empty($svg)) {
            echo "ERROR: empty output\n";
            continue;
        }
        $path = "C:/temp/{$name}.svg";
        file_put_contents($path, $svg);
        $bytes = file_get_contents($path);
        $bom = (strlen($bytes) >= 2 && ord($bytes[0]) === 0xFF && ord($bytes[1]) === 0xFE);
        echo "Saved " . strlen($svg) . " bytes" . ($bom ? " [UTF-16 BOM!]" : " [UTF-8 ✓]") . "\n";
        echo "  viewBox: ";
        preg_match('/viewBox="([^"]+)"/', $svg, $m);
        echo ($m[1] ?? '?') . "\n";
    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
    }
}
echo "Done.\n";
