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
        Schema::table('sbn_exercises', function (Blueprint $table) {
            $table->string('composer')->nullable()->after('title');
            $table->string('rhythm')->nullable()->after('time_sig');
            $table->unsignedInteger('measure_count')->default(0)->after('rhythm');
            $table->foreignId('course_id')->nullable()->constrained('sbn_courses')->nullOnDelete()->after('measure_count');
            $table->longText('shortcode_content')->nullable()->after('content_json');
            $table->longText('tab_xml')->nullable()->after('shortcode_content');
            $table->text('description')->nullable()->after('tab_xml');
            $table->text('harmony_notes')->nullable()->after('description');
            $table->text('form_notes')->nullable()->after('harmony_notes');
            $table->text('voicing_notes')->nullable()->after('form_notes');
            $table->unsignedInteger('popularity')->default(0)->after('voicing_notes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sbn_exercises', function (Blueprint $table) {
            $table->dropForeign(['course_id']);
            $table->dropColumn([
                'composer', 'rhythm', 'measure_count', 'course_id', 
                'shortcode_content', 'tab_xml', 'description', 
                'harmony_notes', 'form_notes', 'voicing_notes', 'popularity'
            ]);
        });
    }
};
