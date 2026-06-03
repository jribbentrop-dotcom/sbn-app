<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sbn_products') && ! Schema::hasColumn('sbn_products', 'tax_code')) {
            Schema::table('sbn_products', function (Blueprint $table) {
                $table->string('tax_code')->nullable()->after('payment_ref');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sbn_products') && Schema::hasColumn('sbn_products', 'tax_code')) {
            Schema::table('sbn_products', function (Blueprint $table) {
                $table->dropColumn('tax_code');
            });
        }
    }
};
