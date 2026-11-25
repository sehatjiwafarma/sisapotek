<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccessManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProfitLossTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_open_report_and_only_posted_expenses_in_period_and_pharmacy_are_counted(): void
    {
        $owner = User::factory()->create();
        $pharmacy = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek Laba']);
        $other = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek Lain']);
        foreach ([[$pharmacy, 'POSTED', '2026-11-01', 100], [$pharmacy, 'PENDING', '2026-11-01', 200], [$pharmacy, 'POSTED', '2026-10-01', 300], [$other, 'POSTED', '2026-11-01', 400]] as [$tenant, $status, $date, $amount]) {
            $category = (string) Str::uuid();
            DB::table('expense_categories')->insert(['id' => $category, 'pharmacy_id' => $tenant->id, 'name' => $category, 'active' => true]);
            DB::table('operating_expenses')->insert(['id' => (string) Str::uuid(), 'pharmacy_id' => $tenant->id, 'expense_category_id' => $category, 'request_key' => (string) Str::uuid(), 'number' => $category, 'category_name' => 'Biaya', 'description' => 'Operasional', 'spent_on' => $date, 'amount' => $amount, 'method' => 'cash', 'status' => $status, 'created_by' => $owner->id]);
        }
        $this->actingAs($owner)->get(route('profit-loss.index', ['pharmacy' => $pharmacy, 'from' => '2026-11-01', 'to' => '2026-11-30']))
            ->assertOk()->assertSee('aria-label="Laporan laba rugi"', false)
            ->assertViewHas('summary', fn ($summary) => $summary['expenses'] === 10000 && $summary['net'] === -10000 && $summary['revenue'] === 0);
        $this->get(route('profit-loss.index', ['pharmacy' => $pharmacy, 'from' => '2026-11-30', 'to' => '2026-11-01']))->assertSessionHasErrors();
        $this->get(route('profit-loss.index', ['pharmacy' => $pharmacy, 'from' => '2024-01-01', 'to' => '2026-11-01']))->assertSessionHasErrors();
    }

    public function test_non_owner_cannot_access_report_even_with_report_permissions(): void
    {
        $owner = User::factory()->create();
        $pharmacy = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek Laba']);
        $staff = User::factory()->create();
        $membership = $pharmacy->memberships()->create(['user_id' => $staff->id, 'active' => true]);
        $role = $pharmacy->roles()->create(['name' => 'Staff laporan', 'permissions' => ['dashboard.view', 'reports.sales']]);
        $membership->roles()->attach($role->id, ['pharmacy_id' => $pharmacy->id]);
        $this->actingAs($staff)->get(route('profit-loss.index', $pharmacy))->assertForbidden();
        $this->get(route('reports.index', $pharmacy))->assertOk()->assertDontSee('aria-label="Laporan laba rugi"', false);
        $other = app(AccessManager::class)->provision($staff->id, ['name' => 'Apotek milik staff']);
        $this->get(route('profit-loss.index', $other))->assertOk();
        $this->get(route('profit-loss.index', $pharmacy))->assertForbidden();
        $this->actingAs($owner);
        $pharmacy->memberships()->where('user_id', $owner->id)->update(['active' => false]);
        $this->get(route('profit-loss.index', $pharmacy))->assertForbidden();
    }
}
