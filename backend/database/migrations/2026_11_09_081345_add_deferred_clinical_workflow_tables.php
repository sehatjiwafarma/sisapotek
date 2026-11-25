<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', fn (Blueprint $table) => $table->text('completion_note_encrypted')->nullable());
        Schema::create('examination_images', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pharmacy_id')->constrained();
            $table->foreignUuid('visit_id')->constrained();
            $table->string('path')->unique();
            $table->string('mime', 30);
            $table->string('sha256', 64);
            $table->unsignedInteger('bytes');
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestampTz('created_at');
            $table->index(['pharmacy_id', 'visit_id']);
        });
        Schema::create('examination_version_images', function (Blueprint $table) {
            $table->foreignUuid('version_id')->constrained('examination_versions');
            $table->foreignUuid('image_id')->constrained('examination_images');
            $table->primary(['version_id', 'image_id']);
        });
        Schema::create('prescriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pharmacy_id')->constrained();
            $table->foreignUuid('visit_id')->unique()->constrained();
            $table->string('number', 40);
            $table->string('status', 10)->default('DRAFT');
            $table->unsignedInteger('revision')->default(1);
            $table->text('payload_encrypted');
            $table->foreignUuid('signature_id')->nullable()->constrained('doctor_signatures');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->constrained('users');
            $table->foreignId('finalized_by')->nullable()->constrained('users');
            $table->timestampTz('finalized_at')->nullable();
            $table->timestampsTz();
            $table->unique(['pharmacy_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prescriptions');
        Schema::dropIfExists('examination_version_images');
        Schema::dropIfExists('examination_images');
        Schema::table('visits', fn (Blueprint $table) => $table->dropColumn('completion_note_encrypted'));
    }
};
