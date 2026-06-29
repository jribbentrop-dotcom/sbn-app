<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sbn_pdf_documents', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('template_key');
            $table->string('title')->nullable();
            $table->json('content');
            $table->json('pages')->nullable();
            $table->string('status')->default('draft');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sbn_pdf_documents');
    }
};
