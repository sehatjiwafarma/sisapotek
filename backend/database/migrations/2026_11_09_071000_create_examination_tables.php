<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->timestampTz('completed_at')->nullable();
        });
        Schema::create('examinations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pharmacy_id')->constrained();
            $table->foreignUuid('visit_id')->unique()->constrained();
            $table->string('status', 10)->default('DRAFT');
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedInteger('current_version')->default(0);
            $table->text('payload_encrypted');
            $table->timestampTz('examined_at');
            $table->timestampTz('finalized_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->constrained('users');
            $table->timestampsTz();
            $table->index(['pharmacy_id', 'status', 'examined_at']);
        });
        Schema::create('examination_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('examination_id')->constrained();
            $table->unsignedInteger('version');
            $table->string('kind', 10);
            $table->text('payload_encrypted');
            $table->text('allergies_encrypted')->nullable();
            $table->text('reason_encrypted')->nullable();
            $table->string('doctor_name', 150);
            $table->string('doctor_code', 30);
            $table->string('sip_number', 80)->nullable();
            $table->string('str_number', 80)->nullable();
            $table->foreignId('recorded_by')->constrained('users');
            $table->timestampTz('created_at');
            $table->unique(['examination_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('examination_versions');
        Schema::dropIfExists('examinations');
        Schema::table('visits', fn (Blueprint $table) => $table->dropColumn('completed_at'));
    }
};
