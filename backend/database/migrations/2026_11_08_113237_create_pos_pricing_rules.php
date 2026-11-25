<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pos_pricing_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pharmacy_id')->constrained()->restrictOnDelete();
            $table->string('kind', 10);
            $table->string('code', 30);
            $table->string('name', 150);
            $table->string('type', 15);
            $table->decimal('value', 18, 2);
            $table->string('tax_mode', 15)->nullable();
            $table->boolean('active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->timestampsTz();
            $table->unique(['pharmacy_id', 'kind', 'code']);
        });
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignUuid('discount_rule_id')->nullable()->constrained('pos_pricing_rules')->restrictOnDelete();
            $table->string('discount_name', 150)->nullable();
            $table->string('discount_type', 15)->nullable();
            $table->decimal('discount_value', 18, 2)->default(0);
            $table->foreignUuid('tax_rule_id')->nullable()->constrained('pos_pricing_rules')->restrictOnDelete();
            $table->string('tax_name', 150)->nullable();
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->string('tax_mode', 15)->nullable();
            $table->decimal('taxable_base', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('discount_rule_id');
            $table->dropConstrainedForeignId('tax_rule_id');
            $table->dropColumn(['discount_name', 'discount_type', 'discount_value', 'tax_name', 'tax_rate', 'tax_mode', 'taxable_base', 'tax_amount']);
        });
        Schema::dropIfExists('pos_pricing_rules');
    }
};
