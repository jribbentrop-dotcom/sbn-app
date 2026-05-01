<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sbn_lookup_cache', function (Blueprint $t) {
            $t->id();
            $t->string('cache_key', 64)->unique();        // sha256 of normalized opts
            $t->string('title', 255);                      // for inspection / cache busting
            $t->json('analysis');                          // stored IntermediateAnalysis
            $t->timestamp('expires_at')->index();
            $t->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sbn_lookup_cache');
    }
};
