<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('purchase_receipts', fn (Blueprint $table) => $table->string('ppn_scope', 12)->default('total')); }
    public function down(): void { Schema::table('purchase_receipts', fn (Blueprint $table) => $table->dropColumn('ppn_scope')); }
};
