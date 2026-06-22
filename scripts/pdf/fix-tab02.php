<?php
$file = __DIR__ . '/../../design/pdf-system/templates/top10-bossa-nova.html';
$html = file_get_contents($file);

preg_match_all('/<div class="pattern-tab">(.*?)<\/div>/s', $html, $m, PREG_OFFSET_CAPTURE);
$idx = 1; // item 02
$offset = $m[0][$idx][1];
$len    = strlen($m[0][$idx][0]);
$inner  = $m[1][$idx][0];

// Extract the SVG
preg_match('/<svg[^>]*>.*?<\/svg>/s', $inner, $svgMatch);
$svg = $svgMatch[0];

// Remove the trailing empty <g translate(640...)></g>
$svg = preg_replace('/<g transform="translate\(640[^"]*"\)><\/g>/', '', $svg);

// Fix viewBox and width from 800 to 640
$svg = str_replace('viewBox="0 0 800 114"', 'viewBox="0 0 640 114"', $svg);
$svg = str_replace('width="800"', 'width="640"', $svg);

$newBlock = '<div class="pattern-tab">' . "\n                            " . trim($svg) . "\n                        " . '</div>';
$html = substr_replace($html, $newBlock, $offset, $len);
file_put_contents($file, $html);
echo "Done. New viewBox: ";
preg_match('/viewBox="([^"]+)"/', $svg, $vb);
echo $vb[1] . "\n";
