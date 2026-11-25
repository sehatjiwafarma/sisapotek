<?php

namespace App\Http\Controllers;

use App\Http\Requests\SavePharmacyRequest;
use App\Http\Resources\PharmacyResource;
use App\Models\Membership;
use App\Models\Pharmacy;
use App\Models\Region;
use App\Services\AccessManager;
use App\Services\PharmacyMaster;
use App\Support\TableSort;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

class MasterPharmacyController extends Controller
{
    public function __construct(private PharmacyMaster $master, private AccessManager $access) {}

    private function isApi(Request $request): bool
    {
        return $request->is('api/*');
    }

    private function done(Request $request, Pharmacy $pharmacy, string $message, int $status = 200)
    {
        if ($this->isApi($request)) {
            return (new PharmacyResource($pharmacy->fresh()))->response()->setStatusCode($status);
        }
        $tab = in_array($request->input('tab'), ['general', 'contact', 'legal', 'personnel', 'hours', 'settings', 'documents'], true) ? $request->input('tab') : 'general';

        if ($tab === 'personnel') {
            $form = (string) $request->input('_personnel_form', '');
            $step = str_starts_with($form, 'end-') ? 'history' : ($form === 'assign' ? 'assignment' : 'data');

            return redirect()->to(route('master.show', ['pharmacy' => $pharmacy, 'personnel_step' => $step]).'#personnel')->with('status', $message);
        }

        return redirect()->to(route('master.show', $pharmacy).'#'.$tab)->with('status', $message);
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->is_system_admin, 403);
        $query = Pharmacy::query();
        TableSort::apply($query, ['code' => 'code', 'name' => 'name', 'status' => 'status'], 'name');
        $pharmacies = $query->orderBy('id')->paginate(20)->withQueryString();
        if ($this->isApi($request)) {
            return PharmacyResource::collection($pharmacies);
        }

