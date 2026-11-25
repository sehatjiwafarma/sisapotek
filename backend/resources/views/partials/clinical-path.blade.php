@php
    $pathVisit = $visit ?? null;
    $pathPrescription = $prescription ?? null;
    $pathVisitId = $pathVisit?->id ?? $pathPrescription?->visit_id;
    $pathSoap = $soapStatus ?? ($exam->status ?? null);
    $pathRx = $prescriptionStatus ?? $pathPrescription?->status;
@endphp
@if($pathVisitId)
<nav class="clinical-path" aria-label="Alur layanan pasien">
    <strong>{{ $pathVisit->patient_name ?? $payload['patient']['name'] ?? 'Layanan pasien' }} · {{ $pathVisit->medical_record_number ?? $payload['patient']['medical_record_number'] ?? '' }}</strong>
    @if($access->allows('visits.view'))<a href="{{ route('visits.show',[$currentPharmacy,$pathVisitId]) }}" @if(request()->routeIs('visits.*')) aria-current="page" @endif>Kunjungan</a>@endif
    @if(($canReadExamination ?? false) || request()->routeIs('examinations.*'))<a href="{{ route('examinations.show',[$currentPharmacy,$pathVisitId]) }}" @if(request()->routeIs('examinations.*','prescriptions.*')) aria-current="page" @endif>Pemeriksaan</a>
    @elseif(($canReadPrescription ?? false) || request()->routeIs('prescriptions.*'))<a href="{{ route('prescriptions.show',[$currentPharmacy,$pathVisitId]) }}">Resep</a>@endif
    @if($pathPrescription?->status === 'FINAL' && $access->allows('dispensing.view'))<a href="{{ route('dispensing.show',[$currentPharmacy,$pathPrescription->id]) }}" @if(request()->routeIs('dispensing.*')) aria-current="page" @endif>Penebusan</a>@endif
    @if($access->allows('billing.view') && isset($serviceBill) && $serviceBill)<a href="{{ route('billing.show',[$currentPharmacy,$serviceBill->id]) }}">Tagihan</a>@endif
    <x-help-tooltip label="Informasi alur layanan">SOAP dan resep dilengkapi pada satu halaman. Layanan selesai, pembayaran lunas, dan obat diserahkan merupakan status yang berbeda.</x-help-tooltip>
</nav>
@endif
