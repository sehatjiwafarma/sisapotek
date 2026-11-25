<?php

use App\Support\Decimal;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_receipts', function (Blueprint $table) {
            $table->string('ppn_type', 20)->default('nominal');
            $table->decimal('ppn_value', 18, 2)->default(0);
            $table->decimal('ppn_amount', 18, 2)->default(0);
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
        });
        DB::table('purchase_receipts')->orderBy('id')->chunkById(100, function ($receipts) {
            foreach ($receipts as $receipt) {
                $subtotal = 0;
                foreach (DB::table('purchase_receipt_items')->where('purchase_receipt_id', $receipt->id)->get() as $line) {
                    $subtotal += Decimal::rounded(Decimal::scaled($line->quantity, 3) * Decimal::scaled($line->unit_price, 2), 1000);
                }
                DB::table('purchase_receipts')->where('id', $receipt->id)->update(['subtotal' => Decimal::format($subtotal), 'total' => Decimal::format($subtotal)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_receipts', function (Blueprint $table) {
            $table->dropColumn(['ppn_type', 'ppn_value', 'ppn_amount', 'subtotal', 'total']);
        });
    }
};
