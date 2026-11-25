@extends('layouts.app')
@section('title', 'Supplier')
@include('procurement.assets')
@section('content')
<div class="proc-page">
<div class="heading"><div><span class="eyebrow">PEMBELIAN / MASTER PEMASOK</span><h1>Supplier</h1><p>Kelola kontak pemasok dan termin pembayaran di {{ $currentPharmacy->name }}.</p></div>@if($access->allows('suppliers.create'))<a class="btn primary" href="{{ route('suppliers.create', $currentPharmacy) }}">+ Tambah supplier</a>@endif</div>
<section class="card">
@include('procurement.filters', ['placeholder'=>'Nama supplier, kode, atau telepon','statuses'=>['active'=>'Aktif','inactive'=>'Nonaktif'],'reset'=>route('suppliers.index', $currentPharmacy)])
<div class="table-wrap"><table data-table-sort="server" data-sort-columns="name,contact,phone,terms,status," data-sort-default="name"><thead><tr><th>SUPPLIER / KODE</th><th>KONTAK PIC</th><th>TELEPON / EMAIL</th><th>TERMIN</th><th>STATUS</th><th>AKSI</th></tr></thead><tbody>
@forelse($suppliers as $supplier)<tr><td><strong>{{ $supplier->name }}</strong><small>{{ $supplier->code }}</small></td><td>{{ $supplier->contact_name ?: 'Belum diisi' }}</td><td>{{ $supplier->phone ?: '—' }}<small>{{ $supplier->email }}</small></td><td>{{ $supplier->payment_days ? $supplier->payment_days.' hari' : 'Tunai' }}</td><td><span class="badge {{ $supplier->active ? '' : 'neutral' }}">{{ $supplier->active ? 'Aktif' : 'Nonaktif' }}</span></td><td><div class="actions">
@if($access->allows('suppliers.update'))<a class="btn small" href="{{ route('suppliers.edit', [$currentPharmacy, $supplier]) }}">Edit</a>@endif
@if($access->allows('suppliers.deactivate'))<form method="post" action="{{ route('suppliers.status', [$currentPharmacy, $supplier]) }}" data-confirm="{{ $supplier->active ? 'Nonaktifkan' : 'Aktifkan' }} supplier {{ $supplier->name }}? Supplier nonaktif tidak dapat dipilih untuk PO baru." data-confirm-label="{{ $supplier->active ? 'Nonaktifkan' : 'Aktifkan' }}" data-confirm-variant="{{ $supplier->active ? 'danger' : 'primary' }}">@csrf @method('PATCH')<input type="hidden" name="active" value="{{ $supplier->active ? 0 : 1 }}"><button class="btn small {{ $supplier->active ? 'danger' : '' }}">{{ $supplier->active ? 'Nonaktifkan' : 'Aktifkan' }}</button></form>@endif
</div></td></tr>@empty<tr><td class="empty" colspan="6">Belum ada supplier yang sesuai. Tambahkan pemasok untuk mulai membuat pembelian.</td></tr>@endforelse
</tbody></table></div>@include('procurement.footer', ['rows'=>$suppliers])</section></div>
@endsection
