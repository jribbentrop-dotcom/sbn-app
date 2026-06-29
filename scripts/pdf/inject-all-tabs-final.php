<?php
/**
 * Injects all 10 item-page practice TABs into top10-bossa-nova.html.
 * Replaces all pattern-tab divs in order (index 0 = item 01 … index 9 = item 10).
 * All SVGs must be pre-rendered by render-all-tabs-utf8.php.
 */

$file = __DIR__ . '/../../design/pdf-system/templates/top10-bossa-nova.html';
$html = file_get_contents($file);

// Load all 10 SVGs
$svgs = [];
for ($i = 1; $i <= 10; $i++) {
    $name = sprintf('tab%02d', $i);
    $path = "C:/temp/{$name}.svg";
    $content = @file_get_contents($path);
    if (!$content) { echo "ERROR: cannot read $path\n"; exit(1); }
    if (strlen($content) >= 2 && ord($content[0]) === 0xFF && ord($content[1]) === 0xFE) {
        echo "ERROR: $name is UTF-16 — re-run render-all-tabs-utf8.php\n"; exit(1);
    }
    preg_match('/viewBox="0 0 ([\d.]+) ([\d.]+)"/', $content, $m);
    echo "$name: " . ($m[0] ?? '?') . "\n";
    $svgs[$i] = $content;
}

// Find all pattern-tab divs
preg_match_all('/<div class="pattern-tab">(.*?)<\/div>/s', $html, $matches, PREG_OFFSET_CAPTURE);
$count = count($matches[0]);
echo "\nFound $count pattern-tab divs\n";
if ($count !== 10) {
    echo "ERROR: expected 10\n";
    exit(1);
}

// Replace in reverse order so byte offsets stay valid
for ($idx = 9; $idx >= 0; $idx--) {
    $matchOffset = $matches[0][$idx][1];
    $matchLen    = strlen($matches[0][$idx][0]);
    $newBlock    = '<div class="pattern-tab">' . trim($svgs[$idx + 1]) . '</div>';
    $html        = substr_replace($html, $newBlock, $matchOffset, $matchLen);
    echo "Item " . ($idx + 1) . ": injected\n";
}

file_put_contents($file, $html);
echo "\nSaved.\n";

preg_match_all('/<div class="pattern-tab">.*?<\/div>/s', $html, $divs);
echo "pattern-tab divs after: " . count($divs[0]) . "\n";
