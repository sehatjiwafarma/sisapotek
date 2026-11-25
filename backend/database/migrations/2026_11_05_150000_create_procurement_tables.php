<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('pharmacy_id')->constrained()->restrictOnDelete();
            $t->string('code', 30);
            $t->string('name', 150);
            $t->string('contact_name', 100)->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('email', 254)->nullable();
            $t->text('address')->nullable();
            $t->string('tax_number', 30)->nullable();
            $t->unsignedInteger('payment_days')->default(0);
            $t->boolean('active')->default(true);
            $t->timestampsTz();
            $t->unique(['pharmacy_id', 'code']);
        });
        Schema::create('purchase_orders', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('pharmacy_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('supplier_id')->constrained()->restrictOnDelete();
            $t->string('number', 40);
            $t->string('supplier_name', 150);
            $t->date('ordered_on');
            $t->date('expected_on')->nullable();
            $t->string('payment_type', 10);
            $t->unsignedInteger('payment_days')->default(0);
            $t->string('status', 15)->default('DRAFT');
            $t->decimal('total', 18, 2)->default(0);
            $t->text('notes')->nullable();
            $t->text('cancellation_reason')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('finalized_at')->nullable();
            $t->timestampsTz();
            $t->unique(['pharmacy_id', 'number']);
        });
        Schema::create('purchase_order_items', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('purchase_order_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $t->string('product_name', 200);
            $t->string('brand', 150);
            $t->string('sku', 50);
            $t->string('unit', 30);
            $t->string('base_unit', 30);
            $t->string('unit_type', 10);
            $t->decimal('conversion', 14, 4);
            $t->decimal('quantity', 12, 3);
            $t->decimal('received_quantity', 12, 3)->default(0);
            $t->decimal('unit_price', 14, 2);
            $t->decimal('discount', 16, 2)->default(0);
            $t->decimal('tax', 16, 2)->default(0);
            $t->decimal('total', 18, 2);
        });
        Schema::create('purchase_receipts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('pharmacy_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('purchase_order_id')->constrained()->restrictOnDelete();
            $t->string('number', 40);
            $t->date('received_on');
            $t->string('invoice_number', 80)->nullable();
            $t->text('notes')->nullable();
            $t->string('status', 12)->default('DRAFT');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('posted_at')->nullable();
            $t->timestampsTz();
            $t->unique(['pharmacy_id', 'number']);
        });
        Schema::create('purchase_receipt_items', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('purchase_receipt_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('purchase_order_item_id')->constrained()->restrictOnDelete();
            $t->decimal('quantity', 12, 3);
            $t->string('batch_number', 80)->nullable();
            $t->date('expires_on')->nullable();
            $t->decimal('unit_price', 14, 2);
            $t->string('location', 100)->nullable();
            $t->unique(['purchase_receipt_id', 'purchase_order_item_id'], 'receipt_item_once');
        });
        Schema::create('stock_movements', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('pharmacy_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('purchase_receipt_item_id')->unique()->constrained()->restrictOnDelete();
            $t->date('occurred_on');
            $t->string('type', 20);
            $t->decimal('quantity', 20, 4);
            $t->string('base_unit', 30);
            $t->string('batch_number', 80)->nullable();
            $t->date('expires_on')->nullable();
            $t->string('location', 100)->nullable();
            $t->timestampTz('created_at');
            $t->index(['pharmacy_id', 'product_id']);
        });
    }

    public function down(): void
    {
        foreach (['stock_movements', 'purchase_receipt_items', 'purchase_receipts', 'purchase_order_items', 'purchase_orders', 'suppliers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
