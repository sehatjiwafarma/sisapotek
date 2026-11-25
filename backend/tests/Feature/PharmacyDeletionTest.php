<?php

namespace Tests\Feature;

use App\Models\Pharmacy;
use App\Models\User;
use App\Services\AccessManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PharmacyDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_archive_pharmacy_and_its_workspace_becomes_unavailable(): void
    {
        $owner = User::factory()->create();
        $pharmacy = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek Arsip']);
        $other = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek Tetap']);
        $this->actingAs($owner)->get(route('pharmacies.index'))->assertSee(route('pharmacies.destroy', $pharmacy));
        $this->delete(route('pharmacies.destroy', $pharmacy))->assertRedirect(route('pharmacies.index'));
        $this->assertSoftDeleted($pharmacy);
        $this->assertDatabaseHas('memberships', ['pharmacy_id' => $pharmacy->id, 'user_id' => $owner->id]);
        $this->assertDatabaseHas('audit_entries', ['pharmacy_id' => $pharmacy->id, 'action' => 'pharmacy.deleted']);
        $this->assertTrue(Pharmacy::whereKey($other->id)->exists());
        $this->get(route('pharmacies.index'))->assertOk()->assertDontSee('Apotek Arsip')->assertSee('Apotek Tetap');
        $this->get(route('pharmacy.home', $pharmacy))->assertNotFound();
        $this->delete(route('pharmacies.destroy', $pharmacy))->assertNotFound();
    }

    public function test_non_owner_and_inactive_owner_cannot_delete_pharmacy(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $pharmacy = app(AccessManager::class)->provision($owner->id, ['name' => 'Apotek Aman']);
        $this->actingAs($other)->delete(route('pharmacies.destroy', $pharmacy))->assertForbidden();
        $pharmacy->memberships()->create(['user_id' => $other->id, 'active' => true]);
        $this->get(route('pharmacies.index'))->assertOk()->assertDontSee('Hapus apotek');
        $this->delete(route('pharmacies.destroy', $pharmacy))->assertForbidden();
        $pharmacy->memberships()->where('user_id', $owner->id)->update(['active' => false]);
        $this->actingAs($owner)->delete(route('pharmacies.destroy', $pharmacy))->assertForbidden();
        $this->assertNotSoftDeleted($pharmacy);
    }
}
