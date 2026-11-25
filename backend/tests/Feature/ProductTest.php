<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccessManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): array
    {
        $owner = User::factory()->create();
        $pharmacy = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek Pengujian']);

        return [$owner, $pharmacy];
    }

    public function test_selling_units_can_have_independent_prices_and_validate_conversion(): void
    {
        [$owner, $pharmacy] = $this->workspace();
        $units = [['unit' => 'Strip', 'conversion' => '10', 'price' => '4000'], ['unit' => 'Box', 'conversion' => '100', 'price' => '35000']];
        $this->actingAs($owner)->post(route('products.store', $pharmacy), $this->payload(['selling_units' => $units]))->assertSessionHasNoErrors();
        $product = $pharmacy->products()->firstOrFail();
        $this->assertSame($units, $product->selling_units);
        $this->get(route('products.edit', [$pharmacy, $product]))->assertOk()->assertSee('selling_units[1][price]', false);
        $units[0]['conversion'] = 0;
        $this->put(route('products.update', [$pharmacy, $product]), $this->payload(['selling_units' => $units]))->assertSessionHasErrors('selling_units.0.conversion');
        $this->assertEquals(10, $product->fresh()->selling_units[0]['conversion']);
    }

    private function payload(array $changes = []): array
    {
        return array_replace(['sku' => 'PCM-A-500', 'name' => 'Paracetamol', 'brand' => 'Brand A', 'generic_name' => 'Paracetamol',
            'category' => 'Obat', 'dosage_form' => 'Tablet', 'strength' => '500 mg', 'base_unit' => 'Tablet', 'purchase_unit' => 'Box',
            'purchase_conversion' => 100, 'purchase_price' => 20000, 'selling_price' => 500, 'minimum_stock' => 10, 'maximum_stock' => 200,
            'requires_prescription' => 0], $changes);
    }

    public function test_psychotropic_reporting_selection_can_be_saved_and_updated(): void
    {
        [$owner, $pharmacy] = $this->workspace();
        $this->actingAs($owner)->post(route('products.store', $pharmacy), $this->payload(['is_psychotropic' => 1]))->assertSessionHasNoErrors();
        $product = $pharmacy->products()->firstOrFail();
        $this->assertTrue($product->is_psychotropic);
        $this->get(route('products.edit', [$pharmacy, $product]))->assertOk()->assertSee('Pelaporan psikotropika');
        $this->put(route('products.update', [$pharmacy, $product]), $this->payload(['is_psychotropic' => 0]))->assertSessionHasNoErrors();
        $this->assertFalse($product->fresh()->is_psychotropic);
    }

    public function test_combination_product_saves_each_ingredient_and_can_be_searched_and_edited(): void
    {
        [$owner, $pharmacy] = $this->workspace();
        $composition = [
            ['name' => 'Paracetamol', 'amount' => '250', 'unit' => 'mg', 'basis_quantity' => '1', 'basis_unit' => 'Tablet'],
            ['name' => 'Kafein', 'amount' => '25', 'unit' => 'mg', 'basis_quantity' => '1', 'basis_unit' => 'Tablet'],
        ];
        $payload = $this->payload(['ingredients_present' => '1', 'ingredients' => $composition]);
        $this->actingAs($owner)->post(route('products.store', $pharmacy), $payload)->assertSessionHasNoErrors()->assertRedirect();
        $product = $pharmacy->products()->firstOrFail();
        $this->assertSame(['Paracetamol', 'Kafein'], $product->ingredients->pluck('name')->all());
        $this->assertSame('250.0000', $product->ingredients[0]->amount);
        $this->assertSame('1.0000', $product->ingredients[0]->basis_quantity);
        $this->get(route('products.index', [$pharmacy, 'q' => 'kAfEiN']))->assertOk()->assertSee('Brand A')->assertSee('Kafein');
        $this->get(route('products.edit', [$pharmacy, $product]))->assertOk()->assertSee('value="25.0000"', false);
        $payload['ingredients'] = [3 => $composition[1]];
        $this->put(route('products.update', [$pharmacy, $product]), $payload)->assertSessionHasNoErrors();
        $this->assertSame(['Kafein'], $product->fresh()->ingredients->pluck('name')->all());
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('stock_movements', 0);
        unset($payload['ingredients']);
        $this->put(route('products.update', [$pharmacy, $product]), $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('product_ingredients', 0);
    }

    public function test_ingredient_validation_rejects_incomplete_duplicate_and_invalid_composition_without_changes(): void
    {
        [$owner, $pharmacy] = $this->workspace();
        $this->actingAs($owner)->post(route('products.store', $pharmacy), $this->payload(['ingredients' => [['name' => 'Zat awal']]]))->assertSessionHasNoErrors();
        $product = $pharmacy->products()->firstOrFail();
        $invalidRows = [
            [['name' => '', 'amount' => '100']],
            [['name' => 'Paracetamol', 'amount' => '100']],
            [['name' => 'Paracetamol', 'amount' => '0', 'unit' => 'mg', 'basis_quantity' => '1', 'basis_unit' => 'Tablet']],
            [['name' => 'Paracetamol', 'amount' => '100', 'unit' => 'invalid', 'basis_quantity' => '1', 'basis_unit' => 'Tablet']],
            [['name' => 'Paracetamol'], ['name' => ' paracetamol ']],
            [['name' => 'Paracetamol', 'product_id' => $product->id]],
            array_fill(0, 21, ['name' => 'Kandungan']),
        ];
        foreach ($invalidRows as $rows) {
            $this->put(route('products.update', [$pharmacy, $product]), $this->payload(['ingredients' => $rows]))->assertSessionHasErrors();
            $this->assertSame(['Zat awal'], $product->fresh()->ingredients->pluck('name')->all());
        }
        $this->put(route('products.update', [$pharmacy, $product]), $this->payload())->assertSessionHasNoErrors();
        $this->assertSame(['Zat awal'], $product->fresh()->ingredients->pluck('name')->all());
        $this->put(route('products.update', [$pharmacy, $product]), $this->payload(['ingredients_present' => '1', 'ingredients' => [['name' => ' ', 'amount' => '', 'unit' => '', 'basis_quantity' => '', 'basis_unit' => '']]]))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('product_ingredients', 0);
    }

    public function test_ingredient_search_and_writes_remain_scoped_to_pharmacy_and_escape_names(): void
    {
        [$owner, $pharmacy] = $this->workspace();
        [$otherOwner, $other] = $this->workspace();
        $payload = $this->payload(['ingredients' => [['name' => '<script>alert(1)</script>']]]);
        $this->actingAs($owner)->post(route('products.store', $pharmacy), $payload)->assertSessionHasNoErrors();
        $product = $pharmacy->products()->firstOrFail();
        $this->get(route('products.index', [$pharmacy, 'q' => 'alert']))->assertOk()->assertSee('<script>alert(1)</script>')->assertDontSee('<script>alert(1)</script>', false);
        $this->actingAs($otherOwner)->get(route('products.index', [$other, 'q' => 'alert']))->assertOk()->assertDontSee('Brand A');
        $this->put(route('products.update', [$other, $product]), $this->payload(['ingredients' => []]))->assertNotFound();
        $this->assertSame(['<script>alert(1)</script>'], $product->fresh()->ingredients->pluck('name')->all());
    }

    public function test_product_can_be_created_without_drug_name(): void
    {
        [$owner, $pharmacy] = $this->workspace();
        $this->actingAs($owner)->get(route('products.create', $pharmacy))->assertOk()->assertDontSee('name="name"', false);
        $data = $this->payload();
        unset($data['name']);
        $this->post(route('products.store', $pharmacy), $data)->assertSessionHasNoErrors();
        $product = $pharmacy->products()->firstOrFail();
        $this->assertSame('Brand A', $product->name);
        $product->update(['name' => 'Nama lama']);
        $data['brand'] = 'Brand Baru';
        $this->put(route('products.update', [$pharmacy, $product]), $data)->assertSessionHasNoErrors();
        $this->assertSame('Nama lama', $product->fresh()->name);
    }

    public function test_same_name_can_have_multiple_brands_and_search_filters_brand_and_generic_name(): void
    {
        [$owner, $pharmacy] = $this->workspace();
        $this->actingAs($owner)->get(route('products.index', $pharmacy))->assertOk()->assertSee('Mulai susun katalog apotek');
        $this->get(route('products.create', $pharmacy))->assertOk()->assertSee('Brand / merek')->assertSee('Isi per satuan pembelian');
        $this->post(route('products.store', $pharmacy), $this->payload())->assertRedirect(route('products.index', $pharmacy));
        $this->post(route('products.store', $pharmacy), $this->payload(['sku' => 'PCM-B-500', 'brand' => 'Brand B']))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('products', 2);
        $this->get(route('products.index', [$pharmacy, 'q' => 'brand b']))->assertOk()->assertSee('Brand B')->assertDontSee('Brand A');
        $this->get(route('products.index', [$pharmacy, 'q' => 'paracetamol']))->assertSee('Brand A')->assertSee('Brand B');
        $this->assertDatabaseHas('audit_entries', ['pharmacy_id' => $pharmacy->id, 'action' => 'product.created']);
    }

    public function test_validation_rejects_duplicates_missing_brand_and_invalid_conversions(): void
    {
        [$owner, $pharmacy] = $this->workspace();
        $this->actingAs($owner)->post(route('products.store', $pharmacy), $this->payload(['barcode' => '123456']))->assertSessionHasNoErrors();
        $this->post(route('products.store', $pharmacy), $this->payload(['sku' => ' pcm-a-500 ']))->assertSessionHasErrors('sku');
        $this->post(route('products.store', $pharmacy), $this->payload(['sku' => 'OTHER', 'barcode' => '123456']))->assertSessionHasErrors('barcode');
        foreach ([['brand' => ' '], ['purchase_conversion' => 0], ['maximum_stock' => 1], ['selling_price' => -1], ['base_unit' => 'Tablet', 'purchase_unit' => 'Tablet', 'purchase_conversion' => 10]] as $invalid) {
            $this->post(route('products.store', $pharmacy), $this->payload(['sku' => 'NEW'] + $invalid))->assertSessionHasErrors();
        }
        $this->assertDatabaseCount('products', 1);
    }

    public function test_edit_status_and_form_values_are_preserved_without_deleting_product(): void
    {
        [$owner, $pharmacy] = $this->workspace();
        $this->actingAs($owner)->post(route('products.store', $pharmacy), $this->payload());
        $product = $pharmacy->products()->firstOrFail();
        $this->get(route('products.edit', [$pharmacy, $product]))->assertOk()->assertSee('value="Brand A"', false);
        $this->put(route('products.update', [$pharmacy, $product]), $this->payload(['brand' => 'Brand Baru', 'selling_price' => '750.50', 'active' => 0]))->assertSessionHasNoErrors();
        $this->assertTrue($product->fresh()->active);
        $this->assertSame('750.50', $product->fresh()->selling_price);
        $this->patch(route('products.status', [$pharmacy, $product]), ['active' => 0])->assertRedirect();
        $this->assertFalse($product->fresh()->active);
        $this->get(route('products.index', [$pharmacy, 'status' => 'inactive']))->assertSee('Brand Baru');
        $this->assertDatabaseHas('audit_entries', ['action' => 'product.status_changed', 'subject' => $product->id]);
        $this->patch(route('products.status', [$pharmacy, $product]), ['active' => 1])->assertRedirect();
        $this->assertTrue($product->fresh()->active);
    }

    public function test_tenant_scoping_prevents_cross_pharmacy_access_and_allows_reusing_sku(): void
    {
        [$owner, $pharmacy] = $this->workspace();
        [$otherOwner, $other] = $this->workspace();
        $this->actingAs($owner)->post(route('products.store', $pharmacy), $this->payload());
        $product = $pharmacy->products()->firstOrFail();
        $this->actingAs($otherOwner)->post(route('products.store', $other), $this->payload())->assertSessionHasNoErrors();
        $this->get(route('products.edit', [$other, $product]))->assertNotFound();
        $this->put(route('products.update', [$other, $product]), $this->payload())->assertNotFound();
        $this->patch(route('products.status', [$other, $product]), ['active' => 0])->assertNotFound();
        $this->get(route('products.index', $pharmacy))->assertForbidden();
        $this->assertTrue($product->fresh()->active);
    }

    public function test_view_only_member_cannot_create_edit_or_deactivate_and_menu_respects_permission(): void
    {
        [$owner, $pharmacy] = $this->workspace();
        $this->actingAs($owner)->post(route('products.store', $pharmacy), $this->payload());
        $product = $pharmacy->products()->firstOrFail();
        $viewer = User::factory()->create();
        $member = $pharmacy->memberships()->create(['user_id' => $viewer->id, 'active' => true]);
        $role = $pharmacy->roles()->create(['name' => 'Pembaca katalog', 'permissions' => ['drugs.view']]);
        app(AccessManager::class)->syncRoles($member, collect([$role]));
        $this->actingAs($viewer)->get(route('products.index', $pharmacy))->assertOk()->assertSee('Brand A')->assertDontSee('Tambah produk</a>', false);
        $this->get(route('products.create', $pharmacy))->assertForbidden();
        $this->post(route('products.store', $pharmacy), $this->payload())->assertForbidden();
        $this->get(route('products.edit', [$pharmacy, $product]))->assertForbidden();
        $this->put(route('products.update', [$pharmacy, $product]), $this->payload())->assertForbidden();
        $this->patch(route('products.status', [$pharmacy, $product]), ['active' => 0])->assertForbidden();
        $role->update(['permissions' => []]);
        $this->get(route('products.index', $pharmacy))->assertForbidden();
    }
}
