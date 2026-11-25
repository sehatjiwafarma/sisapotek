<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use HasUuids;

    protected $guarded = ['id', 'pharmacy_id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'payment_days' => 'integer'];
    }
}
