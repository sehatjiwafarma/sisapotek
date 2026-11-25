<?php

namespace App\Http\Controllers;

use App\Models\Pharmacy;
use App\Services\AccessManager;
use App\Services\ClinicalMaster;
use App\Support\TableSort;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DoctorController extends Controller
{
    private function record(Pharmacy $pharmacy, string $id): object
    {
        $record = ClinicalMaster::doctors($pharmacy)->where('id', $id)->first();
        abort_unless($record, 404);

        return $record;
    }

    public function index(Request $request, Pharmacy $pharmacy): View
    {
        $data = $request->validate(['q' => 'nullable|string|max:150', 'status' => ['nullable', Rule::in(['active', 'inactive'])]]);
        $query = ClinicalMaster::doctors($pharmacy)->select('id', 'code', 'name', 'specialty', 'sip_number', 'str_number', 'active');
        if ($term = trim($data['q'] ?? '')) {
            $query->where(function ($query) use ($term) {
                foreach (['name', 'code', 'specialty', 'sip_number', 'str_number'] as $field) {
                    $query->orWhereRaw('LOWER('.$field.') LIKE ?', ['%'.mb_strtolower($term).'%']);
                }
            });
        }
        if ($data['status'] ?? null) {
            $query->where('active', $data['status'] === 'active');
        }
        TableSort::apply($query, ['code' => 'code', 'name' => 'name', 'specialty' => 'specialty', 'sip' => 'sip_number', 'status' => 'active'], 'name');

        return view('doctors.index', ['doctors' => $query->orderBy('id')->paginate(15)->withQueryString()]);
    }

    public function create(Pharmacy $pharmacy): View
    {
        return view('doctors.form', ['doctor' => null, 'requestKey' => (string) Str::uuid()]);
    }

    public function edit(Pharmacy $pharmacy, string $doctor): View
    {
        return view('doctors.form', ['doctor' => $this->record($pharmacy, $doctor), 'requestKey' => null]);
    }

    public function show(Request $request, Pharmacy $pharmacy, string $doctor): View
    {
        $record = $this->record($pharmacy, $doctor);
        $access = $request->attributes->get('membership');
        $canViewSignatures = $access->allows('doctors.signature_view') || ($record->user_id === $request->user()->id && $access->allows('doctors.signature_update'));
        $current = $canViewSignatures ? ClinicalMaster::currentSignature($pharmacy, $record) : null;
        if ($current && ! $access->allows('doctors.signature_view') && $current->uploaded_by !== $request->user()->id) {
            $current = null;
        }
        $signatures = null;
        if ($canViewSignatures) {
            $query = DB::table('doctor_signatures')->where('doctor_id', $record->id)->select('id', 'version', 'valid_from', 'created_at', 'signing_revision');
            if (! $access->allows('doctors.signature_view')) {
                $query->where('uploaded_by', $request->user()->id);
            }
            TableSort::apply($query, ['version' => 'version', 'date' => 'created_at', 'start' => 'valid_from'], 'version', 'desc');
            $signatures = $query->orderBy('id')->paginate(10)->withQueryString();
        }
        $accounts = $access->allows('doctors.link_account') ? DB::table('memberships')->join('users', 'users.id', '=', 'memberships.user_id')
            ->where('memberships.pharmacy_id', $pharmacy->id)->where('memberships.active', true)->orderBy('users.name')->get(['users.id', 'users.name', 'users.email']) : collect();
        $accountName = $record->user_id ? DB::table('users')->where('id', $record->user_id)->value('name') : null;

        return view('doctors.show', ['doctor' => $record, 'signatures' => $signatures, 'currentSignature' => $current, 'accounts' => $accounts, 'accountName' => $accountName]);
    }

    private function validateDoctor(Request $request, bool $update): array
    {
        return $request->validate(['request_key' => $update ? 'nullable|uuid' : 'required|uuid', 'revision' => $update ? 'required|integer|min:1' : 'nullable',
            'name' => 'required|string|max:150', 'sip_number' => 'nullable|string|max:80', 'str_number' => 'nullable|string|max:80', 'specialty' => 'nullable|string|max:100', 'phone' => 'nullable|string|max:30']);
    }

    public function store(Request $request, Pharmacy $pharmacy, ClinicalMaster $master): RedirectResponse
    {
        $id = $master->saveDoctor($pharmacy, $request->user()->id, $this->validateDoctor($request, false));

        return redirect()->route('doctors.show', [$pharmacy, $id])->with('status', 'Dokter didaftarkan. Tautkan akun dokter sebelum mengunggah tanda tangan sendiri.');
    }

    public function update(Request $request, Pharmacy $pharmacy, string $doctor, ClinicalMaster $master): RedirectResponse
    {
        $master->saveDoctor($pharmacy, $request->user()->id, $this->validateDoctor($request, true), $doctor);

        return redirect()->route('doctors.show', [$pharmacy, $doctor])->with('status', 'Profil dokter diperbarui. Perubahan nama, SIP, atau STR memerlukan versi tanda tangan baru untuk penggunaan berikutnya.');
    }

    public function status(Request $request, Pharmacy $pharmacy, string $doctor, ClinicalMaster $master): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'active' => 'required|boolean']);
        $master->status($pharmacy, $request->user()->id, 'doctors', $doctor, (int) $data['revision'], (bool) $data['active']);

        return redirect()->route('doctors.show', [$pharmacy, $doctor])->with('status', 'Status dokter diperbarui.');
    }

    public function account(Request $request, Pharmacy $pharmacy, string $doctor, ClinicalMaster $master): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'user_id' => 'nullable|integer|min:1']);
        $master->linkDoctor($pharmacy, $request->user()->id, $doctor, (int) $data['revision'], isset($data['user_id']) ? (int) $data['user_id'] : null);

        return redirect()->route('doctors.show', [$pharmacy, $doctor])->with('status', 'Tautan akun diperbarui. Jika akun berubah, pemilik akun perlu mengunggah tanda tangan baru.');
    }

    public function signature(Request $request, Pharmacy $pharmacy, string $doctor, ClinicalMaster $master): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'valid_from' => 'required|date_format:Y-m-d',
            'signature' => ['required', 'file', 'image', 'mimes:png,jpg,jpeg', 'max:2048', 'dimensions:max_width=3000,max_height=1500']]);
        $master->signature($pharmacy, $request->user()->id, $doctor, (int) $data['revision'], $request->file('signature'), $data['valid_from']);

        return redirect()->route('doctors.show', [$pharmacy, $doctor])->with('status', 'Versi tanda tangan baru disimpan privat. Versi sebelumnya tetap tersimpan.');
    }

    public function image(Request $request, Pharmacy $pharmacy, string $doctor, string $signature, AccessManager $manager): BinaryFileResponse
    {
        $record = $this->record($pharmacy, $doctor);
        $access = $request->attributes->get('membership');
        abort_unless($access->allows('doctors.signature_view') || ($record->user_id === $request->user()->id && $access->allows('doctors.signature_update')), 403);
        $version = DB::table('doctor_signatures')->where('doctor_id', $doctor)->where('id', $signature)->first();
        abort_unless($version && Storage::disk('local')->exists($version->path), 404);
        abort_unless($access->allows('doctors.signature_view') || $version->uploaded_by === $request->user()->id, 403);
        $manager->audit($pharmacy, $request->user()->id, 'doctor.signature_viewed', $doctor, ['signature_id' => $signature]);

        return response()->file(Storage::disk('local')->path($version->path), ['Content-Type' => $version->mime, 'Content-Disposition' => 'inline; filename="signature.'.$version->version.($version->mime === 'image/png' ? '.png' : '.jpg').'"']);
    }
}
