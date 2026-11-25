<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('prescriptions', function (Blueprint $t) { $t->timestampTz('ready_at')->nullable(); });
        Schema::table('sale_items', function (Blueprint $t) {
            $t->uuid('prescribed_product_id')->nullable();
            $t->decimal('prescribed_quantity', 18, 4)->nullable();
            $t->text('adjusted_dose_encrypted')->nullable();
        });
    }
    public function down(): void {
        Schema::table('sale_items', fn (Blueprint $t) => $t->dropColumn(['prescribed_product_id','prescribed_quantity','adjusted_dose_encrypted']));
        Schema::table('prescriptions', fn (Blueprint $t) => $t->dropColumn('ready_at'));
    }
};
