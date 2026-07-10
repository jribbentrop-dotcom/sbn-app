<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Provenance on skill-node completions.
     *
     * Before this, a completion was just `status='completed'` — there was no way
     * to tell a student's self-reported tick from a node genuinely earned by
     * passing a quiz. Recording it is cheap now and impossible to reconstruct
     * later, so it happens even though `source` has only one value on day one.
     *
     * Existing rows are GRANDFATHERED as 'self_report'. They stay valid; nobody
     * loses progress when a node later becomes quiz-gated. See
     * docs/SBN-Skill-System-Reference.md and docs/SBN-Quiz-Reference.md.
     */
    public function up(): void
    {
        Schema::table('sbn_user_skill_progress', function (Blueprint $table) {
            $table->string('source')->default('self_report')->after('status'); // self_report|quiz
            $table->foreignId('quiz_attempt_id')->nullable()->after('source')
                ->constrained('sbn_quiz_attempts')->nullOnDelete();
        });

        // Explicit backfill. The column default already covers existing rows,
        // but stating it keeps the grandfathering intentional rather than
        // incidental — and guards against a default being dropped later.
        DB::table('sbn_user_skill_progress')
            ->whereNull('source')
            ->update(['source' => 'self_report']);
    }

    public function down(): void
    {
        Schema::table('sbn_user_skill_progress', function (Blueprint $table) {
            $table->dropConstrainedForeignId('quiz_attempt_id');
            $table->dropColumn('source');
        });
    }
};
