<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PharmacyDocument extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expires_on' => 'date', 'size' => 'integer'];
    }

    public function pharmacy()
    {
        return $this->belongsTo(Pharmacy::class);
    }
}
