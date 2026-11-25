<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_returns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pharmacy_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('sale_id')->constrained()->restrictOnDelete();
            $table->uuid('request_key');
            $table->string('number', 40);
            $table->string('status', 15)->default('PENDING');
            $table->unsignedInteger('revision')->default(1);
            $table->text('reason');
            $table->decimal('refund_amount', 18, 2);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('refunded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('refunded_at')->nullable();
            $table->string('refund_method', 15)->nullable();
            $table->string('refund_reference', 100)->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();
            $table->unique(['pharmacy_id', 'request_key']);
            $table->unique(['pharmacy_id', 'number']);
            $table->index(['pharmacy_id', 'status', 'created_at']);
        });
        Schema::create('sale_return_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sale_return_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('sale_item_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('sale_movement_id')->constrained('stock_movements')->restrictOnDelete();
            $table->decimal('quantity', 20, 4);
            $table->string('disposition', 15);
            $table->decimal('refund_amount', 18, 2);
            $table->unique(['sale_return_id', 'sale_movement_id']);
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignUuid('sale_return_item_id')->nullable()->unique()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('sale_returns')->exists()) {
            throw new RuntimeException('Rollback diblokir karena retur sudah tercatat.');
        }
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropUnique(['sale_return_item_id']);
            $table->dropConstrainedForeignId('sale_return_item_id');
        });
        Schema::dropIfExists('sale_return_items');
        Schema::dropIfExists('sale_returns');
    }
};
