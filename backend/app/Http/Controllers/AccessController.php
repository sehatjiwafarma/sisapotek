<?php

namespace App\Http\Controllers;

use App\Models\Invitation;
use App\Models\Pharmacy;
use App\Models\Role;
use App\Models\User;
use App\Notifications\PharmacyInvitation;
use App\Services\AccessManager;
use App\Support\Permissions;
use App\Support\TableSort;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

class AccessController extends Controller
{
    public function __construct(private AccessManager $manager) {}

    public function pharmacies(Request $request)
    {
        return view('access.pharmacies', ['memberships' => $request->user()->memberships()->with(['pharmacy', 'roles'])->whereHas('pharmacy')->where('active', true)->get()]);
    }

    public function deletePharmacy(Request $request, Pharmacy $pharmacy)
    {
        $this->manager->locked($pharmacy, function () use ($request, $pharmacy) {
            $member = $pharmacy->memberships()->with('roles')->where('user_id', $request->user()->id)->first();
            abort_unless($member?->isOwner(), 403);
            $this->manager->audit($pharmacy, $request->user()->id, 'pharmacy.deleted', 'pharmacy:'.$pharmacy->id, ['name' => $pharmacy->name]);
            $pharmacy->delete();
        });

        return redirect()->route('pharmacies.index')->with('status', 'Apotek dihapus dari daftar. Akses workspace ditutup; riwayat data tetap tersimpan.');
    }

    public function createPharmacy(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:150'], 'address' => ['nullable', 'string', 'max:500']]);
        $pharmacy = $this->manager->provision($request->user()->id, $data);

        return redirect()->route('pharmacy.home', $pharmacy)->with('status', 'Apotek dibuat. Anda terdaftar sebagai Owner.');
    }

    public function home(Pharmacy $pharmacy)
    {
        return view('access.home', ['pharmacy' => $pharmacy]);
    }

    public function pharmacyEdit(Pharmacy $pharmacy)
    {
        return view('access.pharmacy-form', compact('pharmacy'));
    }

    public function pharmacyUpdate(Request $request, Pharmacy $pharmacy)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:150'], 'address' => ['nullable', 'string', 'max:500']]);
        $this->manager->locked($pharmacy, function () use ($request, $pharmacy, $data) {
            $this->manager->actor($pharmacy, $request->user()->id, 'pharmacy.update');
            $before = $pharmacy->only('name', 'address');
            $pharmacy->update($data);
            $this->manager->audit($pharmacy, $request->user()->id, 'pharmacy.updated', "pharmacy:$pharmacy->id", ['before' => $before, 'after' => $data]);
        });

