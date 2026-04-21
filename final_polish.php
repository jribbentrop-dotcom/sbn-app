<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Product;
use Illuminate\Support\Facades\DB;

$products = Product::all();

echo "FINAL DATA POLISH...\n";

DB::beginTransaction();

try {
    foreach ($products as $product) {
        foreach (['description', 'excerpt'] as $field) {
            if ($product->$field) {
                // Remove raw &nbsp; characters (which are often \xc2\xa0)
                $val = str_replace("\xc2\xa0", ' ', $product->$field);
                
                // Decode HTML entities
                $val = html_entity_decode($val, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                
                // Trim multiple spaces
                $val = preg_replace('/ +/', ' ', $val);
                
                $product->$field = trim($val);
            }
        }
        $product->save();
    }

    DB::commit();
    echo "POLISH COMPLETE.\n";
} catch (\Exception $e) {
    DB::rollBack();
    echo "ERROR: " . $e->getMessage() . "\n";
}
