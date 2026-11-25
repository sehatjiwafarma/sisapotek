<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctor_signatures', function (Blueprint $table) {
            $table->boolean('uploaded_as_owner')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('doctor_signatures', fn (Blueprint $table) => $table->dropColumn('uploaded_as_owner'));
    }
};
