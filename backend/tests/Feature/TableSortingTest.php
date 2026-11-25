<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccessManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TableSortingTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_sorting_applies_before_pagination_and_preserves_filters(): void
    {
        $owner = User::factory()->create();
        $pharmacy = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek Sorting']);
        foreach (range(1, 18) as $index) {
            $pharmacy->products()->create(['sku' => 'SKU-'.$index, 'name' => sprintf('Produk %02d', $index),
                'brand' => sprintf('Brand %02d', 19 - $index), 'category' => 'Obat', 'base_unit' => 'Tablet',
                'purchase_unit' => 'Tablet', 'purchase_conversion' => 1, 'selling_price' => $index * 100,
                'minimum_stock' => 0, 'requires_prescription' => false, 'active' => true]);
        }
        $this->actingAs($owner);
        $response = $this->get(route('products.index', [$pharmacy, 'sort' => 'price', 'direction' => 'desc', 'category' => 'Obat']));
        $response->assertOk()->assertViewHas('products', fn ($products) => $products->first()->name === 'Produk 18' && $products->last()->name === 'Produk 04');
        $response->assertSee('sort=price', false)->assertSee('direction=desc', false)->assertSee('category=Obat', false);
        $this->get(route('products.index', [$pharmacy, 'sort' => 'price', 'direction' => 'desc', 'page' => 2]))
            ->assertOk()->assertViewHas('products', fn ($products) => $products->first()->name === 'Produk 03');
        $this->get(route('products.index', [$pharmacy, 'sort' => 'brand', 'direction' => 'asc']))
            ->assertOk()->assertViewHas('products', fn ($products) => $products->first()->brand === 'Brand 01');
        $this->get(route('products.index', [$pharmacy, 'sort' => 'name; DROP TABLE products', 'direction' => 'invalid']))
            ->assertOk()->assertViewHas('products', fn ($products) => $products->first()->name === 'Produk 01');
        $this->assertDatabaseCount('products', 18);
    }

    public function test_members_roles_and_independent_invitation_sorting(): void
    {
        $owner = User::factory()->create(['name' => 'Zebra Owner']);
        $manager = app(AccessManager::class);
        $pharmacy = $manager->provision($owner->id, ['name' => 'Apotek Sorting']);
        $reader = User::factory()->create(['name' => 'Alpha Anggota']);
        $member = $pharmacy->memberships()->create(['user_id' => $reader->id, 'active' => true]);
        $role = $pharmacy->roles()->create(['name' => 'Admin', 'permissions' => []]);
        $manager->syncRoles($member, collect([$role]));
        foreach (['z@example.invalid', 'a@example.invalid'] as $email) {
            $pharmacy->invitations()->create(['email' => $email, 'token_hash' => hash('sha256', $email), 'role_ids' => [], 'invited_by' => $owner->id, 'expires_at' => now()->addDay()]);
        }
        $this->actingAs($owner)->get(route('users.index', [$pharmacy, 'members_sort' => 'user', 'members_direction' => 'asc', 'invites_sort' => 'email', 'invites_direction' => 'desc']))
            ->assertOk()->assertViewHas('members', fn ($members) => $members->first()->user->name === 'Alpha Anggota')
            ->assertViewHas('invitations', fn ($invitations) => $invitations->first()->email === 'z@example.invalid');
        $this->get(route('users.index', [$pharmacy, 'members_sort' => 'role', 'members_direction' => 'asc', 'invites_sort' => 'status', 'invites_direction' => 'asc']))
            ->assertOk()->assertViewHas('members', fn ($members) => $members->first()->id === $member->id);
        $this->get(route('audit.index', [$pharmacy, 'sort' => 'actor', 'direction' => 'asc']))->assertOk();
        $this->get(route('master.audit', [$pharmacy, 'sort' => 'details', 'direction' => 'desc']))->assertOk();
    }
}
