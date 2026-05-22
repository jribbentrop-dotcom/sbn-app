<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the `video_snippets` JSON library column to the rhythm-pattern and
     * chord-progression component tables. Each holds an array of authored
     * VideoSnippet objects; the course slot references one by its stable id.
     * See docs/Video-Sync-Snippet-Integration-Plan.md §0.2.
     */
    public function up(): void
    {
        Schema::table('sbn_rhythm_patterns', function (Blueprint $table) {
            $table->json('video_snippets')->nullable()->after('mp3_file');
        });

        Schema::table('sbn_chord_progressions', function (Blueprint $table) {
            $table->json('video_snippets')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sbn_rhythm_patterns', function (Blueprint $table) {
            $table->dropColumn('video_snippets');
        });

        Schema::table('sbn_chord_progressions', function (Blueprint $table) {
            $table->dropColumn('video_snippets');
        });
    }
};
