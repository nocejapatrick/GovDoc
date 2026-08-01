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
        //
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('original_filename');
            $table->string('storage_path');
            $table->unsignedBigInteger('size_bytes');
            $table->string('status')->default('pending'); // pending | processed | failed
            $table->string('extraction_method')->nullable(); // text | ocr | mixed
            $table->unsignedInteger('page_count')->nullable();
            $table->longText('extracted_text')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
        Schema::dropIfExists('documents');
    }
};
