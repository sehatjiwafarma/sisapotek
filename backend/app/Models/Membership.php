<?php

namespace App\Models;

use App\Support\Permissions;
use Illuminate\Database\Eloquent\Model;

class Membership extends Model
{
    protected $fillable = ['pharmacy_id', 'user_id', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function pharmacy()
    {
        return $this->belongsTo(Pharmacy::class);
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class)->withPivot('pharmacy_id');
    }

    public function isOwner(): bool
    {
        return $this->active && $this->roles->contains('is_owner', true);
    }

    public function permissions(): array
    {
        return $this->roles->flatMap(fn ($role) => $role->permissions)->unique()->values()->all();
    }

    public function allows(string $permission): bool
    {
        if (in_array($permission, ['pharmacy.activate', 'pharmacy.deactivate'], true)) {
            return $this->isOwner();
        }

        return $this->active && array_key_exists($permission, Permissions::all())
            && ($this->isOwner() || in_array($permission, $this->permissions(), true));
    }
}
