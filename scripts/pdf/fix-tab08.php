<?php
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Leadsheet;
use App\Services\TabXmlParser;
use App\Http\Controllers\Admin\PdfController;

$ls = Leadsheet::where('slug', 'top10')->first();
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
// bars 19-22 = indices 18-21
$measures = array_slice($tabData['measures'], 18, 4);
foreach ($measures as $i => &$m) { $m['index'] = $i; }
unset($m);
$svg = PdfController::renderTabSvg($measures, $tabData['timeSig'], 4, true);
echo "viewBox: "; preg_match('/viewBox="([^"]+)"/', $svg, $vb); echo $vb[1] . "\n";

// Inject into template
$file = __DIR__ . '/../../design/pdf-system/templates/top10-bossa-nova.html';
$html = file_get_contents($file);
preg_match_all('/<div class="pattern-tab">(.*?)<\/div>/s', $html, $m, PREG_OFFSET_CAPTURE);
$idx = 7; // item 08
$offset = $m[0][$idx][1];
$len    = strlen($m[0][$idx][0]);
$newBlock = '<div class="pattern-tab">' . "\n                            " . trim($svg) . "\n                        " . '</div>';
$html = substr_replace($html, $newBlock, $offset, $len);
file_put_contents($file, $html);
echo "Done.\n";
