<?php
// Stage 4.1 parity: the version-scoped progressions query must return the same
// progression IDs for the default version as the old leadsheet-scoped query.
// Also smoke-test that show() runs without throwing.

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Leadsheet;
use Illuminate\Support\Facades\DB;

$mismatch = 0; $checked = 0;

foreach (Leadsheet::with('defaultVersion')->get() as $ls) {
    $v = $ls->defaultVersion;
    if (!$v) { echo "no default version: {$ls->id}\n"; $mismatch++; continue; }
    $checked++;

    $oldIds = DB::table('sbn_progression_occurrences')->where('leadsheet_id', $ls->id)
        ->distinct()->pluck('progression_id')->sort()->values()->all();
    $newIds = DB::table('sbn_progression_occurrences')->where('version_id', $v->id)
        ->distinct()->pluck('progression_id')->sort()->values()->all();

    if ($oldIds != $newIds) {
        echo "PROGRESSION MISMATCH [{$ls->id} {$ls->title}] old=" . json_encode($oldIds) . " new=" . json_encode($newIds) . "\n";
        $mismatch++;
    }
}

echo "\nprogression parity: checked {$checked}, {$mismatch} mismatches\n";

// Smoke-test show() end-to-end on a few songs (publish only — abortIfDraft).
$controller = $app->make(App\Http\Controllers\Library\SongLibraryController::class);
$search = $app->make(App\Services\ChordVoicingSearch::class);
$ran = 0; $errors = 0;

foreach (Leadsheet::where('status', 'publish')->limit(5)->get() as $ls) {
    try {
        $req = Illuminate\Http\Request::create("/library/songs/{$ls->slug}", 'GET');
        app()->instance('request', $req);
        $resp = $controller->show($req, $ls, $search);
        // Inertia\Response exposes its props via reflection (no public getter).
        $ref = new ReflectionClass($resp);
        $propProp = $ref->getProperty('props'); $propProp->setAccessible(true);
        $props = $propProp->getValue($resp);
        $vcount = count($props['versions'] ?? []);
        echo "  show({$ls->slug}): ok, versions={$vcount}, active={$props['activeVersion']}, chords=" . count($props['chordNames'] ?? []) . "\n";
        $ran++;
    } catch (\Throwable $e) {
        echo "  show({$ls->slug}): ERROR " . $e->getMessage() . "\n";
        $errors++;
    }
}

echo "\nshow() smoke: ran {$ran}, errors {$errors}\n";
echo ($mismatch === 0 && $errors === 0) ? "STAGE 4.1 OK\n" : "STAGE 4.1 ISSUES\n";
