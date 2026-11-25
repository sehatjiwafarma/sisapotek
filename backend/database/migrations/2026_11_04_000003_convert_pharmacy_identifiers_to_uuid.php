<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->convertSqlite();

            return;
        }
        DB::transaction(function () {
            $tables = ['memberships', 'roles', 'membership_role', 'invitations', 'audit_entries'];
            Schema::table('membership_role', function (Blueprint $table) {
                $table->dropForeign(['membership_id', 'pharmacy_id']);
                $table->dropForeign(['role_id', 'pharmacy_id']);
            });
            foreach (array_diff($tables, ['membership_role']) as $name) {
                Schema::table($name, fn (Blueprint $table) => $table->dropForeign(['pharmacy_id']));
            }
            Schema::table('pharmacies', function (Blueprint $table) {
                $table->uuid('new_uuid')->nullable();
                $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            });
            foreach (DB::table('pharmacies')->get() as $row) {
                DB::table('pharmacies')->where('id', $row->id)->update(['new_uuid' => (string) Str::uuid(), 'legacy_id' => $row->id]);
            }
            foreach ($tables as $name) {
                Schema::table($name, fn (Blueprint $table) => $table->uuid('new_pharmacy_uuid')->nullable());
                DB::statement("UPDATE {$name} AS target SET new_pharmacy_uuid = pharmacies.new_uuid FROM pharmacies WHERE target.pharmacy_id = pharmacies.id");
                DB::statement("ALTER TABLE {$name} ALTER COLUMN pharmacy_id TYPE uuid USING new_pharmacy_uuid");
                Schema::table($name, fn (Blueprint $table) => $table->dropColumn('new_pharmacy_uuid'));
            }
            DB::statement('ALTER TABLE pharmacies ALTER COLUMN id DROP DEFAULT');
            DB::statement('ALTER TABLE pharmacies ALTER COLUMN id TYPE uuid USING new_uuid');
            Schema::table('pharmacies', fn (Blueprint $table) => $table->dropColumn('new_uuid'));
            foreach (array_diff($tables, ['membership_role']) as $name) {
                Schema::table($name, fn (Blueprint $table) => $table->foreign('pharmacy_id')->references('id')->on('pharmacies')->restrictOnDelete());
            }
            Schema::table('membership_role', function (Blueprint $table) {
                $table->foreign(['membership_id', 'pharmacy_id'])->references(['id', 'pharmacy_id'])->on('memberships')->cascadeOnDelete();
                $table->foreign(['role_id', 'pharmacy_id'])->references(['id', 'pharmacy_id'])->on('roles')->restrictOnDelete();
            });
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Migrasi UUID tidak dibalik otomatis agar relasi historis tetap utuh. Gunakan restore backup untuk rollback deployment.');
    }

    private function convertSqlite(): void
    {
        $tables = ['pharmacies', 'memberships', 'roles', 'membership_role', 'invitations', 'audit_entries'];
        $definitions = [];
        $rows = [];
        $indexes = [];
        $map = [];
        foreach ($tables as $name) {
            $definitions[$name] = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$name])->sql;
            $rows[$name] = DB::table($name)->get()->map(fn ($row) => (array) $row)->all();
            $indexes[$name] = DB::select("SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND sql IS NOT NULL", [$name]);
        }
        foreach ($rows['pharmacies'] as $row) {
            $map[$row['id']] = (string) Str::uuid();
        }
        DB::statement('PRAGMA foreign_keys = OFF');
        try {
            DB::transaction(function () use ($tables, $definitions, $rows, $indexes, $map) {
                foreach (array_reverse($tables) as $name) {
                    Schema::drop($name);
                }
                foreach ($tables as $name) {
                    $sql = $definitions[$name];
                    if ($name === 'pharmacies') {
                        $sql = preg_replace('/"id" integer (?:not null )?primary key autoincrement(?: not null)?/i', '"id" varchar not null primary key', $sql);
                    } else {
                        $sql = preg_replace('/"pharmacy_id" integer/i', '"pharmacy_id" varchar', $sql);
                    }
                    DB::statement($sql);
                    if ($name === 'pharmacies') {
                        Schema::table($name, fn (Blueprint $table) => $table->unsignedBigInteger('legacy_id')->nullable()->unique());
                    }
                    foreach ($rows[$name] as $row) {
                        if ($name === 'pharmacies') {
                            $row['legacy_id'] = $row['id'];
                            $row['id'] = $map[$row['id']];
                        } else {
                            $row['pharmacy_id'] = $map[$row['pharmacy_id']];
                        }
                        DB::table($name)->insert($row);
                    }
                    foreach ($indexes[$name] as $index) {
                        DB::statement($index->sql);
                    }
                }
                if (DB::select('PRAGMA foreign_key_check')) {
                    throw new RuntimeException('Relasi SQLite tidak valid setelah konversi UUID.');
                }
            });
        } finally {
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }
};
