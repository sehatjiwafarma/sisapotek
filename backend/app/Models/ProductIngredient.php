<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductIngredient extends Model
{
    public $timestamps = false;

    protected $guarded = ['id', 'product_id'];

    public const STRENGTH_UNITS = ['mg', 'mcg', 'g', 'IU', '%'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4', 'basis_quantity' => 'decimal:4'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
