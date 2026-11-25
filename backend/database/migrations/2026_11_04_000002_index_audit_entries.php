<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_entries', fn (Blueprint $table) => $table->index(['pharmacy_id', 'id']));
    }

    public function down(): void
    {
        Schema::table('audit_entries', fn (Blueprint $table) => $table->dropIndex(['pharmacy_id', 'id']));
    }
};
