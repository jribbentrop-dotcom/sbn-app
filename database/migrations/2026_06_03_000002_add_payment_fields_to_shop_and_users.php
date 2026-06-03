<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12b — provider-agnostic payment fields.
 *
 * Column names are provider-neutral (payment_ref / provider_order_id /
 * payment_customer_id) so the concrete MoR (Paddle / Stripe Managed) is a late,
 * reversible decision. New order statuses (pending_payment, refunded) are stored
 * as plain strings — sbn_orders.status is an enum in MySQL but SQLite (the app
 * DB) does not enforce it, so no column rebuild is needed here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sbn_products') && !Schema::hasColumn('sbn_products', 'payment_ref')) {
            Schema::table('sbn_products', function (Blueprint $table) {
                $table->string('payment_ref')->nullable()->after('slug');
            });
        }

        if (Schema::hasTable('sbn_orders')) {
            Schema::table('sbn_orders', function (Blueprint $table) {
                if (!Schema::hasColumn('sbn_orders', 'user_id')) {
                    $table->unsignedBigInteger('user_id')->nullable()->after('id')->index();
                }
                if (!Schema::hasColumn('sbn_orders', 'provider_order_id')) {
                    $table->string('provider_order_id')->nullable()->after('status')->index();
                }
            });
        }

        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'payment_customer_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('payment_customer_id')->nullable()->after('email');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sbn_products', 'payment_ref')) {
            Schema::table('sbn_products', fn (Blueprint $t) => $t->dropColumn('payment_ref'));
        }
        if (Schema::hasColumn('sbn_orders', 'user_id')) {
            Schema::table('sbn_orders', fn (Blueprint $t) => $t->dropColumn('user_id'));
        }
        if (Schema::hasColumn('sbn_orders', 'provider_order_id')) {
            Schema::table('sbn_orders', fn (Blueprint $t) => $t->dropColumn('provider_order_id'));
        }
        if (Schema::hasColumn('users', 'payment_customer_id')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('payment_customer_id'));
        }
    }
};
