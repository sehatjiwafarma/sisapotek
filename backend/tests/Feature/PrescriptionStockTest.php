<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccessManager;
use App\Services\ClinicalMaster;
use App\Services\StockLots;
use App\Services\VisitRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PrescriptionStockTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): array
    {
        // Separate temporary files from other test processes running against another database.
        Storage::set('local', Storage::fake('deferred-clinical-'.getmypid()));
        $owner = User::factory()->create();
        $pharmacy = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek Layanan Online']);
        $pharmacy->forceFill(['status' => 'ACTIVE'])->save();
        $master = app(ClinicalMaster::class);
        $patient = $master->savePatient($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'name' => 'Pasien Online', 'birth_date' => '1990-01-01', 'sex' => 'female', 'allergies' => 'Alergi privat online']);
        $doctor = $master->saveDoctor($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'name' => 'Dokter Online']);
        $visits = app(VisitRegistration::class);
        $visit = $visits->save($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'patient_id' => $patient, 'doctor_id' => $doctor, 'type' => 'new', 'complaint' => 'Konsultasi online']);
        \Illuminate\Support\Facades\DB::table('visits')->where('id', $visit)->update(['status' => 'IN_PROGRESS', 'started_at' => now(), 'revision' => 2]); // Historical visit fixture.
        $product = $pharmacy->products()->create(['sku' => 'OBAT-ONLINE', 'name' => 'Obat Simulasi', 'brand' => 'Dummy', 'category' => 'Obat', 'base_unit' => 'Tablet', 'purchase_unit' => 'Tablet', 'purchase_conversion' => 1, 'selling_price' => 1000, 'minimum_stock' => 0, 'active' => true, 'requires_prescription' => true]);
        DB::table('stock_movements')->insert(['id' => (string) Str::uuid(), 'pharmacy_id' => $pharmacy->id, 'product_id' => $product->id, 'type' => 'STOCKTAKE_IN', 'quantity' => '20.0000', 'base_unit' => 'Tablet', 'occurred_on' => now()->toDateString(), 'expires_on' => now()->addYear()->toDateString(), 'created_at' => now()]);
        $this->actingAs($owner);

        return [$owner, $pharmacy, $visit, $product];
    }

    private function recipe($product, string $quantity = '5'): array
    {
        return ['valid_until' => now()->addDays(30)->toDateString(), 'revision' => 0, 'items' => [['product_id' => $product->id, 'quantity' => $quantity, 'dose' => '1 tablet', 'frequency' => '2 kali sehari', 'instructions' => 'Sesudah makan']]];
    }

    public function test_general_products_are_hidden_and_rejected_even_when_submitted_directly(): void
    {
        [, $pharmacy,$visit,$product] = $this->workspace();
        $product->update(['requires_prescription' => false]);
        $this->get(route('prescriptions.edit', [$pharmacy, $visit]))->assertOk()->assertDontSee($product->name);
        $this->put(route('prescriptions.save', [$pharmacy, $visit]), $this->recipe($product))->assertSessionHasErrors('items');
        $this->assertDatabaseCount('prescriptions', 0);
        $product->update(['requires_prescription' => true]);
        $this->put(route('prescriptions.save', [$pharmacy, $visit]), $this->recipe($product))->assertSessionHasNoErrors();
        $product->update(['requires_prescription' => false]);
        $this->post(route('prescriptions.finalize', [$pharmacy, $visit]), ['revision' => 1])->assertSessionHasErrors('items');
        $this->assertDatabaseHas('prescriptions', ['visit_id' => $visit, 'status' => 'DRAFT', 'revision' => 1]);
    }

    public function test_stock_is_shown_and_expired_future_or_exhausted_lots_cannot_back_a_final_prescription(): void
    {
        [, $pharmacy,$visit,$product] = $this->workspace();
        $source = DB::table('stock_movements')->first();
        $this->get(route('prescriptions.edit', [$pharmacy, $visit]))->assertOk()->assertSee('Stok 20 Tablet');
        DB::table('stock_movements')->where('id', $source->id)->update(['expires_on' => now()->toDateString()]);
        foreach ([['expires_on' => now()->subDay()->toDateString(), 'occurred_on' => now()->toDateString()], ['expires_on' => now()->addYear()->toDateString(), 'occurred_on' => now()->addDay()->toDateString()]] as $dates) {
            DB::table('stock_movements')->insert($dates + ['id' => (string) Str::uuid(), 'pharmacy_id' => $pharmacy->id, 'product_id' => $product->id, 'type' => 'STOCKTAKE_IN', 'quantity' => 100, 'base_unit' => 'Tablet', 'created_at' => now()]);
        }
        $this->put(route('prescriptions.save', [$pharmacy, $visit]), $this->recipe($product))->assertSessionHasNoErrors();
        $this->post(route('prescriptions.finalize', [$pharmacy, $visit]), ['revision' => 1])->assertSessionHasErrors('items.0.quantity');
        $this->assertDatabaseHas('prescriptions', ['visit_id' => $visit, 'status' => 'DRAFT', 'revision' => 1]);
        DB::table('stock_movements')->where('id', $source->id)->update(['expires_on' => now()->addYear()->toDateString()]);
        DB::table('stock_movements')->insert(['id' => (string) Str::uuid(), 'pharmacy_id' => $pharmacy->id, 'product_id' => $product->id, 'type' => 'STOCKTAKE_OUT', 'source_movement_id' => $source->id, 'quantity' => '-20.0000', 'base_unit' => 'Tablet', 'occurred_on' => now()->toDateString(), 'created_at' => now()]);
        $this->assertSame(0, app(StockLots::class)->available($pharmacy, [$product->id])[$product->id]);
        $this->post(route('prescriptions.finalize', [$pharmacy, $visit]), ['revision' => 1])->assertSessionHasErrors('items.0.quantity');
    }

    public function test_finalization_rechecks_current_stock_and_does_not_reserve_or_deduct_it(): void
    {
        [, $pharmacy,$visit,$product] = $this->workspace();
        $source = DB::table('stock_movements')->first();
        $this->put(route('prescriptions.save', [$pharmacy, $visit]), $this->recipe($product, '5.0001'))->assertSessionHasNoErrors();
        $movement = (string) Str::uuid();
        DB::table('stock_movements')->insert(['id' => $movement, 'pharmacy_id' => $pharmacy->id, 'product_id' => $product->id, 'type' => 'STOCKTAKE_OUT', 'source_movement_id' => $source->id, 'quantity' => '-15.0000', 'base_unit' => 'Tablet', 'occurred_on' => now()->toDateString(), 'created_at' => now()]);
        $this->post(route('prescriptions.finalize', [$pharmacy, $visit]), ['revision' => 1])->assertSessionHasErrors('items.0.quantity');
        $this->assertDatabaseHas('prescriptions', ['visit_id' => $visit, 'status' => 'DRAFT', 'revision' => 1]);
        $this->put(route('prescriptions.save', [$pharmacy, $visit]), array_replace($this->recipe($product, '5'), ['revision' => 1]))->assertSessionHasNoErrors();
        $this->post(route('prescriptions.finalize', [$pharmacy, $visit]), ['revision' => 2])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('prescriptions', ['visit_id' => $visit, 'status' => 'FINAL', 'revision' => 3]);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertSame(50000, app(StockLots::class)->available($pharmacy,[$product->id])[$product->id]);
    }
}
