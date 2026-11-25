<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stocktake_lines', function (Blueprint $table) {
            $table->decimal('physical_card_quantity', 20, 4)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stocktake_lines', function (Blueprint $table) {
            $table->dropColumn('physical_card_quantity');
        });
    }
};
