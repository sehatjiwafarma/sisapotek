<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PharmacyPersonnel extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $table = 'pharmacy_personnel';

    protected function casts(): array
    {
        return ['active' => 'boolean', 'sip_expires_on' => 'date'];
    }

    public function pharmacy()
    {
        return $this->belongsTo(Pharmacy::class);
    }
}
