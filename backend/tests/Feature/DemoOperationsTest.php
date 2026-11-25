<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccessManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DemoOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_scenarios_are_consistent_scoped_and_safe_to_repeat(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $pharmacy = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek Uji Dummy']);
        $pharmacy->forceFill(['status' => 'ACTIVE'])->save();
        $other = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek Tidak Diubah']);
        $this->artisan('apotek:demo-clinical', ['pharmacy' => $pharmacy->code])->assertSuccessful();
        $this->artisan('apotek:demo-operations', ['pharmacy' => $pharmacy->code])->assertSuccessful();
        $this->assertDatabaseCount('doctor_signatures', 4);
        foreach (DB::table('doctor_signatures')->get() as $signature) {
            Storage::disk('local')->assertExists($signature->path);
            $this->assertSame($signature->sha256, hash('sha256', Storage::disk('local')->get($signature->path)));
        }
        $this->assertSame(['CANCELLED', 'DRAFT', 'ORDERED', 'PARTIAL', 'RECEIVED'], $pharmacy->purchaseOrders()->orderBy('status')->pluck('status')->all());
        $this->assertSame(['CANCELLED', 'COMPLETED', 'IN_PROGRESS', 'REGISTERED'], DB::table('visits')->where('pharmacy_id', $pharmacy->id)->distinct()->orderBy('status')->pluck('status')->all());
        $this->assertDatabaseCount('visits', 7);
        $this->assertDatabaseCount('prescriptions', 4);
        $this->assertSame(['CANCELLED', 'DRAFT', 'PAID', 'PAID', 'PAID'], DB::table('service_bills')->orderBy('status')->pluck('status')->all());
        $this->assertSame(['APPROVED', 'CANCELLED', 'COUNTING', 'POSTED', 'POSTED'], DB::table('stocktakes')->orderBy('status')->pluck('status')->all());
        $this->assertSame(['COMPLETE', 'PARTIAL', 'UNREDEEMED'], DB::table('prescriptions')->distinct()->orderBy('dispensing_status')->pluck('dispensing_status')->all());
        $this->assertSame(3, DB::table('sales')->whereNotNull('handed_over_at')->count());
        $this->assertSame(3, DB::table('sales')->where('status', 'PAID')->count());
        $this->assertSame(['PURCHASE_RECEIPT', 'SALE', 'STOCKTAKE_IN', 'STOCKTAKE_OUT'], DB::table('stock_movements')->distinct()->orderBy('type')->pluck('type')->all());
        foreach (['01' => 126, '02' => 127, '03' => 131, '04' => 120, '05' => 120, '06' => 120, '07' => 0] as $sku => $expected) {
            $product = $pharmacy->products()->where('sku', 'DEMO-OPS-'.$sku)->firstOrFail();
            $this->assertEquals($expected, DB::table('stock_movements')->where('product_id', $product->id)->sum('quantity'));
        }
        $this->assertSame(0, $other->products()->count());
        $this->assertSame(0, DB::table('visits')->where('pharmacy_id', $other->id)->count());
        $tables = ['doctor_signatures', 'products', 'purchase_orders', 'purchase_receipts', 'patients', 'visits', 'examinations', 'prescriptions', 'sales', 'stocktakes', 'stock_movements', 'service_bills', 'cash_entries'];
        $counts = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()]);
        $this->artisan('apotek:demo-operations', ['pharmacy' => $pharmacy->code])->assertSuccessful();
        foreach ($counts as $table => $count) {
            $this->assertDatabaseCount($table, $count);
        }
    }
}
