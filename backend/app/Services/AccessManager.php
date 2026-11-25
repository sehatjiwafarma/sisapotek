<?php

namespace App\Services;

use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Pharmacy;
use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccessManager
{
    // All access mutations lock the tenant row first, including invitation acceptance.
    public function locked(Pharmacy $pharmacy, callable $callback): mixed
    {
        return DB::transaction(function () use ($pharmacy, $callback) {
            Pharmacy::whereKey($pharmacy->id)->lockForUpdate()->firstOrFail();

            return $callback();
        }, 3);
    }

    public function actor(Pharmacy $pharmacy, int $userId, string $permission): Membership
    {
        $actor = $pharmacy->memberships()->with('roles')->where('user_id', $userId)->first();
        abort_unless($actor?->allows($permission), 403);

        return $actor;
    }

    public function grantable(Membership $actor, Role $role): bool
    {
        return $actor->active && $actor->pharmacy_id === $role->pharmacy_id
            && ($actor->isOwner() || (! $role->is_owner && ! array_diff($role->permissions, $actor->permissions())));
    }

    public function rolesFor(Membership $actor, array $ids): Collection
    {
        $roles = Role::where('pharmacy_id', $actor->pharmacy_id)->whereIn('id', $ids)->get();
        if (! $roles->count() || $roles->count() !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['roles' => 'Pilih minimal satu role yang tersedia di apotek ini.']);
        }
        foreach ($roles as $role) {
            abort_unless($this->grantable($actor, $role), 403, 'Tidak dapat memberikan izin melebihi akses Anda.');
        }

        return $roles;
    }

    public function syncRoles(Membership $member, $roles): void
    {
        $member->roles()->sync($roles->mapWithKeys(fn ($role) => [$role->id => ['pharmacy_id' => $member->pharmacy_id]])->all());
        $member->unsetRelation('roles');
    }

    public function audit(Pharmacy $pharmacy, ?int $actor, string $action, string $subject, array $details = []): void
    {
        DB::table('audit_entries')->insert(['pharmacy_id' => $pharmacy->id, 'actor_id' => $actor,
            'action' => $action, 'subject' => $subject, 'details' => json_encode($details), 'created_at' => now()]);
    }

    public function provision(int $userId, array $data): Pharmacy
    {
        return DB::transaction(function () use ($userId, $data) {
            $pharmacy = Pharmacy::create($data + ['code' => 'APT-'.strtoupper(Str::random(12))]);
            foreach (\App\Models\Product::CATEGORIES as $name) { DB::table('product_categories')->insert(['pharmacy_id' => $pharmacy->id, 'name' => $name, 'active' => true]); }
            $pharmacy->license()->create([]);
            $pharmacy->settings()->create(['receipt_header' => $pharmacy->name]);
            $owner = $pharmacy->roles()->create(['name' => 'Owner', 'description' => 'Pengelola penuh apotek; role dilindungi.', 'is_owner' => true, 'permissions' => array_keys(Permissions::all())]);
            $member = $pharmacy->memberships()->create(['user_id' => $userId, 'active' => true]);
            $this->syncRoles($member, collect([$owner]));
            $this->audit($pharmacy, $userId, 'pharmacy.created', "pharmacy:$pharmacy->id");

            return $pharmacy;
        });
    }

    public function saveRole(Pharmacy $pharmacy, int $actorId, array $data, ?int $roleId = null): Role
    {
        return $this->locked($pharmacy, function () use ($pharmacy, $actorId, $data, $roleId) {
            $actor = $this->actor($pharmacy, $actorId, $roleId ? 'roles.update' : 'roles.create');
            $role = $roleId ? $pharmacy->roles()->findOrFail($roleId) : new Role(['pharmacy_id' => $pharmacy->id]);
            abort_if($role->is_owner, 403, 'Role Owner dilindungi.');
            if ($roleId) {
                abort_unless($this->grantable($actor, $role), 403);
            }
            $permissions = $data['permissions'] ?? [];
            abort_if(array_diff($permissions, array_keys(Permissions::all())), 422);
            abort_unless($actor->isOwner() || ! array_diff($permissions, $actor->permissions()), 403, 'Tidak dapat memberikan izin melebihi akses Anda.');
            if ($pharmacy->roles()->whereRaw('LOWER(name) = ?', [mb_strtolower($data['name'])])->when($roleId, fn ($q) => $q->where('id', '!=', $roleId))->exists()) {
                throw ValidationException::withMessages(['name' => 'Nama role sudah digunakan.']);
            }
            $before = $role->exists ? $role->only('name', 'description', 'permissions') : null;
            $role->fill(['name' => $data['name'], 'description' => $data['description'] ?? null, 'permissions' => array_values(array_unique($permissions))])->save();
            $this->audit($pharmacy, $actorId, $roleId ? 'role.updated' : 'role.created', "role:$role->id", ['before' => $before, 'after' => $role->only('name', 'description', 'permissions')]);

            return $role;
        });
    }

    public function deleteRole(Pharmacy $pharmacy, int $actorId, int $roleId): void
    {
        $this->locked($pharmacy, function () use ($pharmacy, $actorId, $roleId) {
            $actor = $this->actor($pharmacy, $actorId, 'roles.delete');
            $role = $pharmacy->roles()->findOrFail($roleId);
            abort_if($role->is_owner || ! $this->grantable($actor, $role), 403);
            $inUse = $pharmacy->invitations()->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->get()->contains(fn ($invite) => in_array($roleId, $invite->role_ids));
            if ($role->memberships()->exists() || $inUse) {
                throw ValidationException::withMessages(['role' => 'Role masih digunakan pengguna atau undangan aktif.']);
            }
            $this->audit($pharmacy, $actorId, 'role.deleted', "role:$roleId", $role->only('name', 'permissions'));
            $role->delete();
        });
    }

    public function changeMember(Pharmacy $pharmacy, int $actorId, int $memberId, string $action, array $ids = [], bool $active = true): void
    {
        $this->locked($pharmacy, function () use ($pharmacy, $actorId, $memberId, $action, $ids, $active) {
            $permission = ['roles' => 'users.update', 'status' => 'users.deactivate', 'remove' => 'users.remove'][$action];
            $actor = $this->actor($pharmacy, $actorId, $permission);
            $member = $pharmacy->memberships()->with('roles')->findOrFail($memberId);
            foreach ($member->roles as $role) {
                abort_unless($this->grantable($actor, $role), 403);
            }
            $before = ['active' => $member->active, 'roles' => $member->roles->pluck('id')->all()];
            if ($action === 'roles') {
                $this->syncRoles($member, $this->rolesFor($actor, $ids));
            } elseif ($action === 'status') {
                $member->update(['active' => $active]);
            } else {
                $member->roles()->detach();
                $member->delete();
            }
            $hasOwner = $pharmacy->memberships()->where('active', true)->whereHas('roles', fn ($q) => $q->where('is_owner', true))->exists();
            if (! $hasOwner) {
                throw ValidationException::withMessages(['owner' => 'Apotek harus memiliki minimal satu Owner aktif.']);
            }
            $this->audit($pharmacy, $actorId, "member.$action", "user:$member->user_id", ['before' => $before, 'after' => $action === 'remove' ? null : ['active' => $member->active, 'roles' => $member->roles->pluck('id')->all()]]);
        });
    }

    public function invite(Pharmacy $pharmacy, int $actorId, string $email, array $ids): array
    {
        return $this->locked($pharmacy, function () use ($pharmacy, $actorId, $email, $ids) {
            $actor = $this->actor($pharmacy, $actorId, 'users.invite');
            $roles = $this->rolesFor($actor, $ids);
            if ($pharmacy->memberships()->whereHas('user', fn ($q) => $q->where('email', $email))->exists()) {
                throw ValidationException::withMessages(['email' => 'Pengguna sudah menjadi anggota. Ubah aksesnya dari daftar pengguna.']);
            }
            $pharmacy->invitations()->where('email', $email)->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $token = bin2hex(random_bytes(32));
            $invite = $pharmacy->invitations()->create(['invited_by' => $actorId, 'email' => $email,
                'role_ids' => $roles->pluck('id')->all(), 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHours(72)]);
            $this->audit($pharmacy, $actorId, 'invitation.created', "invitation:$invite->id", ['role_ids' => $invite->role_ids]);

            return [$invite, $token];
        });
    }

    public function accept(Invitation $invitation, User $user): Pharmacy
    {
        $pharmacy = $invitation->pharmacy;

        return $this->locked($pharmacy, function () use ($pharmacy, $invitation, $user) {
            $invite = Invitation::findOrFail($invitation->id);
            abort_unless($invite->usable(), 410, 'Undangan sudah kedaluwarsa atau tidak berlaku.');
            abort_unless($invite->email === $user->email, 403, 'Masuk dengan email penerima undangan.');
            $actor = $this->actor($pharmacy, $invite->invited_by, 'users.invite');
            $roles = $this->rolesFor($actor, $invite->role_ids);
            abort_if($pharmacy->memberships()->where('user_id', $user->id)->exists(), 409, 'Anda sudah menjadi anggota.');
            $member = $pharmacy->memberships()->create(['user_id' => $user->id, 'active' => true]);
            $this->syncRoles($member, $roles);
            $invite->update(['accepted_at' => now()]);
            $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();
            $this->audit($pharmacy, $user->id, 'invitation.accepted', "invitation:$invite->id");

            return $pharmacy;
        });
    }
}
