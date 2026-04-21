<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Product;
use Illuminate\Support\Facades\DB;

$products = Product::all();

echo "CONVERTING HTML TO MARKDOWN...\n";

DB::beginTransaction();

try {
    foreach ($products as $product) {
        foreach (['description', 'excerpt'] as $field) {
            if ($product->$field) {
                $content = $product->$field;
                
                // Convert <strong> and <b> to **
                $content = preg_replace('/<(strong|b)>(.*?)<\/\1>/i', '**$2**', $content);
                
                // Convert <em> and <i> to *
                $content = preg_replace('/<(em|i)>(.*?)<\/\1>/i', '*$2*', $content);
                
                // Strip all other tags EXCEPT iframes (which are used for videos in descriptions)
                if ($field === 'excerpt') {
                    $content = strip_tags($content);
                } else {
                    // For description, we keep iframes but strip other junk
                    $content = strip_tags($content, '<iframe><br><p>');
                }

                $product->$field = trim($content);
            }
        }
        $product->save();
    }

    DB::commit();
    echo "CONVERSION COMPLETE.\n";
} catch (\Exception $e) {
    DB::rollBack();
    echo "ERROR: " . $e->getMessage() . "\n";
}
