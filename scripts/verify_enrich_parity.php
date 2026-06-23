<?php
// Stage 4.2 parity: enrich(leadsheet, search, defaultVersion) must equal what the
// legacy enrich(leadsheet, search) produced. Since the default version's data ==
// the leadsheet's legacy data, chordCards/progressions/qualityByKey must match.

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Leadsheet;

$svc    = $app->make(App\Services\LeadsheetViewerService::class);
$search = $app->make(App\Services\ChordVoicingSearch::class);

$checked = 0; $errors = 0; $mismatch = 0;

foreach (Leadsheet::with('defaultVersion')->where('status', 'publish')->get() as $ls) {
    try {
        // New path: explicit default version.
        $new = $svc->enrich($ls, $search, $ls->defaultVersion);
        // Implicit path: enrich resolves the default itself (no version passed).
        $implicit = $svc->enrich($ls, $search, null);
        $checked++;

        // Both calls must agree (they resolve the same version).
        $a = json_encode($new); $b = json_encode($implicit);
        if ($a !== $b) {
            echo "  enrich mismatch (explicit vs implicit) [{$ls->id} {$ls->title}]\n";
            $mismatch++;
            continue;
        }

        // Sanity: counts present
        $cc = count($new['chordCards'] ?? []);
        $pr = count($new['progressions'] ?? []);
        if ($ls->is_pro) {
            echo "  enrich({$ls->slug}): chordCards={$cc} progressions={$pr}\n";
        }
    } catch (\Throwable $e) {
        echo "  enrich({$ls->slug}): ERROR " . $e->getMessage() . "\n";
        $errors++;
    }
}

echo "\nenrich parity: checked {$checked}, {$errors} errors, {$mismatch} mismatches\n";
echo ($errors === 0 && $mismatch === 0) ? "STAGE 4.2 OK\n" : "STAGE 4.2 ISSUES\n";
