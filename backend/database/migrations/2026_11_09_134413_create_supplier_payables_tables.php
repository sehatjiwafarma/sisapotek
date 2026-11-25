<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pharmacy_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('purchase_receipt_id')->unique()->constrained()->restrictOnDelete();
            $table->string('number', 60);
            $table->string('supplier_name', 150);
            $table->string('supplier_invoice_number', 80)->nullable();
            $table->date('received_on');
            $table->unsignedInteger('payment_days');
            $table->date('due_on');
            $table->decimal('amount', 18, 2);
            $table->decimal('opening_paid_amount', 18, 2)->default(0);
            $table->timestampTz('reconciled_at')->nullable();
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('reconciliation_reason')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestampsTz();
            $table->unique(['pharmacy_id', 'number']);
            $table->index(['pharmacy_id', 'due_on']);
        });
        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pharmacy_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('supplier_invoice_id')->constrained()->restrictOnDelete();
            $table->uuid('request_key');
            $table->string('number', 40);
            $table->string('status', 15)->default('PENDING');
            $table->unsignedInteger('revision')->default(1);
            $table->decimal('amount', 18, 2);
            $table->date('paid_on');
            $table->string('method', 15);
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->string('proof_path')->nullable();
            $table->string('proof_mime', 60)->nullable();
            $table->string('proof_sha256', 64)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('posted_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestampsTz();
            $table->unique(['pharmacy_id', 'request_key']);
            $table->unique(['pharmacy_id', 'number']);
            $table->index(['pharmacy_id', 'supplier_invoice_id', 'status']);
        });
        DB::table('purchase_receipts as receipt')->join('purchase_orders as po', 'po.id', '=', 'receipt.purchase_order_id')
            ->where('receipt.status', 'POSTED')->where('po.payment_type', 'credit')
            ->select('receipt.id', 'receipt.pharmacy_id', 'receipt.number', 'receipt.invoice_number', 'receipt.received_on', 'receipt.total', 'po.supplier_id', 'po.supplier_name', 'po.payment_days')
            ->orderBy('receipt.id')->chunk(200, function ($receipts) {
                foreach ($receipts as $receipt) {
                    DB::table('supplier_invoices')->insert(['id' => (string) Str::uuid(), 'pharmacy_id' => $receipt->pharmacy_id, 'supplier_id' => $receipt->supplier_id,
                        'purchase_receipt_id' => $receipt->id, 'number' => 'BILL-'.$receipt->number, 'supplier_name' => $receipt->supplier_name, 'supplier_invoice_number' => $receipt->invoice_number,
                        'received_on' => $receipt->received_on, 'payment_days' => $receipt->payment_days, 'due_on' => Carbon::parse($receipt->received_on)->addDays($receipt->payment_days)->toDateString(),
                        'amount' => $receipt->total, 'opening_paid_amount' => 0, 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
                }
            });
    }

    public function down(): void
    {
        if (DB::table('supplier_payments')->exists() || DB::table('supplier_invoices')->whereNotNull('reconciled_at')->exists()) {
            throw new RuntimeException('Rollback diblokir karena pembayaran atau saldo awal sudah dicatat.');
        }
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('supplier_invoices');
    }
};
