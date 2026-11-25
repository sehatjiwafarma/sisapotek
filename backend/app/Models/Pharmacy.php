<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Pharmacy extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = ['code', 'name', 'address', 'ownership_type', 'business_name', 'legal_form', 'phone', 'email', 'website', 'province_code', 'city_code', 'district_code', 'village_code', 'postal_code', 'latitude', 'longitude', 'maps_url', 'owner_name', 'owner_phone', 'owner_email', 'owners'];

    protected function casts(): array
    {
        return ['owners' => 'array', 'has_transactions' => 'boolean', 'activated_at' => 'datetime', 'deactivated_at' => 'datetime'];
    }

    protected function owners(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value !== null ? json_decode($value, true) : ($this->owner_name ? [['name' => $this->owner_name, 'phone' => $this->owner_phone, 'email' => $this->owner_email, 'share_percentage' => null]] : []),
            set: fn ($value) => ['owners' => json_encode($value), 'owner_name' => $value[0]['name'], 'owner_phone' => $value[0]['phone'], 'owner_email' => $value[0]['email'] ?? null],
        );
    }

    /** All operational modules use the calendar configured in the pharmacy settings. */
    protected function timezone(): Attribute
    {
        return Attribute::get(fn (): string => $this->settings?->timezone ?: 'Asia/Jakarta');
    }

    public function license()
    {
        return $this->hasOne(PharmacyLicense::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function suppliers()
    {
        return $this->hasMany(Supplier::class);
    }

    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function purchaseReceipts()
    {
        return $this->hasMany(PurchaseReceipt::class);
    }

    public function settings()
    {
        return $this->hasOne(PharmacySetting::class);
    }

    public function personnel()
    {
        return $this->hasMany(PharmacyPersonnel::class);
    }

    public function assignments()
    {
        return $this->hasMany(PharmacyPersonnelAssignment::class);
    }

    public function hours()
    {
        return $this->hasMany(PharmacyOperatingHour::class)->orderBy('day')->orderBy('opens_at');
    }

    public function documents()
    {
        return $this->hasMany(PharmacyDocument::class);
    }

    public function resolveRouteBinding($value, $field = null)
    {
        if ($field) {
            return parent::resolveRouteBinding($value, $field);
        }
        if (Str::isUuid($value)) {
            return $this->whereKey($value)->first();
        }

        return ctype_digit((string) $value) ? $this->where('legacy_id', $value)->first() : null;
    }

    public function memberships()
    {
        return $this->hasMany(Membership::class);
    }

    public function roles()
    {
        return $this->hasMany(Role::class);
    }

    public function invitations()
    {
        return $this->hasMany(Invitation::class);
    }
}
