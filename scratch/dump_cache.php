<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$rows = DB::table('sbn_lookup_cache')->get();
foreach ($rows as $row) {
    echo "Title: {$row->title} | Mode: " . ($row->mode ?? 'MISSING') . "\n";
}
