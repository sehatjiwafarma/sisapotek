<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->dropUnique(['visit_id']);
            $table->index(['pharmacy_id', 'visit_id']);
            $table->uuid('request_key')->nullable();
            $table->unique(['pharmacy_id', 'request_key']);
        });
    }

    public function down(): void
    {
        if (DB::table('prescriptions')->select('visit_id')->groupBy('visit_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Beberapa resep sudah memakai kunjungan yang sama. Data tidak dihapus otomatis.');
        }
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->dropUnique(['pharmacy_id', 'request_key']);
            $table->dropColumn('request_key');
            $table->dropIndex(['pharmacy_id', 'visit_id']);
            $table->unique('visit_id');
        });
    }
};
