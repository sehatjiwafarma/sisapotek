<?php

namespace App\Http\Controllers;

use App\Models\Pharmacy;
use App\Services\AccessManager;
use App\Services\PsychotropicReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PsychotropicReportController extends Controller
{
    public function index(Request $request, Pharmacy $pharmacy, PsychotropicReport $reports)
    {
        $input = $request->validate(['year' => 'nullable|integer|min:2000|max:2100', 'month' => 'nullable|integer|min:1|max:12', 'export' => 'nullable|in:xlsx']);
        $today = now($pharmacy->timezone);
        $year = (int) ($input['year'] ?? $today->year);
        $month = (int) ($input['month'] ?? $today->month);
        $report = $reports->build($pharmacy, $year, $month);
        if ($request->input('export') === 'xlsx') {
            app(AccessManager::class)->actor($pharmacy, $request->user()->id, 'reports.export');
            if (! $report['rows'] || $report['unmapped']) {
                throw ValidationException::withMessages(['report' => 'Tandai produk psikotropika dan lengkapi kategori seluruh mutasi periode ini sebelum ekspor.']);
            }
            $bytes = $reports->excel($pharmacy, $report);
            app(AccessManager::class)->audit($pharmacy, $request->user()->id, 'psychotropic.report_exported', 'psychotropic', ['year' => $year, 'month' => $month, 'product_count' => count($report['rows'])]);

            return response($bytes)->header('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                ->header('Content-Disposition', 'attachment; filename="rekapitulasi-psikotropika-'.sprintf('%04d-%02d', $year, $month).'.xlsx"')->header('Cache-Control', 'private, no-store');
        }
        $movements = PsychotropicReport::movements($pharmacy, $report['from'], $report['to'])->orderBy('m.occurred_on')->orderBy('m.id')->paginate(20, ['*'], 'movements_page')->withQueryString();

        return view('operations.psychotropic', compact('report', 'year', 'month', 'movements'));
    }

    public function classify(Request $request, Pharmacy $pharmacy, string $movement, AccessManager $access)
    {
        $data = $request->validate(['category' => ['required', Rule::in(array_keys(PsychotropicReport::CATEGORIES))], 'reason' => 'required|string|min:5|max:500']);
        $access->locked($pharmacy, function () use ($request, $pharmacy, $movement, $data, $access) {
            $access->actor($pharmacy, $request->user()->id, 'reports.inventory');
            $access->actor($pharmacy, $request->user()->id, 'drugs.update');
            $row = DB::table('stock_movements as m')->join('products as p', 'p.id', '=', 'm.product_id')->where('m.pharmacy_id', $pharmacy->id)->where('p.pharmacy_id', $pharmacy->id)->where('p.is_psychotropic', true)->where('m.id', $movement)->first(['m.id']);
            abort_unless($row, 404);
            $existing = DB::table('psychotropic_movement_categories')->where('pharmacy_id', $pharmacy->id)->where('movement_id', $movement)->first();
            $values = $data + ['updated_by' => $request->user()->id, 'updated_at' => now()];
            if ($existing) {
                DB::table('psychotropic_movement_categories')->where('id', $existing->id)->update($values);
            } else {
                DB::table('psychotropic_movement_categories')->insert($values + ['id' => (string) Str::uuid(), 'pharmacy_id' => $pharmacy->id, 'movement_id' => $movement, 'created_at' => now()]);
            }
            $access->audit($pharmacy, $request->user()->id, 'psychotropic.movement_classified', $movement, ['before' => $existing?->category, 'after' => $data['category'], 'reason' => $data['reason']]);
        });

        return back()->with('status', 'Kategori pelaporan disimpan. Saldo stok tidak berubah.');
    }
}
