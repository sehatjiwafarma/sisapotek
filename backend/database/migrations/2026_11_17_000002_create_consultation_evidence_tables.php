<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultation_evidence_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pharmacy_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('visit_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->text('payload_encrypted');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');
            $table->unique(['visit_id', 'version']);
        });
        Schema::create('consultation_evidence_images', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pharmacy_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('visit_id')->constrained()->restrictOnDelete();
            $table->string('path');
            $table->string('mime', 30);
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultation_evidence_images');
        Schema::dropIfExists('consultation_evidence_versions');
    }
};
