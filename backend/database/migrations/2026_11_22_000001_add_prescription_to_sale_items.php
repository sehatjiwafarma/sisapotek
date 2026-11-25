<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropUnique(['sale_id', 'product_id']);
            $table->foreignUuid('prescription_id')->nullable()->constrained()->restrictOnDelete();
            $table->unique(['sale_id', 'prescription_id', 'product_id'], 'sale_rx_product_unique');
        });
        DB::statement('CREATE UNIQUE INDEX sale_single_product_unique ON sale_items (sale_id, product_id) WHERE prescription_id IS NULL');
    }

    public function down(): void
    {
        if (DB::table('sale_items')->whereNotNull('prescription_id')->exists()) {
            throw new RuntimeException('Rollback diblokir karena penebusan gabungan sudah tercatat.');
        }
        DB::statement('DROP INDEX sale_single_product_unique');
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropUnique('sale_rx_product_unique');
            $table->dropConstrainedForeignId('prescription_id');
            $table->unique(['sale_id', 'product_id']);
        });
    }
};
