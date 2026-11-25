<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccessManager;
use App\Services\ClinicalMaster;
use App\Services\PrescriptionDispensing;
use App\Services\PrescriptionRecords;
use App\Services\Procurement;
use App\Services\VisitRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PrescriptionDispensingTest extends TestCase
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

    private function draft($pharmacy, $rx, $product, string $quantity = '4', array $extra = []): object
    {
        $response = $this->post(route('dispensing.store', [$pharmacy, $rx->id]), $extra + ['request_key' => (string) Str::uuid(), 'verified' => 1, 'recipient_type' => 'self', 'items' => [['product_id' => $product->id, 'quantity' => $quantity]]])->assertSessionHasNoErrors()->assertRedirect();

        return DB::table('sales')->find(basename(parse_url($response->headers->get('Location'), PHP_URL_PATH)));
    }

    private function pay($pharmacy, $sale): void
    {
        $this->post(route('sales.pay', [$pharmacy, $sale->id]), ['revision' => $sale->revision, 'payment_method' => 'cash', 'amount_received' => $sale->total])->assertSessionHasNoErrors();
    }

    public function test_cashier_saves_recipient_and_price_when_paying(): void
    {
        [, $pharmacy, $rx, $product] = $this->workspace();
        $response = $this->post(route('dispensing.store', [$pharmacy, $rx->id]), [
            'request_key' => (string) Str::uuid(), 'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ])->assertSessionHasNoErrors();
        $id = basename(parse_url($response->headers->get('Location'), PHP_URL_PATH));
        $sale = DB::table('sales')->find($id);
        $this->assertNull($sale->verified_by);
        $this->get(route('cashier.index', $pharmacy))->assertOk()->assertSee($sale->number)->assertSee('Pasien Resep');
        $this->get(route('sales.show', [$pharmacy, $id]))->assertOk()
            ->assertDontSee('Simpan & tinjau total', false)
            ->assertDontSee('Saya telah memeriksa obat', false)->assertSee('Simpan dan Cetak Struk', false);
        $this->post(route('pricing.store', [$pharmacy, 'tax']), ['code' => 'TAX-UJI', 'name' => 'PPN uji', 'type' => 'percentage', 'value' => '11', 'tax_mode' => 'exclusive', 'is_default' => 0])->assertSessionHasNoErrors();
        $tax = DB::table('pos_pricing_rules')->where('pharmacy_id', $pharmacy->id)->where('code', 'TAX-UJI')->value('id');
        $payment = ['revision' => 1, 'payment_method' => 'cash', 'amount_received' => 2000, 'tax_rule_id' => $tax,
            'recipient_type' => 'representative', 'recipient_name' => 'Pengambil', 'recipient_relationship' => 'Keluarga', 'recipient_phone' => '0812345678'];
        $this->post(route('sales.pay', [$pharmacy, $id]), $payment)->assertSessionHasErrors('amount_received');
        $this->assertNull(DB::table('sales')->find($id)->recipient_encrypted);
        $payment['amount_received'] = 2220;
        $payment['print_receipt'] = 1;
        $this->post(route('sales.pay', [$pharmacy, $id]), $payment)->assertSessionHasNoErrors()
            ->assertRedirect(route('sales.receipt', [$pharmacy, $id]))->assertSessionHas('print_receipt', true);
        $this->get(route('sales.receipt', [$pharmacy, $id]))->assertOk()->assertSee('data-auto-print-receipt', false);
        $this->get(route('sales.receipt', [$pharmacy, $id]))->assertOk()->assertDontSee('data-auto-print-receipt', false);
        $sale = DB::table('sales')->find($id);
        $this->assertSame('Pengambil', json_decode(Crypt::decryptString($sale->recipient_encrypted), true)['name']);
        $this->assertEquals(2220, $sale->total);
        $this->assertDatabaseHas('sales', ['id' => $id, 'status' => 'PAID']);
    }
    public function test_cashier_lists_service_and_sales_in_newest_order(): void
    {
        [$owner, $pharmacy, $rx, $product] = $this->workspace();
        $sale = $this->draft($pharmacy, $rx, $product);
        $billing = app(\App\Services\ServiceBilling::class);
        $tariff = $billing->tariff($pharmacy, $owner->id, ['name' => 'Konsultasi', 'price' => 10000, 'active' => 1]);
        $bill = $billing->create($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'visit_id' => $rx->visit_id, 'items' => [['tariff_id' => $tariff, 'quantity' => 1]]]);
        DB::table('service_bills')->where('id', $bill)->update(['created_at' => now()->subMinutes(5)]);
        DB::table('sales')->where('id', $sale->id)->update(['created_at' => now()]);
        $number = DB::table('service_bills')->where('id', $bill)->value('number');
        $this->get(route('cashier.index', $pharmacy))->assertOk()->assertSeeInOrder([$sale->number, $number]);
        DB::table('service_bills')->where('id', $bill)->update(['created_at' => now()->addMinute()]);
        $this->get(route('cashier.index', $pharmacy))->assertOk()->assertSeeInOrder([$number, $sale->number]);
    }
    public function test_saved_prescription_accepts_substitution_and_records_adjusted_dose(): void
    {
        [, $pharmacy, $rx, $product, $other] = $this->workspace();
        DB::table('prescriptions')->where('id', $rx->id)->update(['status' => 'DRAFT', 'finalized_at' => null, 'ready_at' => now()]);
        $this->get(route('dispensing.index', $pharmacy))->assertOk()->assertSee($rx->number);
        $url = route('dispensing.store', [$pharmacy, $rx->id]);
        $data = ['request_key' => (string) Str::uuid(), 'verified' => 1, 'recipient_type' => 'self', 'items' => [[
            'product_id' => $product->id, 'selected_product_id' => $other->id, 'quantity' => 4,
            'prescribed_quantity' => 2, 'adjusted_dose' => '2 tablet setiap minum', 'substitution_confirmed' => 1,
        ]]];
        $response = $this->post($url, $data)->assertSessionHasNoErrors();
        $sale = DB::table('sales')->where('id', basename(parse_url($response->headers->get('Location'), PHP_URL_PATH)))->firstOrFail();
        $line = DB::table('sale_items')->where('sale_id', $sale->id)->first();
        $this->assertSame($other->id, $line->product_id);
        $this->assertSame($product->id, $line->prescribed_product_id);
        $this->assertSame('2 tablet setiap minum', Crypt::decryptString($line->adjusted_dose_encrypted));
        $this->pay($pharmacy, $sale);
        $remaining = collect(PrescriptionDispensing::remaining($pharmacy, DB::table('prescriptions')->find($rx->id)))->keyBy('product_id');
        $this->assertSame(80000, $remaining[$product->id]['remaining']);
        $this->get(route('dispensing.labels', [$pharmacy, $sale->id]))->assertOk()->assertSee('Obat Kedua')->assertSee('2 tablet setiap minum');
    }
    public function test_expired_prescription_blocks_preparation_and_existing_draft_payment(): void
    {
        [, $pharmacy, $rx, $product] = $this->workspace();
        $sale = $this->draft($pharmacy, $rx, $product);
        DB::table('prescriptions')->where('id', $rx->id)->update(['valid_until' => now($pharmacy->timezone ?: 'Asia/Jakarta')->subDay()->toDateString()]);
        $this->get(route('patients.follow-up', $pharmacy))->assertOk()->assertSee($rx->number)->assertSee('Belum ditinjau');
        $this->post(route('patients.follow-up.update', [$pharmacy, $rx->id]), ['status' => 'CONTACTED', 'note' => 'Pasien sudah dihubungi'])->assertSessionHasNoErrors();
        $this->get(route('patients.follow-up', $pharmacy))->assertOk()->assertSee('Sudah dihubungi')->assertSee('Pasien sudah dihubungi');
        $this->assertStringNotContainsString('Pasien sudah dihubungi', DB::table('prescription_follow_ups')->where('prescription_id', $rx->id)->value('note_encrypted'));
        $this->assertStringNotContainsString('Pasien sudah dihubungi', DB::table('audit_entries')->pluck('details')->implode(' '));
        $this->post(route('patients.follow-up.update', [$pharmacy, $rx->id]), ['status' => 'DONE', 'note' => 'Konsultasi sudah dikonfirmasi'])->assertSessionHasNoErrors();
        $this->get(route('patients.follow-up', $pharmacy))->assertOk()->assertSee('Selesai');
        $this->post(route('patients.follow-up.update', [$pharmacy, $rx->id]), ['status' => 'PENDING', 'note' => 'Perlu ditinjau ulang'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('prescription_follow_ups', ['prescription_id' => $rx->id, 'status' => 'PENDING']);
        $this->get(route('dispensing.show', [$pharmacy, $rx->id]))->assertOk()->assertSee('Resep kedaluwarsa');
        $this->post(route('dispensing.store', [$pharmacy, $rx->id]), ['request_key' => (string) Str::uuid(), 'verified' => 1, 'recipient_type' => 'self', 'items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertSessionHasErrors('dispensing');
        $this->post(route('sales.pay', [$pharmacy, $sale->id]), ['revision' => $sale->revision, 'payment_method' => 'cash', 'amount_received' => $sale->total])->assertSessionHasErrors('dispensing');
    }

    public function test_partial_redemption_representative_fefo_and_handover_without_double_stock(): void
    {
        [, $pharmacy,$rx,$product,$other] = $this->workspace();
        $this->get(route('dispensing.index', $pharmacy))->assertOk()->assertSee($rx->number)->assertSee('Pasien Resep');
        $this->get(route('dispensing.show', [$pharmacy, $rx->id]))->assertOk()->assertSee('Sisa hak tebus');
        $sale = $this->draft($pharmacy, $rx, $product, '4', ['recipient_type' => 'representative', 'recipient_name' => 'Pengambil Privat', 'recipient_relationship' => 'Keluarga', 'recipient_phone' => '08123456789']);
        $this->assertStringNotContainsString('Pengambil Privat', $sale->recipient_encrypted);
        $this->assertSame('Pengambil Privat', json_decode(Crypt::decryptString($sale->recipient_encrypted), true)['name']);
        $this->assertSame(0, DB::table('stock_movements')->where('type', 'SALE')->count());
        $this->post(route('dispensing.handover', [$pharmacy, $sale->id]))->assertSessionHasErrors('dispensing');
        $this->get(route('dispensing.labels', [$pharmacy, $sale->id]))->assertStatus(422);
        $this->pay($pharmacy, $sale);
        $this->assertDatabaseHas('prescriptions', ['id' => $rx->id, 'dispensing_status' => 'PARTIAL']);
        $this->assertEquals(-4, DB::table('stock_movements')->where('type', 'SALE')->where('batch_number', 'EARLY')->sum('quantity'));
        $this->pay($pharmacy, $sale);
        $this->post(route('dispensing.handover', [$pharmacy, $sale->id]))->assertSessionHasNoErrors();
        $this->post(route('dispensing.handover', [$pharmacy, $sale->id]))->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'dispensing.handed_over')->count());
        $this->assertEquals(-4, DB::table('stock_movements')->where('type', 'SALE')->sum('quantity'));
        $this->get(route('dispensing.labels', [$pharmacy, $sale->id]))->assertOk()->assertSee('Pasien Resep')->assertSee('Sesudah makan')->assertDontSee('Obat Kedua');
        $this->get(route('sales.show', [$pharmacy, $sale->id]))->assertOk()->assertDontSee('Pengambil Privat')->assertDontSee('Edit keranjang');
        $this->get(route('sales.receipt', [$pharmacy, $sale->id]))->assertOk();
        $this->get(route('dispensing.show', [$pharmacy, $rx->id]))->assertOk()->assertSee('Pengambil Privat');
        $this->assertStringNotContainsString('Pengambil Privat', DB::table('audit_entries')->pluck('details')->implode(' '));
        $this->assertSame($rx->payload_encrypted, DB::table('prescriptions')->find($rx->id)->payload_encrypted);
        $this->assertDatabaseCount('examinations', 0);
        $this->assertDatabaseHas('visits', ['id' => $rx->visit_id, 'status' => 'IN_PROGRESS']);
    }

    public function test_remaining_entitlement_rechecked_at_payment_and_fractional_final_balance_is_exact(): void
    {
        [, $pharmacy,$rx,$product,$other] = $this->workspace();
        $first = $this->draft($pharmacy, $rx, $product, '6');
        $secondId = app(PrescriptionDispensing::class)->create($pharmacy, auth()->id(), $rx->id, ['request_key' => (string) Str::uuid(), 'verified' => 1, 'recipient_type' => 'self', 'items' => [['product_id' => $product->id, 'quantity' => '6']]]);
        $second = DB::table('sales')->find($secondId);
        $this->pay($pharmacy, $first);
        $this->post(route('sales.pay', [$pharmacy, $second->id]), ['revision' => 1, 'payment_method' => 'cash', 'amount_received' => $second->total])->assertSessionHasErrors('dispensing');
        $this->assertDatabaseHas('sales', ['id' => $second->id, 'status' => 'DRAFT']);
        $this->assertEquals(-6, DB::table('stock_movements')->where('type', 'SALE')->sum('quantity'));
        $this->post(route('sales.cancel', [$pharmacy, $second->id]), ['reason' => 'Sisa berubah'])->assertSessionHasNoErrors();
        $id = app(PrescriptionDispensing::class)->create($pharmacy, auth()->id(), $rx->id, ['request_key' => (string) Str::uuid(), 'verified' => 1, 'recipient_type' => 'self', 'items' => [['product_id' => $product->id, 'quantity' => '4'], ['product_id' => $other->id, 'quantity' => '2.1234']]]);
        $this->pay($pharmacy, DB::table('sales')->find($id));
        $this->assertDatabaseHas('prescriptions', ['id' => $rx->id, 'dispensing_status' => 'COMPLETE']);
        $this->assertEquals('2.1234', DB::table('sale_items')->where('sale_id', $id)->where('product_id', $other->id)->value('quantity'));
        $this->assertSame(0, PrescriptionDispensing::remaining($pharmacy, DB::table('prescriptions')->find($rx->id))[1]['remaining']);
        $this->assertEquals(-10, DB::table('stock_movements')->where('type', 'SALE')->where('product_id', $product->id)->sum('quantity'));
        $this->assertEquals(-2.1234, DB::table('stock_movements')->where('type', 'SALE')->where('product_id', $other->id)->sum('quantity'));
    }

    public function test_validation_idempotency_tampering_and_unpaid_cancel_do_not_consume_entitlement(): void
    {
        [, $pharmacy,$rx,$product,$other] = $this->workspace();
        $url = route('dispensing.store', [$pharmacy, $rx->id]);
        $data = ['request_key' => (string) Str::uuid(), 'verified' => 1, 'recipient_type' => 'self', 'items' => [['product_id' => $product->id, 'quantity' => '11']]];
        $this->post($url, $data)->assertSessionHasErrors('dispensing');
        $data['items'][0]['quantity'] = '0';
        $this->post($url, $data)->assertSessionHasErrors('dispensing');
        $data['items'][0]['quantity'] = '1.00001';
        $this->post($url, $data)->assertSessionHasErrors('items.0.quantity');
        $data['items'][0]['quantity'] = '1';
        $this->post($url, array_replace($data, ['recipient_type' => 'representative']))->assertSessionHasErrors(['recipient_name', 'recipient_relationship', 'recipient_phone']);
        $this->post($url, array_replace($data, ['verified' => 0]))->assertSessionHasErrors('verified');
        $this->post($url, $data)->assertSessionHasNoErrors();
        $this->post($url, $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('sales', 1);
        $sale = DB::table('sales')->first();
        $this->put(route('sales.update', [$pharmacy, $sale->id]), ['revision' => 1, 'items' => [['product_id' => $other->id, 'quantity' => 1, 'unit_type' => 'base']]])->assertSessionHasErrors('items');
        $this->get(route('sales.edit', [$pharmacy, $sale->id]))->assertStatus(422);
        $this->post(route('sales.cancel', [$pharmacy, $sale->id]), ['reason' => 'Batal mengambil'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('prescriptions', ['id' => $rx->id, 'dispensing_status' => 'UNREDEEMED']);
        $this->assertSame(100000, PrescriptionDispensing::remaining($pharmacy, $rx)[0]['remaining']);
        $this->assertSame(0, DB::table('stock_movements')->where('type', 'SALE')->count());
    }

    public function test_stock_and_price_failures_roll_back_and_tenant_and_permissions_are_enforced(): void
    {
        [$owner,$pharmacy,$rx,$product] = $this->workspace();
        $sale = $this->draft($pharmacy, $rx, $product, '4');
        $product->update(['selling_price' => 1100]);
        $this->post(route('sales.pay', [$pharmacy, $sale->id]), ['revision' => 1, 'payment_method' => 'cash', 'amount_received' => $sale->total])->assertSessionHasErrors('items');
        $product->update(['selling_price' => 1000]);
        DB::table('stock_movements')->update(['expires_on' => now()->toDateString()]);
        $this->post(route('sales.pay', [$pharmacy, $sale->id]), ['revision' => 1, 'payment_method' => 'cash', 'amount_received' => $sale->total])->assertSessionHasErrors('items');
        $this->assertSame(0, DB::table('stock_movements')->where('type', 'SALE')->count());
        $this->assertDatabaseHas('prescriptions', ['id' => $rx->id, 'dispensing_status' => 'UNREDEEMED']);
        $foreign = app(AccessManager::class)->provision($owner->id, ['name' => 'Tenant lain']);
        $this->get(route('dispensing.show', [$foreign, $rx->id]))->assertNotFound();
        $this->get(route('dispensing.labels', [$foreign, $sale->id]))->assertNotFound();
        $staff = User::factory()->create();
        $role = $pharmacy->roles()->create(['name' => 'Kasir tanpa farmasi', 'permissions' => ['sales.view', 'sales.create', 'sales.pay', 'sales.cancel', 'sales.print'], 'is_owner' => false]);
        $member = $pharmacy->memberships()->create(['user_id' => $staff->id, 'active' => true]);
        app(AccessManager::class)->syncRoles($member, collect([$role]));
        $this->actingAs($staff)->get(route('dispensing.index', $pharmacy))->assertForbidden();
        $this->get(route('cashier.index', $pharmacy))->assertOk()->assertDontSee($sale->number)->assertSee('Transaksi resep memerlukan izin Farmasi: Lihat');
        $this->get(route('sales.index', $pharmacy))->assertOk()->assertDontSee($sale->number);
        $this->get(route('sales.show', [$pharmacy, $sale->id]))->assertForbidden();
        $this->post(route('sales.pay', [$pharmacy, $sale->id]), ['revision' => 1, 'payment_method' => 'cash', 'amount_received' => $sale->total])->assertForbidden();
        $this->post(route('sales.cancel', [$pharmacy, $sale->id]), ['reason' => 'Coba'])->assertForbidden();
    }

    public function test_only_final_prescribed_products_can_be_prepared_and_read_only_staff_cannot_mutate(): void
    {
        [$owner,$pharmacy,$rx,$product] = $this->workspace();
        $url = route('dispensing.store', [$pharmacy, $rx->id]);
        $data = ['request_key' => (string) Str::uuid(), 'verified' => 1, 'recipient_type' => 'self', 'items' => [['product_id' => (string) Str::uuid(), 'quantity' => 1]]];
        $this->post($url, $data)->assertSessionHasErrors('dispensing');
        $data['items'][0]['product_id'] = $product->id;
        DB::table('prescriptions')->where('id', $rx->id)->update(['status' => 'DRAFT', 'ready_at' => null]);
        $this->post($url, $data)->assertSessionHasErrors('dispensing');
        $this->get(route('dispensing.index', $pharmacy))->assertOk()->assertDontSee($rx->number);
        DB::table('prescriptions')->where('id', $rx->id)->update(['status' => 'FINAL']);
        $product->update(['base_unit' => 'Unit berubah']);
        $this->post($url, $data)->assertSessionHasErrors('dispensing');
        $product->update(['base_unit' => 'Tablet']);
        $sale = $this->draft($pharmacy, $rx, $product);
        $staff = User::factory()->create();
        $role = $pharmacy->roles()->create(['name' => 'Baca farmasi', 'permissions' => ['dispensing.view', 'sales.view'], 'is_owner' => false]);
        $member = $pharmacy->memberships()->create(['user_id' => $staff->id, 'active' => true]);
        app(AccessManager::class)->syncRoles($member, collect([$role]));
        $this->actingAs($staff)->get(route('dispensing.show', [$pharmacy, $rx->id]))->assertOk()->assertDontSee('Alergi master pasien saat ini')->assertDontSee('Simpan & lanjut ke pembayaran');
        $this->get(route('prescriptions.show', [$pharmacy, $rx->visit_id]))->assertForbidden();
        $this->post($url, $data)->assertForbidden();
        $this->post(route('dispensing.handover', [$pharmacy, $sale->id]))->assertForbidden();
        $this->get(route('dispensing.labels', [$pharmacy, $sale->id]))->assertForbidden();
        $this->assertDatabaseCount('sales', 1);
        $this->assertSame(0, DB::table('stock_movements')->where('type', 'SALE')->count());
    }
    public function test_combined_substitution_records_adjusted_dose_and_prescribed_entitlement(): void
    {
        [, $pharmacy, $rx, $product, $other] = $this->workspace();
        $product->ingredients()->update(['amount' => '0.5']);
        $other->ingredients()->update(['amount' => '1']);
        $this->get(route('combined-dispensing.index', [$pharmacy, 'selected' => [$rx->id]]))->assertOk()
            ->assertSee('Produk diserahkan')->assertSee('Dosis penyesuaian')->assertSee('data-check-ratio="2"', false);
        $input = ['request_key' => (string) Str::uuid(), 'payment_method' => 'cash',
            'amount_received' => 8000, 'expected_total' => 8000, 'print_receipt' => 1,
            'items' => [['prescription_id' => $rx->id, 'product_id' => $product->id, 'selected_product_id' => $other->id,
                'quantity' => 4, 'prescribed_quantity' => 7, 'adjusted_dose' => 'Setengah tablet sesuai resep', 'substitution_confirmed' => 1]]];
        $oldInput = ['items' => [
            ['prescription_id' => $rx->id, 'product_id' => $other->id, 'quantity' => '1'],
            ['prescription_id' => $rx->id, 'product_id' => $product->id, 'selected_product_id' => $other->id,
                'quantity' => '4', 'prescribed_quantity' => '7', 'adjusted_dose' => 'Setengah tablet sesuai resep', 'substitution_confirmed' => 1],
        ]];
        $this->withSession(['_old_input' => $oldInput])->get(route('combined-dispensing.index', [$pharmacy, 'selected' => [$rx->id]]))
            ->assertOk()->assertViewHas('oldRows', fn ($rows) => $rows->get($rx->id.'|'.$product->id)['prescribed_quantity'] === '7');
        $url = route('combined-dispensing.pay', $pharmacy);
        $missing = $input; unset($missing['items'][0]['substitution_confirmed']);
        $this->post($url, $missing)->assertSessionHasErrors('dispensing');
        $tooMuch = $input; $tooMuch['items'][0]['prescribed_quantity'] = 11;
        $this->post($url, $tooMuch)->assertSessionHasErrors('dispensing');
        $other->ingredients()->update(['name' => 'Zat berbeda']);
        $this->post($url, $input)->assertSessionHasErrors('dispensing');
        $other->ingredients()->update(['name' => 'Zat uji']);
        $stale = $input; $stale['expected_total'] = 4000;
        $this->post($url, $stale)->assertSessionHasErrors('amount_received');
        $this->assertDatabaseCount('sales', 0);
        $response = $this->post($url, $input)->assertSessionHasNoErrors();
        $sale = DB::table('sales')->where('request_key', $input['request_key'])->first();
        $response->assertRedirect(route('sales.receipt', [$pharmacy, $sale->id]));
        $line = DB::table('sale_items')->where('sale_id', $sale->id)->first();
        $this->assertSame($product->id, $line->prescribed_product_id);
        $this->assertSame($other->id, $line->product_id);
        $this->assertEquals(7, $line->prescribed_quantity);
        $this->assertEquals(4, $line->base_quantity);
        $this->assertSame('Setengah tablet sesuai resep', Crypt::decryptString($line->adjusted_dose_encrypted));
        $this->assertEquals(8000, $sale->total);
        $remaining = collect(PrescriptionDispensing::remaining($pharmacy, DB::table('prescriptions')->find($rx->id)))->keyBy('product_id');
        $this->assertSame(30000, $remaining[$product->id]['remaining']);
        $this->get(route('dispensing.labels', [$pharmacy, $sale->id]))->assertOk()->assertSee('Obat Kedua')
            ->assertSee('Setengah tablet sesuai resep')->assertSee('Zat uji 1 mg / 1 Tablet');
        $this->post($url, $input)->assertSessionHasNoErrors();
        $changedQuantity = $input; $changedQuantity['items'][0]['prescribed_quantity'] = 8;
        $this->post($url, $changedQuantity)->assertStatus(422);
        $changed = $input; $changed['items'][0]['adjusted_dose'] = 'Dosis berbeda';
        $this->post($url, $changed)->assertStatus(422);
        $this->assertDatabaseCount('sales', 1);
        $this->assertEquals(-4, DB::table('stock_movements')->where('type', 'SALE')->where('product_id', $other->id)->sum('quantity'));
    }
    public function test_combined_payment_is_atomic_and_idempotent(): void
    {
        [, $pharmacy, $rx, $product] = $this->workspace();
        $this->post(route('pricing.store', [$pharmacy, 'discount']), ['code' => 'COMB-DISC', 'name' => 'Diskon uji', 'type' => 'percentage', 'value' => '10', 'is_default' => 0])->assertSessionHasNoErrors();
        $this->post(route('pricing.store', [$pharmacy, 'tax']), ['code' => 'COMB-TAX', 'name' => 'PPN uji', 'type' => 'percentage', 'value' => '11', 'tax_mode' => 'exclusive', 'is_default' => 0])->assertSessionHasNoErrors();
        $discount = DB::table('pos_pricing_rules')->where('pharmacy_id', $pharmacy->id)->where('code', 'COMB-DISC')->value('id');
        $tax = DB::table('pos_pricing_rules')->where('pharmacy_id', $pharmacy->id)->where('code', 'COMB-TAX')->value('id');
        $url = route('combined-dispensing.pay', $pharmacy);
        $input = ['request_key' => (string) Str::uuid(), 'verified' => 1, 'payment_method' => 'cash',
            'amount_received' => 100, 'expected_total' => 3996, 'print_receipt' => 1, 'discount_rule_id' => $discount, 'tax_rule_id' => $tax,
            'items' => [['prescription_id' => $rx->id, 'product_id' => $product->id, 'quantity' => 4]]];
        $this->get(route('combined-dispensing.index', [$pharmacy, 'selected' => [$rx->id]]))->assertOk()
            ->assertSee('Nominal diterima')->assertSee('Simpan dan Cetak Struk');
        $this->post($url, $input)->assertSessionHasErrors('amount_received');
        $this->assertDatabaseCount('sales', 0);
        $this->assertSame(0, DB::table('stock_movements')->where('type', 'SALE')->count());
        $input['amount_received'] = 5000;
        $input['expected_total'] = 100;
        $this->post($url, $input)->assertSessionHasErrors('amount_received');
        $this->assertDatabaseCount('sales', 0);
        $input['expected_total'] = 3996;
        $input['amount_received'] = 3996;
        $response = $this->post($url, $input)->assertSessionHasNoErrors()->assertSessionHas('print_receipt', true);
        $sale = DB::table('sales')->where('request_key', $input['request_key'])->first();
        $response->assertRedirect(route('sales.receipt', [$pharmacy, $sale->id]));
        $this->assertSame('PAID', $sale->status);
        $this->assertEquals(400, $sale->discount);
        $this->assertEquals(396, $sale->tax_amount);
        $this->assertEquals(3996, $sale->total);
        $this->assertSame('PARTIAL', DB::table('prescriptions')->where('id', $rx->id)->value('dispensing_status'));
        $this->post($url, $input)->assertSessionHasNoErrors()->assertRedirect(route('sales.receipt', [$pharmacy, $sale->id]));
        $this->assertDatabaseCount('sales', 1);
        $this->assertEquals(-4, DB::table('stock_movements')->where('type', 'SALE')->sum('quantity'));
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'sale.paid')->count());
    }
    public function test_combined_payment_area_is_hidden_until_a_prescription_is_selected(): void
    {
        [, $pharmacy, $rx] = $this->workspace();
        $empty = $this->get(route('combined-dispensing.index', $pharmacy))->assertOk()->assertSee('Belum ada resep dipilih');
        $this->assertSame(1, preg_match('/<fieldset\b[^>]*data-combined-payment-area[^>]*>/', $empty->getContent(), $emptyMatch));
        $this->assertStringContainsString('hidden', $emptyMatch[0]);
        $this->assertStringContainsString('disabled', $emptyMatch[0]);
        $selected = $this->get(route('combined-dispensing.index', [$pharmacy, 'selected' => [$rx->id]]))->assertOk()->assertSee('1 resep')->assertSee('data-combined-prescription', false);
        $this->assertSame(1, preg_match('/<fieldset\b[^>]*data-combined-payment-area[^>]*>/', $selected->getContent(), $selectedMatch));
        $this->assertStringNotContainsString('hidden', $selectedMatch[0]);
        $this->assertStringNotContainsString('disabled', $selectedMatch[0]);
    }
    public function test_combined_list_and_submission_only_accept_unredeemed_prescriptions(): void
    {
        [, $pharmacy, $rx, $product, $other] = $this->workspace();
        $this->get(route('combined-dispensing.index', $pharmacy))->assertOk()
            ->assertViewHas('results', fn ($rows) => $rows->contains('id', $rx->id));
        $sale = $this->draft($pharmacy, $rx, $product, '4');
        $this->pay($pharmacy, $sale);
        $this->assertDatabaseHas('prescriptions', ['id' => $rx->id, 'dispensing_status' => 'PARTIAL']);
        $this->get(route('combined-dispensing.index', [$pharmacy, 'selected' => [$rx->id]]))->assertOk()
            ->assertViewHas('results', fn ($rows) => ! $rows->contains('id', $rx->id))
            ->assertViewHas('selected', [])
            ->assertViewHas('records', fn ($rows) => $rows->isEmpty());
        $this->post(route('combined-dispensing.store', $pharmacy), [
            'request_key' => (string) Str::uuid(), 'verified' => 1,
            'items' => [['prescription_id' => $rx->id, 'product_id' => $product->id, 'quantity' => 1]],
        ])->assertSessionHasErrors('dispensing');
        $id = app(PrescriptionDispensing::class)->create($pharmacy, auth()->id(), $rx->id, [
            'request_key' => (string) Str::uuid(), 'verified' => 1, 'recipient_type' => 'self',
            'items' => [['product_id' => $product->id, 'quantity' => 6], ['product_id' => $other->id, 'quantity' => '2.1234']],
        ]);
        $this->pay($pharmacy, DB::table('sales')->find($id));
        $this->assertDatabaseHas('prescriptions', ['id' => $rx->id, 'dispensing_status' => 'COMPLETE']);
        $this->get(route('combined-dispensing.index', $pharmacy))->assertOk()
            ->assertViewHas('results', fn ($rows) => ! $rows->contains('id', $rx->id));
    }
    public function test_combined_sale_preserves_each_patient_prescription_and_remaining_quantity(): void
    {
        [$owner, $pharmacy, $rx, $product] = $this->workspace();
        $master = app(ClinicalMaster::class);
        $patient = $master->savePatient($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'name' => 'Pasien Kedua', 'birth_date' => '1991-01-01', 'sex' => 'male']);
        $visit = app(VisitRegistration::class)->save($pharmacy, $owner->id, ['request_key' => (string) Str::uuid(), 'patient_id' => $patient, 'doctor_id' => DB::table('visits')->where('id', $rx->visit_id)->value('doctor_id'), 'type' => 'new', 'complaint' => 'Keluhan']);
        DB::table('visits')->where('id', $visit)->update(['status' => 'IN_PROGRESS', 'started_at' => now(), 'revision' => 2]);

        $records = app(PrescriptionRecords::class);
        $other = $records->save($pharmacy, $owner->id, $visit, 0, ['valid_until' => now()->addDays(30)->toDateString(), 'items' => [['product_id' => $product->id, 'quantity' => 8, 'dose' => '2 tablet', 'frequency' => '1 kali', 'instructions' => 'Sebelum makan']]]);
        $records->finalize($pharmacy, $owner->id, $visit, 1, $other);
        $this->post(route('pricing.store', [$pharmacy, 'discount']), ['code' => 'DISC-TEST', 'name' => 'Diskon uji', 'type' => 'percentage', 'value' => '10', 'is_default' => 0])->assertSessionHasNoErrors();
        $this->post(route('pricing.store', [$pharmacy, 'tax']), ['code' => 'TAX-TEST', 'name' => 'PPN uji', 'type' => 'percentage', 'value' => '11', 'tax_mode' => 'exclusive', 'is_default' => 0])->assertSessionHasNoErrors();
        $discount = DB::table('pos_pricing_rules')->where('pharmacy_id', $pharmacy->id)->where('code', 'DISC-TEST')->value('id');
        $tax = DB::table('pos_pricing_rules')->where('pharmacy_id', $pharmacy->id)->where('code', 'TAX-TEST')->value('id');
        $input = ['request_key' => (string) Str::uuid(), 'verified' => 1, 'discount_rule_id' => $discount, 'tax_rule_id' => $tax, 'items' => [
            ['prescription_id' => $rx->id, 'product_id' => $product->id, 'quantity' => 3],
            ['prescription_id' => $other, 'product_id' => $product->id, 'quantity' => 2],
        ]];
        $this->get(route('combined-dispensing.index', $pharmacy))->assertOk()->assertSee('Resep dipilih')->assertDontSee('Nama pengambil')->assertSee('Pembayaran')->assertSee('Belum ada resep dipilih');
        $this->get(route('combined-dispensing.index', [$pharmacy, 'selected' => [$rx->id, $other]]))->assertOk()->assertSee('Pasien Resep')->assertSee('Pasien Kedua');
        $tooMuch = $input; $tooMuch['items'][1]['quantity'] = 9;
        $this->post(route('combined-dispensing.store', $pharmacy), $tooMuch)->assertSessionHasErrors('dispensing');
        DB::table('prescriptions')->where('id', $other)->update(['valid_until' => now()->subDay()->toDateString()]);
        $this->post(route('combined-dispensing.store', $pharmacy), $input)->assertSessionHasErrors('dispensing');
        DB::table('prescriptions')->where('id', $other)->update(['valid_until' => now()->addDays(30)->toDateString()]);
        $response = $this->post(route('combined-dispensing.store', $pharmacy), $input)->assertSessionHasNoErrors()->assertRedirect();
        $sale = DB::table('sales')->find(basename(parse_url($response->headers->get('Location'), PHP_URL_PATH)));
        $this->post(route('combined-dispensing.store', $pharmacy), $input)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('sales', 1);
        $this->assertNull($sale->recipient_encrypted);
        $altered = $input; $altered['items'][0]['quantity'] = 1;
        $this->post(route('combined-dispensing.store', $pharmacy), $altered)->assertStatus(422);
        $this->assertSame(2, DB::table('sale_items')->where('sale_id', $sale->id)->count());
        $this->pay($pharmacy, $sale);
        $this->get(route('sales.index', $pharmacy))->assertOk()->assertViewHas('sales', function ($entries) use ($sale, $rx, $other) {
            $rows = $entries->getCollection()->where('id', $sale->id);
            return $rows->count() === 2 && $rows->pluck('rx_id')->sort()->values()->all() === collect([$rx->id, $other])->sort()->values()->all()
                && $rows->sum(fn ($row) => \App\Support\Decimal::scaled($row->entry_total, 2)) === \App\Support\Decimal::scaled($sale->total, 2);
        });
        $this->get(route('sales.index', [$pharmacy, 'q' => 'Pasien Kedua']))->assertOk()->assertViewHas('sales', fn ($entries) => $entries->total() === 1 && $entries->first()->rx_id === $other);
        $this->assertDatabaseCount('sales', 1);
        $this->assertEquals(5, -DB::table('stock_movements')->where('type', 'SALE')->sum('quantity'));
        $this->assertEquals(7, (float) \App\Support\Decimal::format(PrescriptionDispensing::remaining($pharmacy, $rx)[0]['remaining'], 4));
        $this->assertEquals(6, (float) \App\Support\Decimal::format(PrescriptionDispensing::remaining($pharmacy, DB::table('prescriptions')->find($other))[0]['remaining'], 4));
        foreach ([$rx->id, $other] as $id) $this->get(route('dispensing.show', [$pharmacy, $id]))->assertOk()->assertSee($sale->number);
        $this->get(route('dispensing.labels', [$pharmacy, $sale->id]))->assertOk()->assertSee('Pasien Resep')->assertSee('Pasien Kedua')->assertSee('Sebelum makan');
        $this->get(route('patients.show', [$pharmacy, $patient]))->assertOk()->assertSee($sale->number);
        $this->get(route('sales.show', [$pharmacy, $sale->id]))->assertOk()->assertSee('Pasien Kedua');
        $this->post(route('dispensing.handover', [$pharmacy, $sale->id]))->assertSessionHasNoErrors();
        $movement = DB::table('stock_movements as m')->join('sale_items as i', 'i.id', '=', 'm.sale_item_id')->where('i.sale_id', $sale->id)->where('i.prescription_id', $other)->select('m.id')->first();
        $returnResponse = $this->post(route('returns.store', [$pharmacy, $sale->id]), ['request_key' => (string) Str::uuid(), 'reason' => 'Retur', 'condition_checked' => 1, 'returner_type' => 'representative', 'returner_name' => 'Pengembali', 'returner_relationship' => 'Keluarga', 'returner_phone' => '0812', 'items' => [['sale_movement_id' => $movement->id, 'quantity' => 1, 'disposition' => 'QUARANTINE']]])->assertSessionHasNoErrors();
        $return = basename(parse_url($returnResponse->headers->get('Location'), PHP_URL_PATH));
        $this->get(route('returns.show', [$pharmacy, $return]))->assertOk()->assertSee('Pasien Kedua');
        $this->post(route('returns.approve', [$pharmacy, $return]), ['revision' => 1])->assertSessionHasNoErrors();
        $this->post(route('returns.refund', [$pharmacy, $return]), ['revision' => 2, 'refund_method' => 'cash', 'refund_confirmed' => 1])->assertSessionHasNoErrors();
        $this->post(route('returns.restore_entitlement', [$pharmacy, $return]), ['revision' => 3, 'reason' => 'Disetujui'])->assertSessionHasNoErrors();
        $this->assertEquals(7, (float) \App\Support\Decimal::format(PrescriptionDispensing::remaining($pharmacy, $rx)[0]['remaining'], 4));
        $this->assertEquals(7, (float) \App\Support\Decimal::format(PrescriptionDispensing::remaining($pharmacy, DB::table('prescriptions')->find($other))[0]['remaining'], 4));
    }

    public function test_combined_list_is_visible_searchable_and_expired_prescriptions_cannot_be_selected(): void
    {
        [, $pharmacy, $rx] = $this->workspace();
        $url = route('combined-dispensing.index', $pharmacy);
        $this->get($url)->assertOk()->assertSee($rx->number)->assertSee('Tanggal resep');
        $this->get(route('combined-dispensing.index', [$pharmacy, 'q' => 'tidak-ada-resep-xyz']))->assertOk()->assertDontSee($rx->number)->assertSee('Tidak ada resep yang sesuai');
        DB::table('prescriptions')->where('id', $rx->id)->update(['valid_until' => now($pharmacy->timezone ?: 'Asia/Jakarta')->subDay()->toDateString()]);
        $this->get($url)->assertOk()->assertSee($rx->number)->assertSee('Kedaluwarsa')->assertSee('Tidak dapat ditambahkan')->assertDontSee('data-combined-add', false);
    }

    public function test_combined_draft_rechecks_remaining_at_payment(): void
    {
        [$owner, $pharmacy, $rx, $product] = $this->workspace();
        $service = app(PrescriptionDispensing::class);
        $data = ['verified' => 1, 'recipient_name' => 'Pengambil', 'recipient_relationship' => 'Keluarga', 'recipient_phone' => '0812', 'items' => [['prescription_id' => $rx->id, 'product_id' => $product->id, 'quantity' => 7]]];
        $first = $service->createCombined($pharmacy, $owner->id, $data + ['request_key' => (string) Str::uuid()]);
        $second = $service->createCombined($pharmacy, $owner->id, $data + ['request_key' => (string) Str::uuid()]);
        $this->pay($pharmacy, DB::table('sales')->find($first));
        $this->post(route('sales.pay', [$pharmacy, $second]), ['revision' => 1, 'payment_method' => 'cash', 'amount_received' => DB::table('sales')->find($second)->total])->assertSessionHasErrors('dispensing');
        $this->assertEquals(7, -DB::table('stock_movements')->where('type', 'SALE')->sum('quantity'));
        $this->assertDatabaseHas('sales', ['id' => $second, 'status' => 'DRAFT']);
    }

}
