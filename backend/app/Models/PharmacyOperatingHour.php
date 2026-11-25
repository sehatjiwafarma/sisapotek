<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PharmacyOperatingHour extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['day' => 'integer', 'is_open' => 'boolean', 'overnight' => 'boolean', 'start_minute' => 'integer', 'end_minute' => 'integer'];
    }

    public function pharmacy()
    {
        return $this->belongsTo(Pharmacy::class);
    }
}
