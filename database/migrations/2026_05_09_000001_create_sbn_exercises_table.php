<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sbn_exercises', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->string('key_center', 4)->default('C');
            $table->string('time_sig', 8)->default('4/4');
            $table->unsignedSmallInteger('bpm_default')->default(100);
            $table->string('type', 32)->default('tab_exercise');
            $table->longText('content_json');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sbn_exercises');
    }
};
