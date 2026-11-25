<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pharmacy_id')->constrained()->restrictOnDelete();
            $table->string('sku', 50);
            $table->string('barcode', 100)->nullable();
            $table->string('name', 200);
            $table->string('brand', 150);
            $table->string('generic_name', 200)->nullable();
            $table->string('category', 50);
            $table->string('dosage_form', 50)->nullable();
            $table->string('strength', 100)->nullable();
            $table->string('base_unit', 30);
            $table->string('purchase_unit', 30);
            $table->decimal('purchase_conversion', 14, 4)->default(1);
            $table->decimal('purchase_price', 16, 2)->nullable();
            $table->decimal('selling_price', 16, 2);
            $table->decimal('minimum_stock', 14, 4)->default(0);
            $table->decimal('maximum_stock', 14, 4)->nullable();
            $table->boolean('requires_prescription')->default(false);
            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->unique(['pharmacy_id', 'sku']);
            $table->unique(['pharmacy_id', 'barcode']);
            $table->index(['pharmacy_id', 'active', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
