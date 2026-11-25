<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void {
        $visits = DB::table('visits')->where('status', 'REGISTERED')->whereExists(function ($q) {
            $q->selectRaw('1')->from('service_bills')->whereColumn('service_bills.pharmacy_id', 'visits.pharmacy_id')->whereColumn('service_bills.visit_id', 'visits.id')->where('service_bills.status', 'PAID');
        })->get(['id', 'pharmacy_id', 'revision']);
        foreach ($visits as $visit) {
            $updated = DB::table('visits')->where('id', $visit->id)->where('status', 'REGISTERED')->where('revision', $visit->revision)->update(['status' => 'IN_PROGRESS', 'started_at' => now(), 'revision' => $visit->revision + 1, 'updated_at' => now()]);
            if ($updated) DB::table('audit_entries')->insert(['pharmacy_id' => $visit->pharmacy_id, 'actor_id' => null, 'action' => 'visit.started', 'subject' => $visit->id, 'details' => json_encode(['source' => 'paid_visit_migration']), 'created_at' => now()]);
        }
    }
    public function down(): void { /* Status is an operational event and is not reversed. */ }
};
