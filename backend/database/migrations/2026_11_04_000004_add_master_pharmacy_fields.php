<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->boolean('is_system_admin')->default(false));
        Schema::create('system_admin_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->boolean('granted');
            $table->timestampTz('created_at');
        });
        Schema::create('regions', function (Blueprint $table) {
            $table->string('code', 20)->primary();
            $table->string('parent_code', 20)->nullable();
            $table->string('name', 150);
            $table->unsignedTinyInteger('level');
            $table->index(['parent_code', 'name']);
        });
        Schema::table('regions', fn (Blueprint $table) => $table->foreign('parent_code')->references('code')->on('regions')->restrictOnDelete());
        Schema::table('pharmacies', function (Blueprint $table) {
            $table->string('code', 20)->nullable()->unique();
            $table->enum('status', ['DRAFT', 'ACTIVE', 'INACTIVE'])->default('DRAFT');
            $table->enum('ownership_type', ['Individual', 'Legal Entity'])->default('Individual');
            $table->string('business_name', 200)->nullable();
            $table->string('legal_form', 30)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 254)->nullable();
            $table->string('website', 500)->nullable();
            foreach (['province', 'city', 'district', 'village'] as $level) {
                $table->string($level.'_code', 20)->nullable();
                $table->foreign($level.'_code')->references('code')->on('regions')->restrictOnDelete();
            }
            $table->string('postal_code', 10)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 11, 7)->nullable();
            $table->string('maps_url', 500)->nullable();
            $table->string('owner_name', 150)->nullable();
            $table->string('owner_phone', 20)->nullable();
            $table->string('owner_email', 254)->nullable();
            $table->string('logo_path')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('deactivated_at')->nullable();
            $table->text('deactivation_reason')->nullable();
            $table->boolean('has_transactions')->default(false);
        });
        Schema::create('pharmacy_licenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pharmacy_id')->unique()->constrained()->restrictOnDelete();
            $table->string('nib', 30)->nullable();
            $table->string('license_number', 100)->nullable();
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->boolean('has_expiry')->default(false);
            $table->string('npwp', 30)->nullable();
            $table->string('issuer', 150)->nullable();
            $table->enum('document_status', ['Recorded', 'Verified', 'Expired'])->default('Recorded');
            $table->timestampsTz();
        });
        Schema::create('pharmacy_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pharmacy_id')->unique()->constrained()->restrictOnDelete();
            $table->string('timezone', 50)->default('Asia/Jakarta');
            $table->string('currency', 3)->default('IDR');
            $table->string('stock_issue_method', 20)->default('FEFO');
            $table->string('valuation_method', 30)->default('MOVING_AVERAGE');
            $table->boolean('allow_negative_stock')->default(false);
            $table->boolean('track_batch')->default(true);
            $table->boolean('track_expiry')->default(true);
            $table->unsignedInteger('expiry_reminder_days')->default(90);
            $table->boolean('minimum_stock_notification')->default(true);
            $table->text('receipt_header')->nullable();
            $table->text('receipt_footer')->nullable();
            $table->timestampsTz();
        });
        Schema::create('pharmacy_personnel', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pharmacy_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->string('phone', 20)->nullable();
            $table->string('str_number', 100)->nullable();
            $table->string('sip_number', 100)->nullable();
            $table->date('sip_expires_on')->nullable();
            $table->boolean('active')->default(true);
            $table->timestampsTz();
            $table->unique(['id', 'pharmacy_id']);
        });
        Schema::create('pharmacy_personnel_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pharmacy_id')->constrained()->restrictOnDelete();
            $table->uuid('personnel_id');
            $table->string('role', 20)->default('APJ');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->json('credential_snapshot');
            $table->timestampsTz();
            $table->foreign(['personnel_id', 'pharmacy_id'])->references(['id', 'pharmacy_id'])->on('pharmacy_personnel')->restrictOnDelete();
        });
        DB::statement("CREATE UNIQUE INDEX pharmacy_single_open_apj ON pharmacy_personnel_assignments (pharmacy_id) WHERE role = 'APJ' AND ends_on IS NULL");
        Schema::create('pharmacy_operating_hours', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pharmacy_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('day');
            $table->boolean('is_open');
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->boolean('overnight')->default(false);
            $table->unsignedInteger('start_minute')->nullable();
            $table->unsignedInteger('end_minute')->nullable();
            $table->string('note', 200)->nullable();
            $table->timestampsTz();
        });
        Schema::create('pharmacy_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pharmacy_id')->constrained()->restrictOnDelete();
            $table->string('type', 30);
            $table->string('name', 150);
            $table->string('path');
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->date('expires_on')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });
        foreach (DB::table('pharmacies')->get() as $pharmacy) {
            DB::table('pharmacies')->where('id', $pharmacy->id)->update(['code' => 'APT-'.str_pad((string) $pharmacy->legacy_id, 6, '0', STR_PAD_LEFT)]);
            foreach (['pharmacy_licenses', 'pharmacy_settings'] as $name) {
                $data = ['id' => (string) Str::uuid(), 'pharmacy_id' => $pharmacy->id, 'created_at' => now(), 'updated_at' => now()];
                if ($name === 'pharmacy_settings') {
                    $data['receipt_header'] = $pharmacy->name;
                }
                DB::table($name)->insert($data);
            }
        }
        DB::statement('CREATE UNIQUE INDEX pharmacy_code_case_insensitive ON pharmacies (UPPER(code))');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE pharmacies ALTER COLUMN code SET NOT NULL');
            foreach (['pharmacies', 'roles', 'memberships', 'invitations'] as $name) {
                foreach (['created_at', 'updated_at'] as $column) {
                    DB::statement("ALTER TABLE {$name} ALTER COLUMN {$column} TYPE timestamptz USING {$column} AT TIME ZONE 'UTC'");
                }
            }
            DB::statement("ALTER TABLE audit_entries ALTER COLUMN created_at TYPE timestamptz USING created_at AT TIME ZONE 'UTC'");
            DB::statement('ALTER TABLE pharmacy_personnel_assignments ADD CONSTRAINT apj_dates_valid CHECK (ends_on IS NULL OR ends_on >= starts_on)');
            DB::statement('ALTER TABLE pharmacy_operating_hours ADD CONSTRAINT hours_range_valid CHECK (day BETWEEN 0 AND 6 AND ((NOT is_open AND start_minute IS NULL AND end_minute IS NULL) OR (is_open AND start_minute >= 0 AND end_minute > start_minute AND end_minute <= 11520)))');
            DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
            DB::statement("ALTER TABLE pharmacy_personnel_assignments ADD CONSTRAINT apj_no_overlap EXCLUDE USING gist (pharmacy_id WITH =, daterange(starts_on, ends_on, '[]') WITH &&) WHERE (role = 'APJ')");
            DB::statement("ALTER TABLE pharmacy_operating_hours ADD CONSTRAINT hours_no_overlap EXCLUDE USING gist (pharmacy_id WITH =, (int4multirange(int4range(start_minute, LEAST(end_minute,10080),'[)'),int4range(0,GREATEST(end_minute-10080,0),'[)'))) WITH &&) WHERE (is_open)");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Gunakan backup untuk rollback modul master agar data historis tidak dihapus.');
    }
};
