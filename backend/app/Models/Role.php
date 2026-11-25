<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $fillable = ['pharmacy_id', 'name', 'description', 'permissions', 'is_owner'];

    protected function casts(): array
    {
        return ['permissions' => 'array', 'is_owner' => 'boolean'];
    }

    public function memberships()
    {
        return $this->belongsToMany(Membership::class)->withPivot('pharmacy_id');
    }
}
