<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leadsheet versions — the ARRANGEMENT layer.
 *
 * Splits the per-arrangement data off sbn_leadsheets (the WORK / catalog identity).
 * One leadsheet has many versions (difficulty / performer variants); each version
 * carries its own chord grid (json_data) plus two notated TAB layers
 * (melody_tab_xml, chord_tab_xml). See docs/SBN-Leadsheet-Versions-Plan.md.
 *
 * Licensing (is_pro / license_status) stays on sbn_leadsheets — never per-version.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sbn_leadsheet_versions')) {
            return;
        }

        Schema::create('sbn_leadsheet_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('leadsheet_id');

            // Identity within the song
            $table->string('version_slug');                 // unique per leadsheet
            $table->text('label')->default('');             // "Basic" / "João Gilberto" / "Wes"
            $table->text('performer')->nullable();          // named arranger/performer
            $table->integer('difficulty')->default(1);      // drives dropdown order
            $table->integer('sort_order')->default(0);

            // Arrangement data (moved off sbn_leadsheets; dual-read during transition)
            $table->text('song_key')->nullable();           // versions may transpose
            $table->text('rhythm')->nullable();
            $table->integer('tempo')->nullable();
            $table->integer('measure_count')->default(0);
            $table->text('json_data')->nullable();          // chord grid + chordVoicings
            $table->text('melody_tab_xml')->nullable();     // notated melody (old tab_xml)
            $table->text('chord_tab_xml')->nullable();      // notated comping/rhythm TAB (NEW)
            $table->text('shortcode_content')->nullable();  // legacy voicings block

            $table->string('status')->default('draft');     // draft a version while another is live
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();

            $table->index('leadsheet_id');
            $table->unique(['leadsheet_id', 'version_slug'], 'leadsheet_version_slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sbn_leadsheet_versions');
    }
};