        return back()->with('status', 'Profil apotek disimpan.');
    }

    public function users(Request $request, Pharmacy $pharmacy)
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:150'], 'status' => ['nullable', Rule::in(['active', 'inactive'])]]);
        $query = $pharmacy->memberships()->with(['user', 'roles']);
        if ($q = $filters['q'] ?? null) {
            $query->whereHas('user', fn ($query) => $query->where(fn ($query) => $query->where('name', 'like', '%'.$q.'%')->orWhere('email', 'like', '%'.$q.'%')));
        }
        if ($status = $filters['status'] ?? null) {
            $query->where('active', $status === 'active');
        }

        TableSort::apply($query, [
            'user' => fn ($q, $d) => $q->orderBy(User::select('name')->whereColumn('users.id', 'memberships.user_id'), $d),
            'role' => fn ($q, $d) => $q->orderBy(DB::table('membership_role')->join('roles', 'roles.id', '=', 'membership_role.role_id')->selectRaw('MIN(roles.name)')->whereColumn('membership_role.membership_id', 'memberships.id'), $d),
            'status' => fn ($q, $d) => $q->orderBy('active', $d === 'asc' ? 'desc' : 'asc'),
        ], 'user', 'asc', 'members_');
        $invitations = $pharmacy->invitations();
        TableSort::apply($invitations, ['email' => 'email', 'expires' => 'expires_at',
            'status' => fn ($q, $d) => $q->orderByRaw("CASE WHEN accepted_at IS NOT NULL THEN 'Diterima' WHEN revoked_at IS NOT NULL THEN 'Dicabut' WHEN expires_at > ? THEN 'Menunggu' ELSE 'Kedaluwarsa' END ".$d, [now()]),
            'created' => 'created_at'], 'created', 'desc', 'invites_');

        return view('access.users', ['members' => $query->orderBy('memberships.id')->paginate(15)->withQueryString(), 'invitations' => $invitations->orderBy('id')->paginate(10, ['*'], 'invites')->withQueryString(), 'pharmacy' => $pharmacy]);
    }

    private function roleChoices(Request $request, Pharmacy $pharmacy)
    {
        return $pharmacy->roles()->orderBy('name')->get()->filter(fn ($role) => $this->manager->grantable($request->attributes->get('membership'), $role));
    }

    public function memberEdit(Request $request, Pharmacy $pharmacy, int $member)
    {
        $member = $pharmacy->memberships()->with(['user', 'roles'])->findOrFail($member);
        foreach ($member->roles as $role) {
            abort_unless($this->manager->grantable($request->attributes->get('membership'), $role), 403);
        }

        return view('access.member-form', ['member' => $member, 'roles' => $this->roleChoices($request, $pharmacy)]);
    }

    public function memberUpdate(Request $request, Pharmacy $pharmacy, int $member)
    {
        $data = $request->validate(['roles' => ['required', 'array', 'min:1'], 'roles.*' => ['required', 'integer', 'distinct']]);
        $isSelf = $pharmacy->memberships()->findOrFail($member)->user_id === $request->user()->id;
        $this->manager->changeMember($pharmacy, $request->user()->id, $member, 'roles', $data['roles']);

        return redirect()->route($isSelf ? 'pharmacy.home' : 'users.index', $pharmacy)->with('status', 'Role pengguna diperbarui.');
    }

    public function memberStatus(Request $request, Pharmacy $pharmacy, int $member)
    {
        $data = $request->validate(['active' => ['required', 'boolean']]);
        $isSelf = $pharmacy->memberships()->findOrFail($member)->user_id === $request->user()->id;
        $this->manager->changeMember($pharmacy, $request->user()->id, $member, 'status', [], (bool) $data['active']);

        return ($isSelf ? redirect()->route('pharmacies.index') : redirect()->route('users.index', $pharmacy))->with('status', 'Status akses pengguna diperbarui.');
    }

    public function memberRemove(Request $request, Pharmacy $pharmacy, int $member)
    {
        $isSelf = $pharmacy->memberships()->findOrFail($member)->user_id === $request->user()->id;
        $this->manager->changeMember($pharmacy, $request->user()->id, $member, 'remove');

        return ($isSelf ? redirect()->route('pharmacies.index') : redirect()->route('users.index', $pharmacy))->with('status', 'Keanggotaan dicabut. Akun dan riwayat audit tetap tersimpan.');
    }

    public function inviteForm(Request $request, Pharmacy $pharmacy)
    {
        return view('access.invite-form', ['roles' => $this->roleChoices($request, $pharmacy)]);
    }

    public function invite(Request $request, Pharmacy $pharmacy)
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate(['email' => ['required', 'email', 'max:255'], 'roles' => ['required', 'array', 'min:1'], 'roles.*' => ['required', 'integer', 'distinct']]);
        [$invite, $token] = $this->manager->invite($pharmacy, $request->user()->id, $data['email'], $data['roles']);
        try {
            Notification::route('mail', $invite->email)->notify(new PharmacyInvitation($pharmacy->name, $token));
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('users.index', $pharmacy)->with('warning', 'Undangan tersimpan tetapi email gagal dikirim. Periksa konfigurasi email, lalu undang ulang untuk membuat tautan baru.');
        }

        return redirect()->route('users.index', $pharmacy)->with('status', 'Undangan dibuat dan dikirim melalui layanan email yang dikonfigurasi. Berlaku 72 jam.');
    }

    public function revoke(Request $request, Pharmacy $pharmacy, int $invitation)
    {
        $this->manager->locked($pharmacy, function () use ($request, $pharmacy, $invitation) {
            $actor = $this->manager->actor($pharmacy, $request->user()->id, 'users.invite');
            $invite = $pharmacy->invitations()->findOrFail($invitation);
            if (! $actor->isOwner() && $invite->invited_by !== $actor->user_id) {
                abort(403);
            }
            abort_if($invite->accepted_at, 409);
            $invite->update(['revoked_at' => now()]);
            $this->manager->audit($pharmacy, $request->user()->id, 'invitation.revoked', "invitation:$invite->id");
        });

        return back()->with('status', 'Undangan dicabut.');
    }

    private function findInvite(string $token): Invitation
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/', $token), 404);
        $invite = Invitation::with('pharmacy')->where('token_hash', hash('sha256', $token))->firstOrFail();
        abort_unless($invite->pharmacy && $invite->usable(), 410, 'Undangan sudah kedaluwarsa atau dicabut.');

        return $invite;
    }

    public function invitationShow(Request $request, string $token)
    {
        $invite = $this->findInvite($token);
        if (! $request->user()) {
            $request->session()->put('url.intended', route('invitations.show', $token));
        }

        return response()->view('access.accept', compact('invite', 'token'))->header('Referrer-Policy', 'no-referrer')->header('Cache-Control', 'no-store');
    }

    public function invitationAccept(Request $request, string $token)
    {
        $pharmacy = $this->manager->accept($this->findInvite($token), $request->user());

        return redirect()->route('pharmacy.home', $pharmacy)->with('status', 'Undangan diterima. Selamat bergabung.');
    }

    public function roles(Pharmacy $pharmacy)
    {
        return view('access.roles', ['roles' => $pharmacy->roles()->withCount('memberships')->orderByDesc('is_owner')->orderBy('name')->get()]);
    }

    public function roleForm(Request $request, Pharmacy $pharmacy, ?int $role = null)
    {
        $role = $role ? $pharmacy->roles()->findOrFail($role) : new Role(['permissions' => []]);
        abort_if($role->is_owner, 403);
        if ($role->exists) {
            abort_unless($this->manager->grantable($request->attributes->get('membership'), $role), 403);
        }

        return view('access.role-form', ['role' => $role, 'groups' => Permissions::groups()]);
    }

    public function roleSave(Request $request, Pharmacy $pharmacy, ?int $role = null)
    {
        $request->merge(['name' => trim((string) $request->input('name'))]);
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'description' => ['nullable', 'string', 'max:300'], 'permissions' => ['sometimes', 'array'], 'permissions.*' => ['required', 'string', 'distinct', Rule::in(array_keys(Permissions::all()))]]);
        $this->manager->saveRole($pharmacy, $request->user()->id, $data, $role);

        return redirect()->route('roles.index', $pharmacy)->with('status', 'Role dan izin disimpan.');
    }

    public function roleDelete(Request $request, Pharmacy $pharmacy, int $role)
    {
        $this->manager->deleteRole($pharmacy, $request->user()->id, $role);

        return back()->with('status', 'Role dihapus.');
    }

    public function audit(Pharmacy $pharmacy)
    {
        $query = DB::table('audit_entries')->leftJoin('users', 'users.id', '=', 'audit_entries.actor_id')->where('pharmacy_id', $pharmacy->id)->select('audit_entries.*', 'users.name as actor_name');
        TableSort::apply($query, ['time' => 'audit_entries.created_at', 'actor' => 'users.name', 'action' => 'audit_entries.action', 'subject' => 'audit_entries.subject'], 'time', 'desc');

        return view('access.audit', ['entries' => $query->orderByDesc('audit_entries.id')->paginate(25)->withQueryString()]);
    }
}
