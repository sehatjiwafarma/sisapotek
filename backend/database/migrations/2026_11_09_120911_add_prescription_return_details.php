<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_returns', function (Blueprint $table) {
            $table->text('returner_encrypted')->nullable();
            $table->text('return_reason_encrypted')->nullable();
            $table->text('cancellation_encrypted')->nullable();
            $table->foreignId('entitlement_restored_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('entitlement_restored_at')->nullable();
            $table->text('entitlement_reason_encrypted')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('sale_returns')->whereNotNull('returner_encrypted')->exists()) {
            throw new RuntimeException('Rollback diblokir karena retur resep sudah tercatat.');
        }
        Schema::table('sale_returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('entitlement_restored_by');
            $table->dropColumn(['returner_encrypted', 'return_reason_encrypted', 'cancellation_encrypted', 'entitlement_restored_at', 'entitlement_reason_encrypted']);
        });
    }
};
