<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pharmacy_id')->constrained()->restrictOnDelete();
            $table->uuid('request_key');
            $table->string('number', 40);
            $table->string('kind', 20)->default('GENERAL');
            $table->string('status', 15)->default('DRAFT');
            $table->unsignedInteger('revision')->default(1);
            $table->string('customer_name', 150)->nullable();
            $table->text('notes')->nullable();
            $table->decimal('subtotal', 18, 2);
            $table->decimal('discount', 18, 2)->default(0);
            $table->decimal('total', 18, 2);
            $table->string('payment_method', 15)->nullable();
            $table->string('payment_reference', 100)->nullable();
            $table->decimal('amount_received', 18, 2)->nullable();
            $table->decimal('change_amount', 18, 2)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('paid_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('paid_at')->nullable();
            $table->date('sold_on')->nullable();
            $table->string('pharmacy_name', 200)->nullable();
            $table->text('pharmacy_address')->nullable();
            $table->text('receipt_footer')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestampsTz();
            $table->unique(['pharmacy_id', 'request_key']);
            $table->unique(['pharmacy_id', 'number']);
            $table->index(['pharmacy_id', 'status', 'created_at']);
        });
        Schema::create('sale_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sale_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->string('product_name', 200);
            $table->string('brand', 150);
            $table->string('sku', 50);
            $table->string('unit_type', 10);
            $table->string('unit', 30);
            $table->string('base_unit', 30);
            $table->decimal('conversion', 14, 4);
            $table->decimal('quantity', 12, 3);
            $table->decimal('base_quantity', 20, 4);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('total', 18, 2);
            $table->unique(['sale_id', 'product_id']);
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->uuid('purchase_receipt_item_id')->nullable()->change();
            $table->foreignUuid('sale_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('source_receipt_item_id')->nullable()->constrained('purchase_receipt_items')->restrictOnDelete();
            $table->unique(['sale_item_id', 'source_receipt_item_id'], 'sale_lot_once');
        });
    }

    public function down(): void
    {
        if (DB::table('stock_movements')->whereNotNull('sale_item_id')->exists()) {
            throw new RuntimeException('Rollback POS diblokir karena sudah ada mutasi penjualan.');
        }
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropUnique('sale_lot_once');
            $table->dropConstrainedForeignId('sale_item_id');
            $table->dropConstrainedForeignId('source_receipt_item_id');
            $table->uuid('purchase_receipt_item_id')->nullable(false)->change();
        });
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
    }
};
