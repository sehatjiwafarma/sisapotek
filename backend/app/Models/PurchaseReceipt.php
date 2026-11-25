<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PurchaseReceipt extends Model
{
    use HasUuids;

    protected $guarded = ['id', 'pharmacy_id'];

    protected function casts(): array
    {
        return ['received_on' => 'date', 'posted_at' => 'datetime', 'ppn_value' => 'decimal:2', 'ppn_amount' => 'decimal:2', 'subtotal' => 'decimal:2', 'total' => 'decimal:2'];
    }

    public function items()
    {
        return $this->hasMany(PurchaseReceiptItem::class);
    }

    public function order()
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }
}
