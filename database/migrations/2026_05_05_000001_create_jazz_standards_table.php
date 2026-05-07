<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jazz Standards DB — separate reference table.
 *
 * Decisions (2026-05-05):
 *   1. SEPARATE TABLE — standards are read-only reference data, not editable content.
 *   2. NOT YET exposed in the public frontend — admin-only reference for now.
 *   3. Progression analysis runs ON DEMAND via admin action, not at import time.
 *   4. Re-seeding strategy DEFERRED — truncate+re-import when Oliphant updates.
 *
 * Source: github.com/mikeoliphant/JazzStandards (iReal Pro data, ~1 382 entries).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sbn_jazz_standards', function (Blueprint $table) {
            $table->id();

            // ── Core metadata ─────────────────────────────────────────────────
            $table->string('title');
            $table->string('composer')->nullable();
            $table->string('song_key', 20)->nullable();       // e.g. "Dmin", "Bb", "F"
            $table->string('rhythm', 80)->nullable();         // e.g. "Medium Swing", "Bossa Nova"
            $table->string('time_signature', 10)->default('4/4');

            // ── Structure ─────────────────────────────────────────────────────
            $table->unsignedSmallInteger('bar_count')->nullable();
            $table->string('form', 20)->nullable();           // e.g. "AABA", "ABAC", "AABBA"

            // ── Raw / parsed content ──────────────────────────────────────────
            /** Full sections array from Oliphant JSON, stored as-is for inspection. */
            $table->json('sections_json');

            /** Flat chord string per section (pipe-separated bars), for fast text search. */
            $table->text('chord_string')->nullable();

            // ── Source metadata ───────────────────────────────────────────────
            $table->string('source', 40)->default('oliphant'); // future-proof for other sources
            $table->string('slug')->unique();                 // url-safe title slug

            $table->timestamp('created_at')->useCurrent();

            // ── Indexes ───────────────────────────────────────────────────────
            $table->index('title');
            $table->index('composer');
            $table->index('song_key');
            $table->index('rhythm');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sbn_jazz_standards');
    }
};
