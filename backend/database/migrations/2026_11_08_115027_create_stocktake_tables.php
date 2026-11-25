<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('stocktakes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pharmacy_id')->constrained()->restrictOnDelete();
            $table->uuid('request_key');
            $table->string('number', 40);
            $table->string('scope', 200);
            $table->string('status', 15)->default('COUNTING');
            $table->unsignedInteger('revision')->default(1);
            $table->text('notes')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->timestampsTz();
            $table->unique(['pharmacy_id', 'request_key']);
            $table->unique(['pharmacy_id', 'number']);
        });
        Schema::create('stocktake_products', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('stocktake_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('movement_count');
            $table->unique(['stocktake_id', 'product_id']);
        });
        Schema::create('stocktake_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('stocktake_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->string('product_name', 200);
            $table->string('sku', 50);
            $table->string('brand', 150);
            $table->string('base_unit', 30);
            $table->string('batch_number', 80)->nullable();
            $table->date('expires_on')->nullable();
            $table->string('location', 100)->nullable();
            $table->decimal('system_quantity', 20, 4);
            $table->decimal('physical_quantity', 20, 4)->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('counted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('counted_at')->nullable();
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignUuid('source_movement_id')->nullable()->constrained('stock_movements')->restrictOnDelete();
            $table->foreignUuid('stocktake_line_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('adjustment_key', 80)->nullable()->unique();
            $table->unique(['sale_item_id', 'source_movement_id'], 'sale_source_once');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('stock_movements')->whereNotNull('stocktake_line_id')->exists()) {
            throw new RuntimeException('Rollback opname diblokir karena sudah ada koreksi stok.');
        }
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropUnique('sale_source_once');
            $table->dropConstrainedForeignId('source_movement_id');
            $table->dropConstrainedForeignId('stocktake_line_id');
            $table->dropColumn('adjustment_key');
        });
        Schema::dropIfExists('stocktake_lines');
        Schema::dropIfExists('stocktake_products');
        Schema::dropIfExists('stocktakes');
    }
};
