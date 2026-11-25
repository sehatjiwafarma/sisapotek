<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pharmacy_settings', fn (Blueprint $table) => $table->unsignedSmallInteger('soap_reminder_hours')->default(24));
    }

    public function down(): void
    {
        Schema::table('pharmacy_settings', fn (Blueprint $table) => $table->dropColumn('soap_reminder_hours'));
    }
};
