<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccessManager;
use App\Services\PsychotropicReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Shuchkin\SimpleXLSX;
use Tests\TestCase;

class PsychotropicReportTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): array
    {
        $owner = User::factory()->create();
        $pharmacy = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek Rekap']);
        $product = $pharmacy->products()->create(['sku' => 'PSI-001', 'name' => 'Obat Psikotropika Uji', 'brand' => 'Uji', 'category' => 'Obat', 'base_unit' => 'Tablet', 'purchase_unit' => 'Box', 'purchase_conversion' => 100, 'selling_price' => 1000, 'minimum_stock' => 0, 'active' => true, 'requires_prescription' => true, 'is_psychotropic' => true]);
        $this->actingAs($owner);

        return [$owner, $pharmacy, $product];
    }

    private function movement($pharmacy, $product, string $date, string $type, string $quantity): string
    {
        $id = (string) Str::uuid();
        DB::table('stock_movements')->insert(['id' => $id, 'pharmacy_id' => $pharmacy->id, 'product_id' => $product->id, 'occurred_on' => $date, 'type' => $type, 'quantity' => $quantity, 'base_unit' => $product->base_unit, 'created_at' => now()]);

        return $id;
    }

    public function test_monthly_report_matches_template_and_reconciles_fractional_quantities(): void
    {
        [, $pharmacy, $product] = $this->workspace();
        $this->movement($pharmacy, $product, '2026-08-31', 'STOCKTAKE_IN', '10.1250');
        $this->movement($pharmacy, $product, '2026-09-01', 'PURCHASE_RECEIPT', '5.5000');
        $out = $this->movement($pharmacy, $product, '2026-09-30', 'STOCKTAKE_OUT', '-3.1250');
        $this->movement($pharmacy, $product, '2026-10-01', 'PURCHASE_RECEIPT', '99.0000');
        $url = route('psychotropic.index', [$pharmacy, 'year' => 2026, 'month' => 9]);
        $this->get($url)->assertOk()->assertSee('1 mutasi belum berkategori');
        $this->get($url.'&export=xlsx')->assertSessionHasErrors('report');
        $this->post(route('psychotropic.classify', [$pharmacy, $out]), ['category' => 'prescription', 'reason' => 'Dokumen pengeluaran resep uji'])->assertSessionHasNoErrors();
        $report = app(PsychotropicReport::class)->build($pharmacy, 2026, 9);
        $row = $report['rows'][$product->id];
        $this->assertSame(101250, $row['opening']);
        $this->assertSame(55000, $row['pbf']);
        $this->assertSame(31250, $row['prescription']);
        $this->assertSame(125000, $row['closing']);
        $this->assertSame(0, $report['unmapped']);
        $response = $this->get($url.'&export=xlsx')->assertOk()->assertDownload('rekapitulasi-psikotropika-2026-09.xlsx');
        $book = SimpleXLSX::parseData($response->getContent());
        $this->assertNotFalse($book);
        $this->assertSame(['Psikotropika'], $book->sheetNames());
        $rows = $book->rows();
        $this->assertSame('REKAPITULASI LAPORAN PSIKOTROPIKA', $rows[1][0]);
        $this->assertSame('Apotek Rekap', $rows[3][3]);
        $this->assertSame('September', $rows[4][10]);
        $this->assertSame('PBF', $rows[7][5]);
        $this->assertSame('PEMUSNAHAN', $rows[7][9]);
        $this->assertEquals(10.125, $rows[8][4]);
        $this->assertEquals(12.5, $rows[8][10]);
        $this->assertDatabaseHas('audit_entries', ['pharmacy_id' => $pharmacy->id, 'action' => 'psychotropic.report_exported']);
        $this->assertEquals('-3.1250', DB::table('stock_movements')->where('id', $out)->value('quantity'));
    }

    public function test_retours_reduce_original_category_and_products_are_explicitly_selected(): void
    {
        [, $pharmacy, $product] = $this->workspace();
        $this->movement($pharmacy, $product, '2026-09-01', 'PURCHASE_RECEIPT', '20');
        $return = $this->movement($pharmacy, $product, '2026-09-02', 'SUPPLIER_RETURN', '-2');
        $this->post(route('psychotropic.classify', [$pharmacy, $return]), ['category' => 'pbf', 'reason' => 'Retur ke PBF sesuai dokumen'])->assertSessionHasNoErrors();
        $product->update(['active' => false]);
        $row = app(PsychotropicReport::class)->build($pharmacy, 2026, 9)['rows'][$product->id];
        $this->assertSame(180000, $row['pbf']);
        $this->assertSame(180000, $row['closing']);
        $product->update(['is_psychotropic' => false]);
        $this->assertCount(0, app(PsychotropicReport::class)->build($pharmacy, 2026, 9)['rows']);
        $this->get(route('psychotropic.index', [$pharmacy, 'year' => 2026, 'month' => 9, 'export' => 'xlsx']))->assertSessionHasErrors('report');
        $this->assertSame('prescription', PsychotropicReport::category((object) ['manual_category' => null, 'type' => 'SALE_RETURN', 'return_kind' => 'PRESCRIPTION']));
    }

    public function test_export_and_classification_permissions_and_tenant_isolation(): void
    {
        [$owner, $pharmacy, $product] = $this->workspace();
        $movement = $this->movement($pharmacy, $product, '2026-09-01', 'PURCHASE_RECEIPT', '10');
        $other = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek lain']);
        $this->post(route('psychotropic.classify', [$other, $movement]), ['category' => 'pbf', 'reason' => 'Tidak sesuai apotek'])->assertNotFound();
        $this->post(route('psychotropic.classify', [$pharmacy, $movement]), ['category' => 'unknown', 'reason' => 'Kategori tidak valid'])->assertSessionHasErrors('category');
        $this->get(route('psychotropic.index', [$pharmacy, 'month' => 13]))->assertSessionHasErrors('month');
        $staff = User::factory()->create();
        $role = $pharmacy->roles()->create(['name' => 'Pembaca laporan', 'permissions' => ['reports.inventory'], 'is_owner' => false]);
        $member = $pharmacy->memberships()->create(['user_id' => $staff->id, 'active' => true]);
        app(AccessManager::class)->syncRoles($member, collect([$role]));
        $this->actingAs($staff)->get(route('psychotropic.index', [$pharmacy, 'year' => 2026, 'month' => 9]))->assertOk();
        $this->get(route('psychotropic.index', [$pharmacy, 'year' => 2026, 'month' => 9, 'export' => 'xlsx']))->assertForbidden();
        $this->post(route('psychotropic.classify', [$pharmacy, $movement]), ['category' => 'pbf', 'reason' => 'Tanpa izin mengubah'])->assertForbidden();
        $this->get(route('psychotropic.index', $other))->assertForbidden();
    }
}
