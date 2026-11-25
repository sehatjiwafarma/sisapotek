<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('prescription_follow_ups', function (Blueprint $table) {
            $table->uuid('prescription_id')->primary();
            $table->foreign('prescription_id')->references('id')->on('prescriptions')->restrictOnDelete();
            $table->foreignUuid('pharmacy_id')->constrained()->restrictOnDelete();
            $table->string('status', 20);
            $table->text('note_encrypted');
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['pharmacy_id', 'status']);
        });
    }
    public function down(): void { Schema::dropIfExists('prescription_follow_ups'); }
};
