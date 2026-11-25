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
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->string('dispensing_status', 20)->default('UNREDEEMED');
        });
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignUuid('prescription_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('recipient_encrypted')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('verified_at')->nullable();
            $table->foreignId('handed_over_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('handed_over_at')->nullable();
            $table->index(['pharmacy_id', 'prescription_id', 'status']);
        });
        Schema::table('sale_items', function (Blueprint $table) {
            $table->decimal('quantity', 13, 4)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('sales')->whereNotNull('prescription_id')->exists()) {
            throw new RuntimeException('Rollback diblokir karena penebusan resep sudah tercatat.');
        }
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['pharmacy_id', 'prescription_id', 'status']);
            $table->dropConstrainedForeignId('prescription_id');
            $table->dropConstrainedForeignId('verified_by');
            $table->dropConstrainedForeignId('handed_over_by');
            $table->dropColumn(['recipient_encrypted', 'verified_at', 'handed_over_at']);
        });
        Schema::table('prescriptions', fn (Blueprint $table) => $table->dropColumn('dispensing_status'));
    }
};
