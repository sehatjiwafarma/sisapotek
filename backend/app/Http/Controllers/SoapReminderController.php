<?php

namespace App\Http\Controllers;

use App\Models\Pharmacy;
use App\Services\AccessManager;
use App\Services\SoapReminders;
use App\Support\TableSort;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SoapReminderController extends Controller
{
    public function index(Request $request, Pharmacy $pharmacy)
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(['pending', 'overdue'])]]);
        $query = SoapReminders::query($pharmacy, $request->attributes->get('membership'));
        if (($data['status'] ?? null) === 'overdue') {
            $query->where('soap_rx.first_prescription_at', '<=', now()->subHours(SoapReminders::hours($pharmacy)));
        }
        TableSort::apply($query, ['number' => 'visits.number', 'date' => 'soap_rx.first_prescription_at', 'doctor' => 'visits.doctor_name'], 'date', 'asc');
        app(AccessManager::class)->audit($pharmacy, $request->user()->id, 'soap_tasks.list_viewed', 'soap_tasks');

        return view('examinations.soap-tasks', ['tasks' => $query->orderBy('visits.id')->paginate(15)->withQueryString()]);
    }
}
