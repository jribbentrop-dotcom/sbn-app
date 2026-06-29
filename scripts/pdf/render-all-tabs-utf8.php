<?php
/**
 * Renders all 10 item-page practice TABs and saves as UTF-8 SVG files.
 * Bar numbers are 1-based (matching the content draft / RENDER-PIPELINE.md).
 * Output: C:/temp/tab01.svg … tab10.svg
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Leadsheet;
use App\Services\TabXmlParser;
use App\Http\Controllers\Admin\PdfController;

function renderTab(string $slug, int $start, int $end, int $barsPerRow = 4): string {
    $ls = Leadsheet::where('slug', $slug)->first();
    if (!$ls || !$ls->tab_xml) throw new \Exception("'$slug' missing tab_xml");

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
    // start/end are 1-based inclusive
    $measures = array_slice($tabData['measures'], $start - 1, $end - $start + 1);
    foreach ($measures as $i => &$m) { $m['index'] = $i; }
    unset($m);
    return PdfController::renderTabSvg($measures, $tabData['timeSig'], $barsPerRow, true);
}

// [label => [slug, start(1-based), end(1-based), barsPerRow]]
// Source: TOP10-CONTENT-DRAFT.md practice patterns, confirmed against top10 leadsheet
$jobs = [
    'tab01' => ['top10',                    1,  4, 4],  // Db6/9 → Gb6/9 (in C, displayed as Db)
    'tab02' => ['top10',                    5,  8, 4],  // Cm7(9) → Fm7(9)
    'tab03' => ['top10',                   10, 13, 4],  // Am6 → Dm7(9)
    'tab04' => ['top10',                   21, 22, 4],  // Dm7(9) → G7(13)
    'tab05' => ['top10',                   16, 18, 3],  // Bm7b5 → E7 → Am6  (3 bars)
    'tab06' => ['fotografia',               1,  4, 4],  // Amaj7 → Am7 → D7(9)
    'tab07' => ['the-shadow-of-your-smile', 8, 11, 4],  // Bm7 → B7(b9)/F# → Em (bars 7-10, 1-based = 8-11)
    'tab08' => ['top10',                   19, 21, 4],  // Cmaj7 → C#dim7 → Dm7(9)
    'tab09' => ['top10',                   26, 29, 4],  // Am6 → Abo7(b13)
    'tab10' => ['swonderful',              15, 18, 4],  // Dm7b5 → G7(b13) → Cm7(9)
];

foreach ($jobs as $name => [$slug, $start, $end, $bpr]) {
    echo "Rendering $name ($slug bars $start-$end, bpr=$bpr)... ";
    try {
        $svg = renderTab($slug, $start, $end, $bpr);
        if (empty($svg)) { echo "ERROR: empty\n"; continue; }

        $path = "C:/temp/{$name}.svg";
        file_put_contents($path, $svg);

        $bytes = file_get_contents($path);
        $bom = strlen($bytes) >= 2 && ord($bytes[0]) === 0xFF && ord($bytes[1]) === 0xFE;
        preg_match('/viewBox="0 0 ([\d.]+) ([\d.]+)"/', $svg, $m);
        echo ($m[0] ?? '?') . ($bom ? " [UTF-16!]" : " [UTF-8 ✓]") . "\n";
    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
    }
}
echo "Done.\n";
