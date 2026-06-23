<?php
// Stage 6 admin editor: confirm edit() serves the selected version's data, and
// that the version list / counts are correct for the merged songs.

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Leadsheet;

$controller = $app->make(App\Http\Controllers\Admin\LeadsheetController::class);

function editData($controller, $app, $slug, $vslug = null) {
    $ls = Leadsheet::where('slug', $slug)->firstOrFail();
    $uri = "/admin/leadsheets/{$ls->id}/edit" . ($vslug ? "?v={$vslug}" : '');
    $req = Illuminate\Http\Request::create($uri, 'GET');
    $app->instance('request', $req);
    $view = $controller->edit($req, $ls);
    $d = $view->getData();
    return [$d['leadsheet'], $d['activeVersion'] ?? null, $d['versionList'] ?? collect()];
}

// Body and Soul: default vs each explicit version
foreach ([
    ['body-and-soul', null],
    ['body-and-soul', 'joe-pass'],
    ['body-and-soul', 'billie-holiday'],
    ['the-girl-from-ipanema', 'in-f'],
] as [$slug, $v]) {
    [$ls, $active, $list] = editData($controller, $app, $slug, $v);
    $jdLen = strlen((string) $ls->json_data);
    echo sprintf(
        "%s  v=%-15s -> active=%-15s key=%-3s json=%-7d versions=%d\n",
        $slug, $v ?? '(default)', $active?->version_slug ?? '?', $ls->song_key, $jdLen, $list->count()
    );
}

// Confirm the two versions actually serve DIFFERENT json_data (not the same blob)
[$lsJP, , ] = editData($controller, $app, 'body-and-soul', 'joe-pass');
[$lsBH, , ] = editData($controller, $app, 'body-and-soul', 'billie-holiday');
$different = $lsJP->json_data !== $lsBH->json_data;
echo "\nBody&Soul joe-pass vs billie-holiday json_data differ: " . ($different ? "YES ✓" : "NO ✗ (same blob — bug)") . "\n";
echo $different ? "STAGE 6 ADMIN OK\n" : "STAGE 6 ADMIN ISSUE\n";
