<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->boolean('is_psychotropic')->default(false));
        Schema::create('psychotropic_movement_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pharmacy_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('movement_id')->unique()->constrained('stock_movements')->restrictOnDelete();
            $table->string('category', 20);
            $table->string('reason', 500);
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('psychotropic_movement_categories');
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('is_psychotropic'));
    }
};
