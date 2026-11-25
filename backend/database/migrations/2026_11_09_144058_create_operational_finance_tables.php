<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_controls', function (Blueprint $t) {
            $t->foreignUuid('pharmacy_id')->primary()->constrained()->restrictOnDelete();
            $t->timestampTz('enabled_at');
        });
        Schema::create('cash_shifts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('pharmacy_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->uuid('request_key');
            $t->string('number', 40);
            $t->string('status', 10)->default('OPEN');
            $t->unsignedInteger('revision')->default(1);
            $t->decimal('opening_cash', 18, 2);
            $t->decimal('expected_cash', 18, 2)->nullable();
            $t->decimal('counted_cash', 18, 2)->nullable();
            $t->decimal('difference', 18, 2)->nullable();
            $t->text('closing_reason')->nullable();
            $t->timestampTz('opened_at');
            $t->timestampTz('closed_at')->nullable();
            $t->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->unique(['pharmacy_id', 'request_key']);
            $t->unique(['pharmacy_id', 'number']);
            $t->index(['pharmacy_id', 'user_id', 'status']);
        });
        Schema::create('cash_entries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('pharmacy_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('cash_shift_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('source_type', 25);
            $t->uuid('source_id');
            $t->string('number', 60);
            $t->string('method', 15);
            $t->decimal('amount', 18, 2);
            $t->date('occurred_on');
            $t->timestampTz('created_at');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->unique(['pharmacy_id', 'source_type', 'source_id']);
            $t->index(['pharmacy_id', 'occurred_on']);
        });
        Schema::create('service_tariffs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('pharmacy_id')->constrained()->restrictOnDelete();
            $t->string('name', 150);
            $t->decimal('price', 14, 2);
            $t->boolean('active')->default(true);
            $t->timestampsTz();
            $t->unique(['pharmacy_id', 'name']);
        });
        Schema::create('service_bills', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('pharmacy_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('visit_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('sale_id')->nullable()->constrained()->restrictOnDelete();
            $t->uuid('open_visit_key')->nullable();
            $t->uuid('open_sale_key')->nullable();
            $t->uuid('request_key');
            $t->string('number', 40);
            $t->string('status', 15)->default('DRAFT');
            $t->unsignedInteger('revision')->default(1);
            $t->decimal('services_total', 18, 2);
            $t->decimal('medicine_total', 18, 2)->default(0);
            $t->decimal('total', 18, 2);
            $t->string('payment_method', 15)->nullable();
            $t->string('payment_reference', 100)->nullable();
            $t->decimal('amount_received', 18, 2)->nullable();
            $t->decimal('change_amount', 18, 2)->nullable();
            $t->timestampTz('paid_at')->nullable();
            $t->date('paid_on')->nullable();
            $t->foreignId('paid_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->text('cancellation_reason')->nullable();
            $t->timestampsTz();
            $t->unique(['pharmacy_id', 'request_key']);
            $t->unique(['pharmacy_id', 'open_visit_key']);
            $t->unique(['pharmacy_id', 'open_sale_key']);
            $t->unique(['pharmacy_id', 'number']);
        });
        Schema::create('service_bill_items', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('service_bill_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('service_tariff_id')->constrained()->restrictOnDelete();
            $t->string('name', 150);
            $t->unsignedInteger('quantity');
            $t->decimal('unit_price', 14, 2);
            $t->decimal('total', 18, 2);
        });
        Schema::create('expense_categories', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('pharmacy_id')->constrained()->restrictOnDelete();
            $t->string('name', 150);
            $t->boolean('active')->default(true);
            $t->timestampsTz();
            $t->unique(['pharmacy_id', 'name']);
        });
        Schema::create('operating_expenses', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('pharmacy_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('expense_category_id')->constrained()->restrictOnDelete();
            $t->uuid('request_key');
            $t->string('number', 40);
            $t->string('category_name', 150);
            $t->text('description');
            $t->date('spent_on');
            $t->decimal('amount', 18, 2);
            $t->string('method', 15);
            $t->string('reference', 100)->nullable();
            $t->string('status', 15)->default('PENDING');
            $t->unsignedInteger('revision')->default(1);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('approved_at')->nullable();
            $t->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('posted_at')->nullable();
            $t->text('cancellation_reason')->nullable();
            $t->timestampsTz();
            $t->unique(['pharmacy_id', 'request_key']);
            $t->unique(['pharmacy_id', 'number']);
        });
        Schema::table('supplier_invoices', fn (Blueprint $t) => $t->decimal('credit_amount', 18, 2)->default(0));
        Schema::create('supplier_returns', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('pharmacy_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('purchase_receipt_id')->constrained()->restrictOnDelete();
            $t->uuid('request_key');
            $t->string('number', 40);
            $t->string('status', 15)->default('PENDING');
            $t->unsignedInteger('revision')->default(1);
            $t->text('reason');
            $t->decimal('total', 18, 2);
            $t->decimal('credit_amount', 18, 2)->default(0);
            $t->decimal('refund_due', 18, 2)->default(0);
            $t->timestampTz('recovered_at')->nullable();
            $t->foreignId('recovered_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('recovery_method', 15)->nullable();
            $t->string('recovery_reference', 100)->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('posted_at')->nullable();
            $t->text('cancellation_reason')->nullable();
            $t->timestampsTz();
            $t->unique(['pharmacy_id', 'request_key']);
            $t->unique(['pharmacy_id', 'number']);
        });
        Schema::create('supplier_return_items', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('supplier_return_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('purchase_receipt_item_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('source_movement_id')->constrained('stock_movements')->restrictOnDelete();
            $t->string('product_name', 200);
            $t->string('unit', 30);
            $t->decimal('quantity', 12, 3);
            $t->decimal('base_quantity', 20, 4);
            $t->decimal('total', 18, 2);
            $t->unique(['supplier_return_id', 'purchase_receipt_item_id'], 'supplier_return_line_once');
        });
    }

    public function down(): void
    {
        foreach (['cash_entries', 'cash_shifts', 'service_bills', 'operating_expenses', 'supplier_returns'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Rollback diblokir karena transaksi operasional sudah dicatat.');
            }
        }
        foreach (['supplier_return_items', 'supplier_returns', 'operating_expenses', 'expense_categories', 'service_bill_items', 'service_bills', 'service_tariffs', 'cash_entries', 'cash_shifts', 'cash_controls'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('supplier_invoices', fn (Blueprint $t) => $t->dropColumn('credit_amount'));
    }
};
