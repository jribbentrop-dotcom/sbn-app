<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sbn_leadsheets', function (Blueprint $table) {
            $table->id();
            $table->text('title');
            $table->text('composer')->default('');
            $table->text('song_key')->default('C');
            $table->integer('tempo')->default(120);
            $table->text('time_signature')->default('4/4');
            $table->text('rhythm')->default('');
            $table->integer('measure_count')->default(0);
            $table->text('description')->nullable();
            $table->text('difficulty')->nullable();
            $table->text('popularity');
            $table->text('genre')->nullable();
            $table->foreignId('course_id')->nullable()->constrained('sbn_courses')->nullOnDelete();
            $table->text('shortcode_content')->nullable();
            $table->text('json_data')->nullable();
            $table->text('harmony_notes')->nullable();
            $table->text('form_notes')->nullable();
            $table->text('voicing_notes')->nullable();
            $table->text('tab_xml')->nullable();
            $table->string('slug')->unique();
            $table->string('cover_image_path')->nullable();
            $table->string('status')->default('draft');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sbn_leadsheets');
    }
};
