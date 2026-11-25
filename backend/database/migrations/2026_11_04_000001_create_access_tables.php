<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 30)->nullable();
        });
        Schema::create('pharmacies', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('address', 500)->nullable();
            $table->timestamps();
        });
        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pharmacy_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['pharmacy_id', 'user_id']);
            $table->unique(['id', 'pharmacy_id']);
        });
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pharmacy_id')->constrained()->restrictOnDelete();
            $table->string('name', 80);
            $table->string('description', 300)->nullable();
            $table->boolean('is_owner')->default(false);
            $table->json('permissions');
            $table->timestamps();
            $table->unique(['pharmacy_id', 'name']);
            $table->unique(['id', 'pharmacy_id']);
        });
        Schema::create('membership_role', function (Blueprint $table) {
            $table->unsignedBigInteger('membership_id');
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('pharmacy_id');
            $table->primary(['membership_id', 'role_id']);
            $table->foreign(['membership_id', 'pharmacy_id'])->references(['id', 'pharmacy_id'])->on('memberships')->cascadeOnDelete();
            $table->foreign(['role_id', 'pharmacy_id'])->references(['id', 'pharmacy_id'])->on('roles')->restrictOnDelete();
        });
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pharmacy_id')->constrained()->restrictOnDelete();
            $table->foreignId('invited_by')->constrained('users')->restrictOnDelete();
            $table->string('email');
            $table->json('role_ids');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['pharmacy_id', 'email']);
        });
        Schema::create('audit_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pharmacy_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 80);
            $table->string('subject', 150);
            $table->json('details');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['audit_entries', 'invitations', 'membership_role', 'roles', 'memberships', 'pharmacies'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('phone'));
    }
};
