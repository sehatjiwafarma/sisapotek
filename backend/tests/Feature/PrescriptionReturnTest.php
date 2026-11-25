<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccessManager;
use App\Services\ClinicalMaster;
use App\Services\PointOfSale;
use App\Services\PrescriptionDispensing;
use App\Services\PrescriptionRecords;
use App\Services\Procurement;
use App\Services\SaleReturns;
use App\Services\VisitRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PrescriptionReturnTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): array
    {
        $owner = User::factory()->create();
        $pharmacy = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek Penebusan']);
        $pharmacy->forceFill(['status' => 'ACTIVE'])->save();
        $master = app(ClinicalMaster::class);
        $patient = $master->savePatient($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'name' => 'Pasien Resep', 'birth_date' => '1990-01-01', 'sex' => 'female']);
        $doctor = $master->saveDoctor($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'name' => 'Dokter Resep']);
        $visits = app(VisitRegistration::class);
        $visit = $visits->save($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'patient_id' => $patient, 'doctor_id' => $doctor, 'type' => 'new', 'complaint' => 'Keluhan']);
        \Illuminate\Support\Facades\DB::table('visits')->where('id', $visit)->update(['status' => 'IN_PROGRESS', 'started_at' => now(), 'revision' => 2]); // Historical visit fixture.
        $product = $pharmacy->products()->create(['sku' => 'RX', 'name' => 'Obat Resep', 'brand' => 'Brand', 'category' => 'Obat', 'base_unit' => 'Tablet', 'purchase_unit' => 'Tablet', 'purchase_conversion' => 1, 'selling_price' => 1000, 'minimum_stock' => 0, 'active' => true, 'requires_prescription' => true]);
        $other = $pharmacy->products()->create(['sku' => 'RX2', 'name' => 'Obat Kedua', 'brand' => 'Brand', 'category' => 'Obat', 'base_unit' => 'Tablet', 'purchase_unit' => 'Tablet', 'purchase_conversion' => 1, 'selling_price' => 2000, 'minimum_stock' => 0, 'active' => true, 'requires_prescription' => true]);
        foreach ([$product, $other] as $testProduct) {
            $testProduct->update(['dosage_form' => 'Tablet']);
            $testProduct->ingredients()->create(['name' => 'Zat uji', 'amount' => 1, 'unit' => 'mg', 'basis_quantity' => 1, 'basis_unit' => 'Tablet', 'position' => 0]);
        }
        $records = app(PrescriptionRecords::class);
        $records->save($pharmacy, $owner->id, $visit, 0, ['valid_until' => now()->addDays(30)->toDateString(), 'items' => [
            ['product_id' => $product->id, 'quantity' => 10, 'dose' => '1 tablet', 'frequency' => '2 kali sehari', 'instructions' => 'Sesudah makan'],
            ['product_id' => $other->id, 'quantity' => '2.1234', 'dose' => 'Sesuai petunjuk', 'frequency' => 'Sesuai petunjuk', 'instructions' => 'Aturan kedua'],
        ]]);

        $rx = DB::table('prescriptions')->first();
        $supplier = $pharmacy->suppliers()->create(['code' => 'SUP', 'name' => 'Pemasok', 'active' => true]);
        $procurement = app(Procurement::class);
        $today = now()->toDateString();
        $order = $procurement->saveOrder($pharmacy, $owner->id, ['supplier_id' => $supplier->id, 'ordered_on' => $today, 'payment_type' => 'cash', 'payment_days' => 0, 'items' => [
            ['product_id' => $product->id, 'unit_type' => 'base', 'quantity' => 30, 'unit_price' => 500],
            ['product_id' => $other->id, 'unit_type' => 'base', 'quantity' => 30, 'unit_price' => 500],
        ]]);
        $procurement->finalize($pharmacy, $owner->id, $order->id);
        foreach ([['EARLY', now()->addMonth()->toDateString(), 4], ['LATE', now()->addYear()->toDateString(), 10]] as [$batch,$expires,$quantity]) {
            $receipt = $procurement->receive($pharmacy, $owner->id, $order->id, ['received_on' => $today, 'notes' => 'Penerimaan bertahap', 'items' => $order->items()->get()->map(fn ($item) => ['order_item_id' => $item->id, 'quantity' => $quantity, 'unit_price' => 500, 'batch_number' => $batch, 'expires_on' => $expires])->all()]);
            $procurement->post($pharmacy, $owner->id, $receipt->id);
        }
        $this->actingAs($owner);

        $records->finalize($pharmacy, $owner->id, $visit, 1);
        $rx = DB::table('prescriptions')->find($rx->id);

        return [$owner, $pharmacy, $rx, $product, $other];
    }

    private function paidRedemption($pharmacy, $rx, $product, bool $handedOver = true): object
    {
        $id = app(PrescriptionDispensing::class)->create($pharmacy, auth()->id(), $rx->id, ['request_key' => (string) Str::uuid(), 'verified' => 1, 'recipient_type' => 'self', 'items' => [['product_id' => $product->id, 'quantity' => 4]]]);
        $sale = DB::table('sales')->find($id);
        app(PointOfSale::class)->pay($pharmacy, auth()->id(), $id, ['revision' => 1, 'payment_method' => 'cash', 'amount_received' => $sale->total]);
        if ($handedOver) {
            app(PrescriptionDispensing::class)->handover($pharmacy, auth()->id(), $id);
        }

        return DB::table('sales')->find($id);
    }

    private function returned($pharmacy, $sale, string $quantity = '1', array $extra = []): object
    {
        $movement = SaleReturns::allocations($pharmacy, $sale->id)->first();
        $response = $this->post(route('returns.store', [$pharmacy, $sale->id]), $extra + ['request_key' => (string) Str::uuid(), 'reason' => 'Alasan klinis privat', 'condition_checked' => 1, 'returner_type' => 'self', 'items' => [['sale_movement_id' => $movement->id, 'quantity' => $quantity, 'disposition' => 'QUARANTINE']]])->assertSessionHasNoErrors()->assertRedirect();

        return DB::table('sale_returns')->find(basename(parse_url($response->headers->get('Location'), PHP_URL_PATH)));
    }

    private function completeReturn($pharmacy, $record): void
    {
        $this->post(route('returns.approve', [$pharmacy, $record->id]), ['revision' => 1])->assertSessionHasNoErrors();
        $this->post(route('returns.refund', [$pharmacy, $record->id]), ['revision' => 2, 'refund_method' => 'cash', 'refund_confirmed' => 1])->assertSessionHasNoErrors();
    }

    public function test_substitution_return_restores_proportional_prescribed_entitlement(): void
    {
        [, $pharmacy, $rx, $product, $other] = $this->workspace();
        $id = app(PrescriptionDispensing::class)->create($pharmacy, auth()->id(), $rx->id, ['request_key' => (string) Str::uuid(), 'verified' => 1, 'recipient_type' => 'self', 'items' => [['product_id' => $product->id, 'selected_product_id' => $other->id, 'quantity' => 4, 'prescribed_quantity' => 2, 'adjusted_dose' => '2 tablet', 'substitution_confirmed' => 1]]]);
        $sale = DB::table('sales')->find($id);
        app(PointOfSale::class)->pay($pharmacy, auth()->id(), $id, ['revision' => 1, 'payment_method' => 'cash', 'amount_received' => $sale->total]);
        app(PrescriptionDispensing::class)->handover($pharmacy, auth()->id(), $id);
        $record = $this->returned($pharmacy, DB::table('sales')->find($id), '2');
        $this->completeReturn($pharmacy, $record);
        $this->post(route('returns.restore_entitlement', [$pharmacy, $record->id]), ['revision' => 3, 'reason' => 'Disetujui'])->assertSessionHasNoErrors();
        $remaining = collect(PrescriptionDispensing::remaining($pharmacy, DB::table('prescriptions')->find($rx->id)))->keyBy('product_id');
        $this->assertSame(90000, $remaining[$product->id]['remaining']);
        $this->assertSame(21234, $remaining[$other->id]['remaining']);
    }
    public function test_refund_keeps_entitlement_and_patient_identity_while_returner_is_private(): void
    {
        [, $pharmacy,$rx,$product] = $this->workspace();
        $sale = $this->paidRedemption($pharmacy, $rx, $product);
        $this->get(route('returns.select', [$pharmacy, 'kind' => 'PRESCRIPTION']))->assertOk()->assertSee($sale->number)->assertSee('Pasien Resep');
        $this->get(route('returns.create', [$pharmacy, $sale->id]))->assertOk()->assertSee('Pengembali obat')->assertSee($rx->number);
        $record = $this->returned($pharmacy, $sale, '1', ['returner_type' => 'representative', 'returner_name' => 'Pengembali Privat', 'returner_relationship' => 'Keluarga', 'returner_phone' => '08123456789', 'restore_entitlement' => 1]);
        $this->assertStringNotContainsString('Pengembali Privat', $record->returner_encrypted);
        $this->assertStringNotContainsString('Alasan klinis privat', $record->reason);
        $this->assertSame('Pengembali Privat', json_decode(Crypt::decryptString($record->returner_encrypted), true)['name']);
        $this->completeReturn($pharmacy, $record);
        $this->assertEquals(1000, DB::table('sale_returns')->find($record->id)->refund_amount);
        $this->assertSame(60000, PrescriptionDispensing::remaining($pharmacy, DB::table('prescriptions')->find($rx->id))[0]['remaining']);
        $this->assertNull(DB::table('sale_returns')->find($record->id)->entitlement_restored_at);
        $this->assertSame(0, DB::table('stock_movements')->where('type', 'SALE_RETURN')->count());
        $this->get(route('returns.show', [$pharmacy, $record->id]))->assertOk()->assertSee('Pengembali Privat')->assertSee('Alasan klinis privat')->assertSee('Hak tebus pasien tidak bertambah');
        $this->get(route('returns.receipt', [$pharmacy, $record->id]))->assertOk()->assertSee('Pasien Resep')->assertSee('Pengembali Privat');
        $this->assertSame($rx->payload_encrypted, DB::table('prescriptions')->find($rx->id)->payload_encrypted);
        $this->assertSame($sale->recipient_encrypted, DB::table('sales')->find($sale->id)->recipient_encrypted);
        $this->assertStringNotContainsString('Pengembali Privat', DB::table('audit_entries')->pluck('details')->implode(' '));
    }

    public function test_special_approval_restores_only_returned_quantity_once_and_redemption_cap_stays_enforced(): void
    {
        [, $pharmacy,$rx,$product] = $this->workspace();
        $sale = $this->paidRedemption($pharmacy, $rx, $product);
        $record = $this->returned($pharmacy, $sale);
        $url = route('returns.restore_entitlement', [$pharmacy, $record->id]);
        $this->post($url, ['revision' => 1, 'reason' => 'Persetujuan privat'])->assertSessionHasErrors('return');
        $this->completeReturn($pharmacy, $record);
        $this->post($url, ['revision' => 2, 'reason' => 'Persetujuan privat'])->assertSessionHasErrors('return');
        $this->post($url, ['revision' => 3])->assertSessionHasErrors('reason');
        $before = DB::table('stock_movements')->count();
        $this->post($url, ['revision' => 3, 'reason' => 'Persetujuan privat'])->assertSessionHasNoErrors();
        $this->post($url, ['revision' => 3, 'reason' => 'Persetujuan privat'])->assertSessionHasNoErrors();
        $record = DB::table('sale_returns')->find($record->id);
        $this->assertSame(4, (int) $record->revision);
        $this->assertSame('Persetujuan privat', Crypt::decryptString($record->entitlement_reason_encrypted));
        $this->assertSame(70000, PrescriptionDispensing::remaining($pharmacy, DB::table('prescriptions')->find($rx->id))[0]['remaining']);
        $this->assertSame($before, DB::table('stock_movements')->count());
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'sale_return.entitlement_restored')->count());
        $this->post(route('dispensing.store', [$pharmacy, $rx->id]), ['request_key' => (string) Str::uuid(), 'verified' => 1, 'recipient_type' => 'self', 'items' => [['product_id' => $product->id, 'quantity' => 8]]])->assertSessionHasErrors('dispensing');
        $response = $this->post(route('dispensing.store', [$pharmacy, $rx->id]), ['request_key' => (string) Str::uuid(), 'verified' => 1, 'recipient_type' => 'self', 'items' => [['product_id' => $product->id, 'quantity' => 7]]])->assertSessionHasNoErrors();
        $next = DB::table('sales')->find(basename(parse_url($response->headers->get('Location'), PHP_URL_PATH)));
        $this->post(route('sales.pay', [$pharmacy, $next->id]), ['revision' => 1, 'payment_method' => 'cash', 'amount_received' => $next->total])->assertSessionHasNoErrors();
        $this->assertSame(0, PrescriptionDispensing::remaining($pharmacy, DB::table('prescriptions')->find($rx->id))[0]['remaining']);
    }

    public function test_unhanded_transactions_and_missing_representative_details_are_rejected(): void
    {
        [, $pharmacy,$rx,$product] = $this->workspace();
        $sale = $this->paidRedemption($pharmacy, $rx, $product, false);
        $movement = SaleReturns::allocations($pharmacy, $sale->id)->first();
        $data = ['request_key' => (string) Str::uuid(), 'reason' => 'Alasan', 'condition_checked' => 1, 'returner_type' => 'representative', 'items' => [['sale_movement_id' => $movement->id, 'quantity' => 1, 'disposition' => 'QUARANTINE']]];
        $url = route('returns.store', [$pharmacy, $sale->id]);
        $this->post($url, $data)->assertStatus(422);
        $this->get(route('returns.select', [$pharmacy, 'kind' => 'PRESCRIPTION']))->assertOk()->assertDontSee($sale->number);
        app(PrescriptionDispensing::class)->handover($pharmacy, auth()->id(), $sale->id);
        $this->post($url, $data)->assertSessionHasErrors(['returner_name', 'returner_relationship', 'returner_phone']);
        $this->assertDatabaseCount('sale_returns', 0);
    }

    public function test_non_clinical_return_staff_cannot_see_or_mutate_prescription_returns_and_restoration_needs_its_own_permission(): void
    {
        [$owner,$pharmacy,$rx,$product] = $this->workspace();
        $sale = $this->paidRedemption($pharmacy, $rx, $product);
        $record = $this->returned($pharmacy, $sale);
        $this->completeReturn($pharmacy, $record);
        $staff = User::factory()->create();
        $role = $pharmacy->roles()->create(['name' => 'Petugas retur umum', 'permissions' => ['returns.view', 'returns.create', 'returns.approve', 'returns.refund', 'returns.cancel', 'returns.print'], 'is_owner' => false]);
        $member = $pharmacy->memberships()->create(['user_id' => $staff->id, 'active' => true]);
        app(AccessManager::class)->syncRoles($member, collect([$role]));
        $this->actingAs($staff)->get(route('returns.index', $pharmacy))->assertOk()->assertDontSee($record->number);
        $this->get(route('returns.select', $pharmacy))->assertOk()->assertDontSee($sale->number);
        $this->get(route('returns.show', [$pharmacy, $record->id]))->assertForbidden();
        $this->get(route('returns.receipt', [$pharmacy, $record->id]))->assertForbidden();
        $this->post(route('returns.refund', [$pharmacy, $record->id]), ['revision' => 3, 'refund_method' => 'cash', 'refund_confirmed' => 1])->assertForbidden();
        $role->update(['permissions' => array_merge($role->permissions, ['dispensing.view'])]);
        $this->get(route('returns.show', [$pharmacy, $record->id]))->assertOk()->assertDontSee('data-confirm-title="Setujui pemulihan hak tebus"', false);
        $this->post(route('returns.restore_entitlement', [$pharmacy, $record->id]), ['revision' => 3, 'reason' => 'Coba'])->assertForbidden();
        $this->assertNull(DB::table('sale_returns')->find($record->id)->entitlement_restored_at);
        $foreign = app(AccessManager::class)->provision($owner->id, ['name' => 'Tenant lain']);
        $this->actingAs($owner)->get(route('returns.show', [$foreign, $record->id]))->assertNotFound();
    }

    public function test_cancellation_is_private_and_restock_does_not_automatically_restore_entitlement(): void
    {
        [, $pharmacy,$rx,$product] = $this->workspace();
        $sale = $this->paidRedemption($pharmacy, $rx, $product);
        $record = $this->returned($pharmacy, $sale);
        $this->post(route('returns.cancel', [$pharmacy, $record->id]), ['revision' => 1, 'reason' => 'Pembatalan privat'])->assertSessionHasNoErrors();
        $cancelled = DB::table('sale_returns')->find($record->id);
        $this->assertStringNotContainsString('Pembatalan privat', $cancelled->cancellation_reason);
        $this->assertSame('Pembatalan privat', Crypt::decryptString($cancelled->cancellation_encrypted));
        $this->get(route('returns.show', [$pharmacy, $record->id]))->assertOk()->assertSee('Pembatalan privat');
        $movement = SaleReturns::allocations($pharmacy, $sale->id)->first();
        $record = $this->returned($pharmacy, $sale, '4', ['items' => [['sale_movement_id' => $movement->id, 'quantity' => 4, 'disposition' => 'RESTOCK']]]);
        $this->completeReturn($pharmacy, $record);
        $this->assertEquals(4, DB::table('stock_movements')->where('type', 'SALE_RETURN')->sum('quantity'));
        $this->assertSame(60000, PrescriptionDispensing::remaining($pharmacy, DB::table('prescriptions')->find($rx->id))[0]['remaining']);
        $this->post(route('returns.restore_entitlement',[$pharmacy, $record->id]),['revision' => 3, 'reason' => 'Disetujui ulang'])->assertSessionHasNoErrors();
        $this->assertSame(100000,PrescriptionDispensing::remaining($pharmacy,DB::table('prescriptions')->find($rx->id))[0]['remaining']);
        $this->assertDatabaseHas('prescriptions',['id' => $rx->id, 'dispensing_status' => 'UNREDEEMED']);
        $this->assertEquals(4,DB::table('stock_movements')->where('type','SALE_RETURN')->sum('quantity'));
    }
}
