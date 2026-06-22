<?php
/**
 * Final consolidated TAB injection for top10-bossa-nova.html.
 * Replaces items 05, 06, 07, 10 and appends G7(13) to item 08.
 * All SVGs are pre-rendered UTF-8 files in C:/temp/.
 */

$file = __DIR__ . '/../../design/pdf-system/templates/top10-bossa-nova.html';
$html = file_get_contents($file);

// Load SVGs
$svgs = [];
foreach (['tab05', 'tab06', 'tab07', 'tab08-g7', 'tab10'] as $name) {
    $path = "C:/temp/{$name}.svg";
    $content = file_get_contents($path);
    // Verify not UTF-16
    if (strlen($content) >= 2 && ord($content[0]) === 0xFF && ord($content[1]) === 0xFE) {
        echo "ERROR: $name is UTF-16! Re-run render-all-tabs-utf8.php\n";
        exit(1);
    }
    $svgs[$name] = $content;
    preg_match('/viewBox="0 0 ([\d.]+) ([\d.]+)"/', $content, $m);
    echo "$name: " . ($m[0] ?? '?') . "\n";
}

// Find all pattern-tab divs — use a flexible regex that tolerates any SVG content
// (including corrupted UTF-16 residue from earlier runs)
$pattern = '/<div class="pattern-tab">(.*?)<\/div>/s';
preg_match_all($pattern, $html, $matches, PREG_OFFSET_CAPTURE);

$count = count($matches[0]);
echo "\nFound $count pattern-tab divs\n";
if ($count < 10) {
    echo "ERROR: Expected 10, found $count\n";
    // Show what we found
    foreach ($matches[0] as $i => $m) {
        echo "  #$i: " . substr($m[0], 0, 60) . "\n";
    }
    exit(1);
}

// ---- Item 08 (index 7): extract existing SVG and merge G7(13) ----
$item08Content = $matches[1][7][0]; // inner content of the div
// Find the SVG within it
preg_match('/<svg[^>]*>.*?<\/svg>/s', $item08Content, $svgMatch);
$existingSvg = $svgMatch[0] ?? '';

if (empty($existingSvg)) {
    echo "ERROR: Could not find SVG in item 08\n";
    exit(1);
}

preg_match('/viewBox="0 0 ([\d.]+) ([\d.]+)"/', $existingSvg, $vb);
$existingW = (float)($vb[1] ?? 640);
$existingH = (float)($vb[2] ?? 114);
echo "Item 08 existing SVG: {$existingW}x{$existingH}px\n";

$g7Svg = $svgs['tab08-g7'];
preg_match('/viewBox="0 0 ([\d.]+) ([\d.]+)"/', $g7Svg, $vb2);
$g7W = (float)($vb2[1] ?? 160);
preg_match('/<svg[^>]*>(.*?)<\/svg>/s', $g7Svg, $g7Inner);
$g7Content = $g7Inner[1];

$newW = $existingW + $g7W;
$shiftedG7 = '<g transform="translate(' . $existingW . ', 0)">' . $g7Content . '</g>';

$mergedSvg = preg_replace(
    '/viewBox="0 0 ' . preg_quote((string)$existingW, '/') . ' ' . preg_quote((string)$existingH, '/') . '"/',
    'viewBox="0 0 ' . $newW . ' ' . $existingH . '"',
    $existingSvg, 1
);
$mergedSvg = preg_replace('/width="' . preg_quote((string)$existingW, '/') . '"/', 'width="' . $newW . '"', $mergedSvg, 1);
$mergedSvg = str_replace('</svg>', $shiftedG7 . '</svg>', $mergedSvg);
echo "Item 08 merged SVG: {$newW}x{$existingH}px\n";

// ---- Replacements map: index => new SVG string ----
$replacements = [
    4 => $svgs['tab05'],   // item 05
    5 => $svgs['tab06'],   // item 06
    6 => $svgs['tab07'],   // item 07
    7 => $mergedSvg,       // item 08 (merged)
    9 => $svgs['tab10'],   // item 10
];

// Apply in reverse order so offsets stay valid
krsort($replacements);

foreach ($replacements as $idx => $newSvg) {
    $matchOffset = $matches[0][$idx][1];
    $matchLen    = strlen($matches[0][$idx][0]);
    $newBlock = '<div class="pattern-tab">' . "\n                            " . trim($newSvg) . "\n                        " . '</div>';
    $html = substr_replace($html, $newBlock, $matchOffset, $matchLen);
    echo "Item " . ($idx + 1) . ": injected (" . strlen($newSvg) . " bytes)\n";
}

file_put_contents($file, $html);
echo "\nSaved.\n";

// Verify
$verify = file_get_contents($file);
preg_match_all('/viewBox="0 0 ([\d.]+) ([\d.]+)"/', $verify, $vbs);
echo "All viewBoxes in file: " . implode(', ', array_map(fn($v) => $v . 'px', $vbs[1])) . "\n";
preg_match_all('/<div class="pattern-tab">.*?<\/div>/s', $verify, $divs);
echo "pattern-tab divs found: " . count($divs[0]) . "\n";
