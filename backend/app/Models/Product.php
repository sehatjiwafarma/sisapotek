<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasUuids;

    protected $guarded = ['id', 'pharmacy_id'];

    public const CATEGORIES = ['Obat', 'Vitamin & suplemen', 'Alat kesehatan', 'Perawatan tubuh', 'Produk lainnya'];

    public const FORMS = ['Tablet', 'Kaplet', 'Kapsul', 'Sirup', 'Suspensi', 'Tetes', 'Salep', 'Krim', 'Gel', 'Serbuk', 'Injeksi', 'Lainnya'];

    public const UNITS = ['Tablet', 'Kaplet', 'Kapsul', 'Botol', 'Tube', 'Sachet', 'Ampul', 'Vial', 'Pcs', 'Strip', 'Blister', 'Box', 'Pack', 'ml', 'gram'];

    public function ingredients(): HasMany
    {
        return $this->hasMany(ProductIngredient::class)->orderBy('position');
    }

    protected function casts(): array
    {
        return ['selling_units' => 'array', 'active' => 'boolean', 'requires_prescription' => 'boolean', 'is_psychotropic' => 'boolean', 'purchase_conversion' => 'decimal:4',
            'purchase_price' => 'decimal:2', 'selling_price' => 'decimal:2', 'minimum_stock' => 'decimal:4', 'maximum_stock' => 'decimal:4'];
    }
}
