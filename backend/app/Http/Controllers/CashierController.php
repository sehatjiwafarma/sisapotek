<?php

namespace App\Http\Controllers;

use App\Models\Pharmacy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CashierController extends Controller
{
    public function index(Request $request, Pharmacy $pharmacy)
    {
        $access = $request->attributes->get('membership');
        abort_unless(collect(['sales.view', 'sales.create', 'billing.view', 'dispensing.view'])->contains(fn ($permission) => $access->allows($permission)), 403);
        $bills = $access->allows('billing.view') ? DB::table('service_bills')->join('visits', 'visits.id', '=', 'service_bills.visit_id')
            ->where('service_bills.pharmacy_id', $pharmacy->id)->where('service_bills.status', 'DRAFT')
            ->select('service_bills.id', 'service_bills.number', 'service_bills.total', 'visits.patient_name', 'service_bills.created_at')->orderByDesc('service_bills.created_at')->orderByDesc('service_bills.id')->limit(50)->get() : collect();
        $sales = $access->allows('sales.view') ? DB::table('sales')
            ->leftJoin('prescriptions as rx', fn ($join) => $join->on('rx.id', '=', 'sales.prescription_id')->on('rx.pharmacy_id', '=', 'sales.pharmacy_id'))
            ->leftJoin('visits as visit', fn ($join) => $join->on('visit.id', '=', 'rx.visit_id')->on('visit.pharmacy_id', '=', 'sales.pharmacy_id'))
            ->where('sales.pharmacy_id', $pharmacy->id)->where('sales.status', 'DRAFT')
            ->when(! $access->allows('dispensing.view'), fn ($query) => $query->where('sales.kind', 'GENERAL'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('service_bills')->whereColumn('service_bills.sale_id', 'sales.id')->whereColumn('service_bills.pharmacy_id', 'sales.pharmacy_id')->where('service_bills.status', 'DRAFT'))
            ->select('sales.id', 'sales.number', 'sales.total', 'sales.kind', 'sales.customer_name', 'sales.created_at', 'visit.patient_name')
            ->selectSub(DB::table('sale_items')->whereColumn('sale_items.sale_id', 'sales.id')->selectRaw('COUNT(DISTINCT sale_items.prescription_id)'), 'prescription_count')
            ->orderByDesc('sales.created_at')->orderByDesc('sales.id')->limit(50)->get() : collect();

        $transactions = $bills->map(fn ($bill) => ['type' => 'bill', 'record' => $bill])
            ->concat($sales->map(fn ($sale) => ['type' => 'sale', 'record' => $sale]))
            ->sortByDesc(fn ($entry) => $entry['record']->created_at.'|'.$entry['record']->id)->values();
        return view('cashier.index', compact('bills', 'sales', 'transactions'));
    }
}
