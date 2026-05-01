<?php

$baseline = json_decode(file_get_contents('storage/audits/phase-a-baseline.json'), true);
$current = json_decode(file_get_contents('storage/audits/progressions-20260501-203648.json'), true);

$diffs = [];

foreach ($baseline['runs'] as $i => $baselineRun) {
    $currentRun = $current['runs'][$i] ?? null;
    
    if (!$currentRun) {
        $diffs[] = "Run $i: Missing in current";
        continue;
    }
    
    // Compare content fields only
    $baselineContent = [
        'chords' => $baselineRun['chords'],
        'vl_scores' => $baselineRun['vl_scores'],
        'flags' => $baselineRun['flags'],
    ];
    
    $currentContent = [
        'chords' => $currentRun['chords'],
        'vl_scores' => $currentRun['vl_scores'],
        'flags' => $currentRun['flags'],
    ];
    
    if ($baselineContent !== $currentContent) {
        $diffs[] = "Run $i ({$baselineRun['name']}): Content differs";
        
        // Check chords
        if ($baselineContent['chords'] !== $currentContent['chords']) {
            echo "  Chords differ for {$baselineRun['name']}\n";
            echo "  Baseline: " . json_encode($baselineContent['chords']) . "\n";
            echo "  Current:  " . json_encode($currentContent['chords']) . "\n";
        }
        
        // Check vl_scores
        if ($baselineContent['vl_scores'] !== $currentContent['vl_scores']) {
            echo "  VL scores differ for {$baselineRun['name']}\n";
            echo "  Baseline: " . json_encode($baselineContent['vl_scores']) . "\n";
            echo "  Current:  " . json_encode($currentContent['vl_scores']) . "\n";
        }
        
        // Check flags
        if ($baselineContent['flags'] !== $currentContent['flags']) {
            echo "  Flags differ for {$baselineRun['name']}\n";
            echo "  Baseline: " . json_encode($baselineContent['flags']) . "\n";
            echo "  Current:  " . json_encode($currentContent['flags']) . "\n";
        }
    }
}

if (empty($diffs)) {
    echo "✓ Phase A non-regression verified: All content matches baseline\n";
} else {
    echo "✗ Phase A regression detected:\n";
    foreach ($diffs as $diff) {
        echo "  - $diff\n";
    }
    exit(1);
}
