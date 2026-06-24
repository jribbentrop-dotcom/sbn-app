<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add icon and grade columns to sbn_skill_nodes.
     * See docs/SBN-Skill-System-Plan.md "Icon System" and "Grade ↔ Skill Node Bridge".
     *
     * icon_key  — Heroicon name used as placeholder (e.g. "musical-note")
     * icon_path — custom SVG path that takes priority when set (e.g. "images/skills/shell-voicings.svg")
     * grade     — primary grade placement (1–5); nullable until curated
     */
    public function up(): void
    {
        Schema::table('sbn_skill_nodes', function (Blueprint $table) {
            $table->string('icon_key')->nullable()->after('content_tag_slug');
            $table->string('icon_path')->nullable()->after('icon_key');
            $table->unsignedTinyInteger('grade')->nullable()->after('icon_path');

            $table->index('grade');
        });
    }

    public function down(): void
    {
        Schema::table('sbn_skill_nodes', function (Blueprint $table) {
            $table->dropIndex(['grade']);
            $table->dropColumn(['icon_key', 'icon_path', 'grade']);
        });
    }
};
