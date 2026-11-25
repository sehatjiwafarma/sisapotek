@extends('layouts.app')
@section('title', 'Tugas SOAP')
@section('content')
<div class="heading"><div><span class="eyebrow">PELAYANAN PASIEN / DOKUMENTASI</span><h1>Tugas SOAP</h1><p>{{ $access->isOwner() ? 'Pantau SOAP yang belum difinalkan oleh seluruh dokter di apotek ini.' : 'Pemeriksaan Anda yang sudah memiliki resep final tetapi SOAP belum final.' }}</p></div><a class="btn" href="{{ route('examinations.index', $currentPharmacy) }}">Daftar pemeriksaan</a></div>
<div class="notice">Batas penyelesaian SOAP: <strong>{{ \App\Services\SoapReminders::hours($currentPharmacy) }} jam</strong> setelah resep pertama difinalkan. Membuka tugas tidak menandainya selesai. Tugas hilang otomatis setelah SOAP difinalkan.</div>
<section class="card">
    <form method="get" class="toolbar" data-live-filter>
        @include('inventory.sort-fields')
        <label>Status tugas<select name="status"><option value="pending" @selected(request('status', 'pending') === 'pending')>Semua yang belum selesai</option><option value="overdue" @selected(request('status') === 'overdue')>Terlambat</option></select></label>
        <button class="btn">Tampilkan</button><a class="btn" href="{{ route('soap-tasks.index', $currentPharmacy) }}">Reset</a>
    </form>
    <div class="table-wrap"><table data-table-sort="server" data-sort-columns="number,date,doctor,,," data-sort-default="date" data-sort-default-direction="asc">
        <thead><tr><th>Pemeriksaan</th><th>Resep pertama final</th><th>Dokter</th><th data-no-sort>Status SOAP</th><th data-no-sort>Batas waktu</th><th data-no-sort>Aksi</th></tr></thead>
        <tbody>@forelse($tasks as $task)
        <tr><td><strong>{{ $task->number }}</strong></td><td>{{ \Illuminate\Support\Carbon::parse($task->first_prescription_at)->timezone($currentPharmacy->timezone)->format('d/m/Y H:i') }}<small>{{ \Illuminate\Support\Carbon::parse($task->first_prescription_at)->locale('id')->diffForHumans() }}</small></td><td>{{ $task->doctor_name }}</td><td><span class="badge neutral">{{ $task->soap_status === 'DRAFT' ? 'SOAP masih draft' : 'SOAP belum diisi' }}</span></td><td>{{ \Illuminate\Support\Carbon::parse($task->first_prescription_at)->addHours(\App\Services\SoapReminders::hours($currentPharmacy))->timezone($currentPharmacy->timezone)->format('d/m/Y H:i') }}@if(\App\Services\SoapReminders::overdue($currentPharmacy, $task->first_prescription_at))<small class="soap-overdue">SOAP terlambat</small>@endif</td><td><a class="btn small" href="{{ route('examinations.show', [$currentPharmacy, $task->id]) }}">Lengkapi SOAP</a></td></tr>
        @empty<tr><td colspan="6" class="empty">Tidak ada tugas SOAP yang sesuai filter.</td></tr>@endforelse</tbody>
    </table></div>
    <div class="pagination"><span>{{ $tasks->total() }} tugas</span>@include('partials.pagination', ['paginator' => $tasks])</div>
</section>
@endsection
