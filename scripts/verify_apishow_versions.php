<?php
// Verify apiShow (the endpoint the editor JS actually fetches) returns DISTINCT
// json_data per ?v= for both merged songs.

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Leadsheet;

$controller = $app->make(App\Http\Controllers\Admin\LeadsheetController::class);

foreach ([['body-and-soul', ['joe-pass','billie-holiday']], ['the-girl-from-ipanema', ['in-db','in-f']]] as [$song, $vslugs]) {
    echo "--- {$song} ---\n";
    $hashes = [];
    foreach ($vslugs as $v) {
        $ls = Leadsheet::where('slug', $song)->first();
        $req = Illuminate\Http\Request::create("/api/admin/leadsheets/{$ls->id}/data?v={$v}", 'GET');
        $app->instance('request', $req);
        $resp = $controller->apiShow($req, $ls);
        $payload = $resp->getData(true)['leadsheet'];
        $h = substr(md5(json_encode($payload['json_data'])), 0, 10);
        $hashes[$v] = $h;
        echo "  ?v={$v}: song_key={$payload['song_key']} json_md5={$h}\n";
    }
    echo (count(array_unique($hashes)) === count($hashes) ? "  DISTINCT ✓\n" : "  SAME BLOB ✗\n");
}
