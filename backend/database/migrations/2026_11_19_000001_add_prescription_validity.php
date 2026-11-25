<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('prescriptions', function (Blueprint $table) { $table->date('valid_until')->nullable()->index(); }); }
    public function down(): void { Schema::table('prescriptions', function (Blueprint $table) { $table->dropColumn('valid_until'); }); }
};
