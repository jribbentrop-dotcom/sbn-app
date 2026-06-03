<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * sbn_orders.status was an enum (['pending_stub','paid','failed']) which SQLite
 * enforces as a CHECK constraint. Phase 12b adds 'pending_payment' and
 * 'refunded', so relax the column to a plain string. Laravel 11+ rebuilds the
 * SQLite table natively (no doctrine/dbal needed).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('sbn_orders', 'status')) {
            Schema::table('sbn_orders', function (Blueprint $table) {
                $table->string('status')->default('pending_stub')->change();
            });
        }
    }

    public function down(): void
    {
        // Intentionally not restoring the enum CHECK — leaving status as string
        // is forward-safe and reverting could orphan new status values.
    }
};
