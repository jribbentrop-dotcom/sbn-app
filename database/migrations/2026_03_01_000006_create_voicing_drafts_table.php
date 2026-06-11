<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sbn_voicing_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leadsheet_id')->constrained('sbn_leadsheets')->cascadeOnDelete();
            $table->text('leadsheet_title')->default('');
            $table->text('chord_name');
            $table->text('fret_string');
            $table->integer('position')->default(1);
            $table->text('fingers')->default('');
            $table->text('root_note')->default('');
            $table->text('quality')->default('');
            $table->text('status')->default('pending');
            $table->text('notes')->default('');
            $table->text('bass_note')->default('');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sbn_voicing_drafts');
    }
};
