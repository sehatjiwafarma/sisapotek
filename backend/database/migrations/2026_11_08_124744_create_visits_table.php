<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pharmacy_id')->constrained();
            $table->foreignUuid('patient_id')->constrained();
            $table->foreignUuid('doctor_id')->constrained();
            $table->uuid('request_key');
            $table->string('number', 40);
            $table->date('visit_date');
            $table->timestampTz('registered_at');
            $table->unsignedInteger('queue_number');
            $table->uuid('open_patient_key')->nullable();
            $table->string('type', 15);
            $table->string('status', 20)->default('REGISTERED');
            $table->string('patient_name', 150);
            $table->string('medical_record_number', 30);
            $table->string('doctor_name', 150);
            $table->string('doctor_code', 30);
            $table->text('complaint_encrypted');
            $table->text('cancellation_encrypted')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->unsignedInteger('revision')->default(1);
            $table->timestampsTz();
            $table->unique(['pharmacy_id', 'request_key']);
            $table->unique(['pharmacy_id', 'number']);
            $table->unique(['pharmacy_id', 'visit_date', 'queue_number'], 'visits_daily_queue_unique');
            $table->unique(['pharmacy_id', 'visit_date', 'open_patient_key'], 'visits_open_patient_unique');
            $table->index(['pharmacy_id', 'visit_date', 'status']);
            $table->index(['pharmacy_id', 'patient_id', 'registered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visits');
    }
};
