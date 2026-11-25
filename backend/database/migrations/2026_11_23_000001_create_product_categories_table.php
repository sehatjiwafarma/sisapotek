<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('pharmacy_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->boolean('active')->default(true);
            $table->unique(['pharmacy_id', 'name']);
        });
        foreach (DB::table('pharmacies')->pluck('id') as $pharmacy) {
            $names = collect(\App\Models\Product::CATEGORIES)->merge(DB::table('products')->where('pharmacy_id', $pharmacy)->distinct()->pluck('category'))->unique();
            foreach ($names as $name) {
                DB::table('product_categories')->insert(['pharmacy_id' => $pharmacy, 'name' => $name, 'active' => true]);
            }
        }
    }
    public function down(): void { Schema::dropIfExists('product_categories'); }
};
