<?php

namespace App\Http\Controllers;

use App\Models\Pharmacy;
use App\Services\AccessManager;
use App\Support\TableSort;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PricingRuleController extends Controller
{
    private function authorizeRule(Pharmacy $pharmacy, string $kind, string $action): void
    {
        abort_unless(in_array($kind, ['discount', 'tax'], true), 404);
        $actor = auth()->id();
        $manager = app(AccessManager::class);
        $manager->actor($pharmacy, $actor, ($kind === 'tax' ? 'taxes' : 'discounts').'.view');
        $manager->actor($pharmacy, $actor, ($kind === 'tax' ? 'taxes' : 'discounts').'.'.$action);
    }

    private function query(Pharmacy $pharmacy, string $kind): Builder
    {
        return DB::table('pos_pricing_rules')->where('pharmacy_id', $pharmacy->id)->where('kind', $kind);
    }

    public function index(Request $request, Pharmacy $pharmacy, string $kind): View
    {
        $this->authorizeRule($pharmacy, $kind, 'view');
        $filters = $request->validate(['q' => 'nullable|string|max:150', 'status' => ['nullable', Rule::in(['active', 'inactive'])]]);
        $query = $this->query($pharmacy, $kind);
        if ($term = trim($filters['q'] ?? '')) {
            $query->where(fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($term).'%'])->orWhereRaw('LOWER(code) LIKE ?', ['%'.mb_strtolower($term).'%']));
        }
        if ($filters['status'] ?? null) {
            $query->where('active', $filters['status'] === 'active');
        }
        TableSort::apply($query, ['name' => 'name', 'code' => 'code', 'value' => 'value', 'status' => 'active'], 'name');

        return view('pricing.index', ['kind' => $kind, 'rules' => $query->orderBy('id')->paginate(15)->withQueryString()]);
    }

    public function form(Pharmacy $pharmacy, string $kind, ?string $rule = null): View
    {
        $this->authorizeRule($pharmacy, $kind, $rule ? 'update' : 'create');
        $row = $rule ? $this->query($pharmacy, $kind)->where('id', $rule)->first() : (object) ['id' => null, 'code' => '', 'name' => '', 'type' => 'percentage', 'value' => 0, 'tax_mode' => 'exclusive', 'is_default' => false, 'starts_on' => null, 'ends_on' => null];
        abort_unless($row, 404);

        return view('pricing.form', ['kind' => $kind, 'rule' => $row]);
    }

    public function save(Request $request, Pharmacy $pharmacy, string $kind, ?string $rule = null): RedirectResponse
    {
        $this->authorizeRule($pharmacy, $kind, $rule ? 'update' : 'create');
        if (is_string($request->input('code'))) {
            $request->merge(['code' => mb_strtoupper(trim($request->input('code')))]);
        }
        $data = $request->validate(['code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('pos_pricing_rules', 'code')->where('pharmacy_id', $pharmacy->id)->where('kind', $kind)->ignore($rule)],
            'name' => 'required|string|max:150', 'type' => ['required', Rule::in($kind === 'tax' ? ['percentage'] : ['percentage', 'nominal'])],
            'value' => 'required|numeric|min:0|max:10000000000000|decimal:0,2', 'tax_mode' => $kind === 'tax' ? ['required', Rule::in(['inclusive', 'exclusive'])] : 'nullable',
            'is_default' => 'required|boolean', 'starts_on' => 'nullable|date_format:Y-m-d', 'ends_on' => 'nullable|date_format:Y-m-d|after_or_equal:starts_on']);
        if ($data['type'] === 'percentage' && (float) $data['value'] > 100) {
            throw ValidationException::withMessages(['value' => 'Persentase maksimal 100%.']);
        }
        $data['tax_mode'] = $kind === 'tax' ? $data['tax_mode'] : null;
        try {
            app(AccessManager::class)->locked($pharmacy, function () use ($pharmacy, $kind, $rule, $data) {
                $this->authorizeRule($pharmacy, $kind, $rule ? 'update' : 'create');
                $before = $rule ? $this->query($pharmacy, $kind)->where('id', $rule)->lockForUpdate()->first() : null;
                abort_if($rule && ! $before, 404);
                if ($data['is_default'] && $before && ! $before->active) {
                    throw ValidationException::withMessages(['is_default' => 'Aktifkan master sebelum menjadikannya pilihan awal.']);
                }
                if ($data['is_default']) {
                    $this->query($pharmacy, $kind)->update(['is_default' => false]);
                }
                $id = $rule ?? (string) Str::uuid();
                if ($rule) {
                    $this->query($pharmacy, $kind)->where('id', $id)->update($data + ['updated_at' => now()]);
                } else {
                    DB::table('pos_pricing_rules')->insert($data + ['id' => $id, 'kind' => $kind, 'pharmacy_id' => $pharmacy->id, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
                }
                app(AccessManager::class)->audit($pharmacy, auth()->id(), 'pricing.'.($rule ? 'updated' : 'created'), $id, ['kind' => $kind, 'before' => $before, 'after' => $data]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => 'Kode sudah dipakai pada master ini.']);
        }

        return redirect()->route('pricing.index', [$pharmacy, $kind])->with('status', 'Master '.($kind === 'tax' ? 'PPN' : 'diskon').' disimpan.');
    }

    public function status(Request $request, Pharmacy $pharmacy, string $kind, string $rule): RedirectResponse
    {
        $this->authorizeRule($pharmacy, $kind, 'deactivate');
        $data = $request->validate(['active' => 'required|boolean']);
        app(AccessManager::class)->locked($pharmacy, function () use ($pharmacy, $kind, $rule, $data) {
            $this->authorizeRule($pharmacy, $kind, 'deactivate');
            $before = $this->query($pharmacy, $kind)->where('id', $rule)->lockForUpdate()->first();
            abort_unless($before, 404);
            $values = ['active' => $data['active'], 'updated_at' => now()];
            if (! $data['active']) {
                $values['is_default'] = false;
            }
            $this->query($pharmacy, $kind)->where('id', $rule)->update($values);
            app(AccessManager::class)->audit($pharmacy, auth()->id(), 'pricing.status_changed', $rule, ['kind' => $kind, 'active' => $data['active']]);
        });

        return back()->with('status', 'Status master diperbarui.');
    }
}
