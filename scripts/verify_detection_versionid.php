<?php
// Stage 4.3: re-run detection on a few songs and confirm version_id is written,
// occurrence/usage counts are stable, and version_id is never null after a detect.

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Leadsheet;
use Illuminate\Support\Facades\DB;

$detector = $app->make(App\Services\ProgressionDetector::class);
$crossref = $app->make(App\Services\VoicingCrossref::class);

// Snapshot popularity (the DISTINCT-leadsheet aggregate must be unaffected by re-detect
// of a single song since its leadsheet_id set is unchanged).
$popBefore = DB::table('sbn_chord_diagrams')->orderBy('id')->pluck('popularity', 'id')->all();

$songs = Leadsheet::with('defaultVersion')
    ->whereNotNull('shortcode_content')->where('shortcode_content', '!=', '')
    ->limit(3)->get();

foreach ($songs as $ls) {
    $v = $ls->defaultVersion;

    $occBefore = DB::table('sbn_progression_occurrences')->where('version_id', $v->id)->count();
    $usgBefore = DB::table('sbn_voicing_usage')->where('version_id', $v->id)->count();

    $pr = $detector->processLeadsheet($ls);
    $vc = $crossref->processLeadsheet($ls);

    $occAfter   = DB::table('sbn_progression_occurrences')->where('version_id', $v->id)->count();
    $occNull    = DB::table('sbn_progression_occurrences')->where('leadsheet_id', $ls->id)->whereNull('version_id')->count();
    $usgAfter   = DB::table('sbn_voicing_usage')->where('version_id', $v->id)->count();
    $usgNull    = DB::table('sbn_voicing_usage')->where('leadsheet_id', $ls->id)->whereNull('version_id')->count();

    echo "{$ls->slug} (v={$v->id}):\n";
    echo "  occurrences before={$occBefore} after={$occAfter} (detector said {$pr['occurrences']}) null_version={$occNull}\n";
    echo "  usage       before={$usgBefore} after={$usgAfter} (matched {$vc['matched']}) null_version={$usgNull}\n";
}

$crossref->recalculatePopularity();
$popAfter = DB::table('sbn_chord_diagrams')->orderBy('id')->pluck('popularity', 'id')->all();

$popDiff = 0;
foreach ($popBefore as $id => $p) {
    if (($popAfter[$id] ?? null) != $p) { $popDiff++; }
}
echo "\npopularity rows changed after re-detect + recalc: {$popDiff} (expect 0)\n";
echo $popDiff === 0 ? "STAGE 4.3 popularity STABLE\n" : "STAGE 4.3 popularity DRIFTED — investigate\n";
