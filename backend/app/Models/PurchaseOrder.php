<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    use HasUuids;

    protected $guarded = ['id', 'pharmacy_id'];

    public const STATUSES = ['DRAFT' => 'Draft', 'ORDERED' => 'Dipesan', 'PARTIAL' => 'Diterima sebagian', 'RECEIVED' => 'Diterima lengkap', 'CANCELLED' => 'Batal'];

    protected function casts(): array
    {
        return ['ordered_on' => 'date', 'expected_on' => 'date', 'total' => 'decimal:2', 'finalized_at' => 'datetime'];
    }

    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function receipts()
    {
        return $this->hasMany(PurchaseReceipt::class);
    }
}
