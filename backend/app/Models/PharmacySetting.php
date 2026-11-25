<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PharmacySetting extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['allow_negative_stock' => 'boolean', 'track_batch' => 'boolean', 'track_expiry' => 'boolean', 'minimum_stock_notification' => 'boolean', 'expiry_reminder_days' => 'integer', 'soap_reminder_hours' => 'integer'];
    }

    public function pharmacy()
    {
        return $this->belongsTo(Pharmacy::class);
    }
}
