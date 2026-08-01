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
        Schema::create('document_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_user_id')->nullable()->constrained('users');
            $table->foreignId('to_user_id')->constrained('users');
            $table->foreignId('to_org_unit_id')->constrained('org_units');
            $table->string('action');                // forwarded | forwarded_to_focal | returned | rejected
            $table->text('remarks')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->index(['document_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_routes');
    }
};
