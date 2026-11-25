<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\Pharmacy;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SoapReminders
{
    public static function firstPrescriptions(Pharmacy $pharmacy): Builder
    {
        return DB::table('prescriptions')->where('pharmacy_id', $pharmacy->id)->where(fn ($q) => $q->where('status', 'FINAL')->orWhereNotNull('ready_at'))
            ->select('visit_id')->selectRaw('MIN(COALESCE(finalized_at, ready_at)) as first_prescription_at')->groupBy('visit_id');
    }

    public static function hours(Pharmacy $pharmacy): int
    {
        return $pharmacy->settings?->soap_reminder_hours ?? 24;
    }

    public static function eligible(?Membership $member): bool
    {
        return $member?->allows('examinations.view') && ($member->isOwner() || ClinicalMaster::doctors($member->pharmacy)->where('user_id', $member->user_id)->where('active', true)->exists());
    }

    public static function query(Pharmacy $pharmacy, Membership $member): Builder
    {
        $query = DB::table('visits')->where('visits.pharmacy_id', $pharmacy->id)
            ->whereIn('visits.status', ['IN_PROGRESS', 'COMPLETED'])
            ->joinSub(self::firstPrescriptions($pharmacy), 'soap_rx', 'soap_rx.visit_id', '=', 'visits.id')
            ->leftJoin('examinations as soap_exam', function ($join) use ($pharmacy) {
                $join->on('soap_exam.visit_id', '=', 'visits.id')->where('soap_exam.pharmacy_id', $pharmacy->id);
            })->whereNull('soap_exam.id');
        if ($member->pharmacy_id !== $pharmacy->id || ! $member->allows('examinations.view')) {
            return $query->whereRaw('1 = 0');
        }
        if (! $member->isOwner()) {
            $query->whereIn('visits.doctor_id', ClinicalMaster::doctors($pharmacy)->where('user_id', $member->user_id)->where('active', true)->select('id'));
        }

        return $query->select('visits.id', 'visits.number', 'visits.doctor_name', 'visits.visit_date', 'soap_rx.first_prescription_at', 'soap_exam.status as soap_status');
    }

    public static function overdue(Pharmacy $pharmacy, ?string $firstPrescription): bool
    {
        return $firstPrescription && Carbon::parse($firstPrescription)->addHours(self::hours($pharmacy))->lte(now());
    }

    public static function summary(Pharmacy $pharmacy, Membership $member): array
    {
        $query = self::query($pharmacy, $member);

        return ['total' => (clone $query)->count(),
            'overdue' => (clone $query)->where('soap_rx.first_prescription_at', '<=', now()->subHours(self::hours($pharmacy)))->count(),
            'items' => $query->orderBy('soap_rx.first_prescription_at')->orderBy('visits.id')->limit(5)->get()];
    }
}
