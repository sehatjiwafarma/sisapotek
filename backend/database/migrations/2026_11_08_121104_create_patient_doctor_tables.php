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
        Schema::create('patients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pharmacy_id')->constrained()->restrictOnDelete();
            $table->uuid('request_key');
            $table->string('medical_record_number', 30);
            $table->string('name', 150);
            $table->date('birth_date');
            $table->string('sex', 15);
            $table->string('phone', 30)->nullable();
            $table->text('address')->nullable();
            $table->string('emergency_name', 150)->nullable();
            $table->string('emergency_phone', 30)->nullable();
            $table->string('emergency_relationship', 80)->nullable();
            $table->text('nik_encrypted')->nullable();
            $table->char('nik_hash', 64)->nullable();
            $table->text('allergies_encrypted')->nullable();
            $table->text('notes_encrypted')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('revision')->default(1);
            $table->timestampsTz();
            $table->unique(['pharmacy_id', 'medical_record_number']);
            $table->unique(['pharmacy_id', 'nik_hash']);
            $table->unique(['pharmacy_id', 'request_key']);
            $table->index(['pharmacy_id', 'active', 'name']);
        });
        Schema::create('doctors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pharmacy_id')->constrained()->restrictOnDelete();
            $table->uuid('request_key');
            $table->string('code', 30);
            $table->string('name', 150);
            $table->string('sip_number', 80)->nullable();
            $table->string('str_number', 80)->nullable();
            $table->string('specialty', 100)->nullable();
            $table->string('phone', 30)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedInteger('signing_revision')->default(1);
            $table->timestampsTz();
            $table->unique(['pharmacy_id', 'code']);
            $table->unique(['pharmacy_id', 'user_id']);
            $table->unique(['pharmacy_id', 'request_key']);
            $table->index(['pharmacy_id', 'active', 'name']);
        });
        Schema::create('doctor_signatures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('doctor_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->unsignedInteger('signing_revision');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('path');
            $table->string('mime', 30);
            $table->char('sha256', 64);
            $table->date('valid_from');
            $table->timestampTz('created_at');
            $table->unique(['doctor_id', 'version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doctor_signatures');
        Schema::dropIfExists('doctors');
        Schema::dropIfExists('patients');
    }
};
