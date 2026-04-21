<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Product;
use Illuminate\Support\Facades\DB;

$products = Product::all();

echo "RESTORING HTML AND CLEANING EXCERPTS...\n";

DB::beginTransaction();

try {
    foreach ($products as $product) {
        foreach (['description', 'excerpt'] as $field) {
            if ($product->$field) {
                $content = $product->$field;
                
                // Convert ** back to <strong>
                $content = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $content);
                // Convert * back to <em>
                $content = preg_replace('/\*(.*?)\*/', '<em>$1</em>', $content);
                
                $product->$field = trim($content);
            }
        }
        $product->save();
    }

    DB::commit();
    echo "RESTORE COMPLETE.\n";
} catch (\Exception $e) {
    DB::rollBack();
    echo "ERROR: " . $e->getMessage() . "\n";
}
