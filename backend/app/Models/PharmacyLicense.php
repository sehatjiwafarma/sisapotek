<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PharmacyLicense extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['issued_on' => 'date', 'expires_on' => 'date', 'has_expiry' => 'boolean'];
    }

    public function pharmacy()
    {
        return $this->belongsTo(Pharmacy::class);
    }
}