        return view('master.index', compact('pharmacies'));
    }

    public function create(Request $request)
    {
        return $this->form($request, new Pharmacy);
    }

    public function store(SavePharmacyRequest $request)
    {
        $data = $request->validated();
        unset($data['logo']);
        $pharmacy = null;
        try {
            $pharmacy = DB::transaction(function () use ($request, $data) {
                $created = $this->master->create($request->user(), $data);
                if ($request->hasFile('logo')) {
                    $this->logo($request, $created);
                }

                return $created;
            });
        } catch (\Throwable $e) {
            if ($pharmacy?->logo_path) {
                Storage::disk('local')->delete($pharmacy->logo_path);
            } throw $e;
        }

        return $this->done($request, $pharmacy, 'Draft master apotek berhasil dibuat.', 201);
    }

    private function context(Request $request, Pharmacy $pharmacy): void
    {
        $member = $pharmacy->memberships()->with('roles')->where('user_id', $request->user()->id)->where('active', true)->first();
        if (! $member && $request->user()->is_system_admin) {
            $member = new Membership(['pharmacy_id' => $pharmacy->id, 'active' => true]);
            $member->setRelation('roles', collect());
        }
        View::share(['currentPharmacy' => $pharmacy, 'access' => $member]);
    }

    public function show(Request $request, Pharmacy $pharmacy)
    {
        $this->master->authorize($pharmacy, $request->user(), 'pharmacy.view');
        if ($this->isApi($request)) {
            return new PharmacyResource($pharmacy);
        }
        $this->context($request, $pharmacy);
        $can = function (string $permission) use ($request, $pharmacy) {
            if ($request->user()->is_system_admin) {
                return true;
            }

            return $pharmacy->memberships()->with('roles')->where('user_id', $request->user()->id)->where('active', true)->first()?->allows($permission) ?? false;
        };
        $pharmacy->load(['settings', 'hours']);
        $full = $can('pharmacy.view_sensitive');
        if ($full) {
            $pharmacy->load(['license', 'assignments.personnel', 'personnel']);
        }
        $documents = $can('pharmacy.documents_view') ? $pharmacy->documents()->latest()->get() : collect();
        $missing = $full ? $this->master->missing($pharmacy) : [];

        return view('master.show', compact('pharmacy', 'can', 'full', 'documents', 'missing'));
    }

    public function edit(Request $request, Pharmacy $pharmacy)
    {
        $this->master->authorize($pharmacy, $request->user(), 'pharmacy.update');

        return $this->form($request, $pharmacy);
    }

    private function form(Request $request, Pharmacy $pharmacy)
    {
        if ($pharmacy->exists) {
            $this->context($request, $pharmacy);
        }
        $provinces = Region::where('level', 1)->orderBy('name')->get();
        $regionOptions = [];
        foreach (['city' => 'province_code', 'district' => 'city_code', 'village' => 'district_code'] as $level => $parent) {
            $regionOptions[$level] = Region::where('parent_code', old($parent, $pharmacy->$parent))->orderBy('name')->get();
        }

        return view('master.form', compact('pharmacy', 'provinces', 'regionOptions'));
    }

    public function update(SavePharmacyRequest $request, Pharmacy $pharmacy)
    {
        $data = $request->validated();
        unset($data['logo']);
        $this->master->save($pharmacy, $request->user(), $data);
        if ($request->hasFile('logo')) {
            $this->logo($request, $pharmacy);
        }

        return $this->done($request, $pharmacy, 'Profil master apotek disimpan.');
    }

    public function logo(Request $request, Pharmacy $pharmacy)
    {
        $this->master->authorize($pharmacy, $request->user(), 'pharmacy.update');
        $request->validate(['logo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048']]);
        $path = $request->file('logo')->store('pharmacies/'.$pharmacy->id.'/logos', 'local');
        abort_unless($path, 500);
        try {
            $old = $this->access->locked($pharmacy, function () use ($request, $pharmacy, $path) {
                $this->master->authorize($pharmacy, $request->user(), 'pharmacy.update');
                $pharmacy->refresh();
                $old = $pharmacy->logo_path;
                $pharmacy->forceFill(['logo_path' => $path])->save();
                $this->access->audit($pharmacy, $request->user()->id, 'pharmacy.logo_updated', "pharmacy:$pharmacy->id", ['replaced' => (bool) $old]);

                return $old;
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
        if ($old) {
            Storage::disk('local')->delete($old);
        }

        return $this->done($request, $pharmacy, 'Logo diperbarui.');
    }

    public function logoView(Request $request, Pharmacy $pharmacy)
    {
        $this->master->authorize($pharmacy, $request->user(), 'pharmacy.view');

        return $this->brandLogo($request, $pharmacy);
    }

    public function brandLogo(Request $request, Pharmacy $pharmacy)
    {
        abort_unless($pharmacy->logo_path && Storage::disk('local')->exists($pharmacy->logo_path), 404);

        return Storage::disk('local')->response($pharmacy->logo_path, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function license(Request $request, Pharmacy $pharmacy)
    {
        $this->master->saveLicense($pharmacy, $request->user(), $request->all());

        return $this->done($request, $pharmacy, 'Legalitas disimpan.');
    }

    public function settings(Request $request, Pharmacy $pharmacy)
    {
        $this->master->authorize($pharmacy, $request->user(), 'pharmacy.view_sensitive');

        return response()->json(['data' => $pharmacy->settings->makeHidden(['id', 'pharmacy_id', 'created_at', 'updated_at'])]);
    }

    public function settingsSave(Request $request, Pharmacy $pharmacy)
    {
        $this->master->saveSettings($pharmacy, $request->user(), $request->all());

        return $this->done($request, $pharmacy, 'Konfigurasi disimpan.');
    }

    public function hours(Request $request, Pharmacy $pharmacy)
    {
        $this->master->saveHours($pharmacy, $request->user(), $request->all());

        return $this->done($request, $pharmacy, 'Jam operasional disimpan.');
    }

    public function personnel(Request $request, Pharmacy $pharmacy, ?string $person = null)
    {
        $this->master->personnel($pharmacy, $request->user(), $request->all(), $person);

        return $this->done($request, $pharmacy, 'Master personel disimpan.', 201);
    }

    public function assign(Request $request, Pharmacy $pharmacy)
    {
        $this->master->assign($pharmacy, $request->user(), $request->all());

        return $this->done($request, $pharmacy, 'Penugasan APJ disimpan.', 201);
    }

    public function endAssignment(Request $request, Pharmacy $pharmacy, string $assignment)
    {
        $this->master->endAssignment($pharmacy, $request->user(), $assignment, $request->all());

        return $this->done($request, $pharmacy, 'Penugasan APJ diakhiri.');
    }

    public function deletePersonnel(Request $request, Pharmacy $pharmacy, string $person)
    {
        $this->master->deletePersonnel($pharmacy, $request->user(), $person);

        return $this->done($request, $pharmacy, 'Data apoteker dihapus.');
    }

    public function activate(Request $request, Pharmacy $pharmacy)
    {
        $this->master->activate($pharmacy, $request->user());

        if (! $this->isApi($request)) {
            return ($request->user()->is_system_admin ? redirect()->route('system.pharmacies') : redirect()->route('master.show', $pharmacy))->with('status', 'Apotek diaktifkan.');
        }

        return $this->done($request, $pharmacy, 'Apotek diaktifkan.');
    }

    public function deactivate(Request $request, Pharmacy $pharmacy)
    {
        $this->master->deactivate($pharmacy, $request->user(), $request->all());

        if (! $this->isApi($request)) {
            return ($request->user()->is_system_admin ? redirect()->route('system.pharmacies') : redirect()->route('master.show', $pharmacy))->with('status', 'Apotek dinonaktifkan.');
        }

        return $this->done($request, $pharmacy, 'Apotek dinonaktifkan.');
    }

    public function upload(Request $request, Pharmacy $pharmacy)
    {
        $request->validate(['file' => ['required', 'file']]);
        $this->master->upload($pharmacy, $request->user(), $request->file('file'), $request->all());

        return $this->done($request, $pharmacy, 'Dokumen diunggah.', 201);
    }

    public function document(Request $request, Pharmacy $pharmacy, string $document)
    {
        $this->master->authorize($pharmacy, $request->user(), 'pharmacy.documents_view');
        $file = $pharmacy->documents()->findOrFail($document);
        abort_unless(Storage::disk('local')->exists($file->path), 404);
        $this->access->audit($pharmacy, $request->user()->id, 'pharmacy.document_downloaded', 'document:'.$file->id);

        $name = (Str::slug($file->name) ?: 'dokumen').'.'.pathinfo($file->path, PATHINFO_EXTENSION);

        return Storage::disk('local')->download($file->path, $name, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function deleteDocument(Request $request, Pharmacy $pharmacy, string $document)
    {
        $this->master->deleteDocument($pharmacy, $request->user(), $document);

        return $this->done($request, $pharmacy, 'Dokumen dihapus.');
    }

    public function audit(Request $request, Pharmacy $pharmacy)
    {
        $this->master->authorize($pharmacy, $request->user(), 'pharmacy.audit');
        $query = DB::table('audit_entries')->where('pharmacy_id', $pharmacy->id)->where('action', 'like', 'pharmacy.%');
        TableSort::apply($query, ['time' => 'created_at', 'actor' => 'actor_id', 'action' => 'action', 'details' => fn ($q, $d) => $q->orderByRaw('CAST(details AS TEXT) '.$d)], 'time', 'desc');
        $entries = $query->orderByDesc('id')->paginate(25)->withQueryString();
        if ($this->isApi($request)) {
            return response()->json($entries);
        }
        $this->context($request, $pharmacy);

        return view('master.audit', compact('entries', 'pharmacy'));
    }

    public function regions(Request $request)
    {
        $data = $request->validate(['parent' => ['nullable', 'string', 'max:20'], 'q' => ['nullable', 'string', 'max:150']]);

        return response()->json(['data' => Region::when($data['parent'] ?? null, fn ($q, $parent) => $q->where('parent_code', $parent), fn ($q) => $q->whereNull('parent_code'))
            ->when($data['q'] ?? null, fn ($q, $search) => $q->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%']))->orderBy('name')->get(['code', 'name', 'level'])]);
    }

    public function contact(Request $request, Pharmacy $pharmacy)
    {
        $this->master->authorizeEither($pharmacy, $request->user(), ['pharmacy.update', 'pharmacy.update_limited']);
        $data = $request->validate(['phone' => ['required', 'string', 'max:20', 'regex:/^\+?[0-9][0-9 ()\-]{6,19}$/'], 'email' => ['nullable', 'email', 'max:254'], 'website' => ['nullable', 'url:http,https', 'max:500'], 'maps_url' => ['nullable', 'url:http,https', 'max:500']]);
        $this->access->locked($pharmacy, function () use ($request, $pharmacy, $data) {
            $this->master->authorizeEither($pharmacy, $request->user(), ['pharmacy.update', 'pharmacy.update_limited']);
            $before = $pharmacy->only(array_keys($data));
            $pharmacy->update($data);
            $this->access->audit($pharmacy, $request->user()->id, 'pharmacy.contact_updated', "pharmacy:$pharmacy->id", ['before' => $before, 'after' => $data]);
        });

        return $this->done($request, $pharmacy, 'Kontak disimpan.');
    }
}
