<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Product Categories (hierarchical)
        Schema::create('sbn_product_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->foreignId('parent_id')->nullable()->constrained('sbn_product_categories')->onDelete('set null');
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('parent_id');
        });

        // Product Tags
        Schema::create('sbn_product_tags', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->timestamps();
        });

        // Products
        Schema::create('sbn_products', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->text('excerpt')->nullable();
            $table->longText('description')->nullable();
            $table->integer('price_cents'); // EUR, base currency
            $table->string('thumbnail_path')->nullable();
            $table->string('pdf_path')->nullable(); // local storage after download
            $table->string('pdf_filename')->nullable(); // download name, e.g. "BOSSANOVA - Estate.pdf"
            $table->string('pdf_original_url')->nullable(); // WP URL for audit
            $table->json('attributes')->nullable(); // { notation: ["chord-grids","tablature"], pages: "4" }
            $table->string('meta_description')->nullable(); // SEO
            $table->integer('wp_post_id')->nullable(); // audit
            $table->timestamp('published_at')->nullable();
            $table->enum('status', ['published', 'draft'])->default('draft');
            $table->timestamps();

            $table->index('slug');
            $table->index('status');
            $table->index('published_at');
            $table->index('wp_post_id');
        });

        // Product-Category Pivot
        Schema::create('sbn_product_category', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained('sbn_products')->onDelete('cascade');
            $table->foreignId('category_id')->constrained('sbn_product_categories')->onDelete('cascade');
            $table->primary(['product_id', 'category_id']);
        });

        // Product-Tag Pivot
        Schema::create('sbn_product_tag', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained('sbn_products')->onDelete('cascade');
            $table->foreignId('tag_id')->constrained('sbn_product_tags')->onDelete('cascade');
            $table->primary(['product_id', 'tag_id']);
        });

        // Orders
        Schema::create('sbn_orders', function (Blueprint $table) {
            $table->id();
            $table->string('guest_email');
            $table->integer('total_cents'); // EUR
            $table->enum('display_currency', ['EUR', 'USD'])->default('EUR');
            $table->enum('status', ['pending_stub', 'paid', 'failed'])->default('pending_stub');
            $table->string('stripe_payment_intent_id')->nullable(); // Phase 10
            $table->string('token')->unique(); // For order success page access
            $table->timestamps();

            $table->index('token');
            $table->index('guest_email');
            $table->index('status');
        });

        // Order Items
        Schema::create('sbn_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('sbn_orders')->onDelete('cascade');
            $table->foreignId('product_id')->constrained('sbn_products')->onDelete('restrict');
            $table->string('title_snapshot');
            $table->integer('price_cents_snapshot');
            $table->integer('quantity')->default(1);
            $table->timestamps();

            $table->index('order_id');
        });

        // Download Grants
        Schema::create('sbn_download_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('sbn_orders')->onDelete('cascade');
            $table->foreignId('product_id')->constrained('sbn_products')->onDelete('restrict');
            $table->string('token')->unique(); // ULID or random 32-char
            $table->string('guest_email');
            $table->integer('downloads_used')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index('token');
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sbn_download_grants');
        Schema::dropIfExists('sbn_order_items');
        Schema::dropIfExists('sbn_orders');
        Schema::dropIfExists('sbn_product_tag');
        Schema::dropIfExists('sbn_product_category');
        Schema::dropIfExists('sbn_products');
        Schema::dropIfExists('sbn_product_tags');
        Schema::dropIfExists('sbn_product_categories');
    }
};
