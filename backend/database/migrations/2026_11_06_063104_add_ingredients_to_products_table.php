<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->string('name', 200);
            $table->decimal('amount', 14, 4)->nullable();
            $table->string('unit', 20)->nullable();
            $table->decimal('basis_quantity', 14, 4)->nullable();
            $table->string('basis_unit', 30)->nullable();
            $table->unsignedSmallInteger('position');
            $table->index(['product_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_ingredients');
    }
};
