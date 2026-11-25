@extends('layouts.app')
@section('title','Administrasi master apotek')
@include('master.assets')
@section('content')
<div class="heading"><div><h1>Master apotek <x-help-tooltip label="Informasi Master apotek">Administrasi status apotek oleh Admin Sistem. Aktivasi memerlukan kelengkapan profil, legalitas dan APJ.</x-help-tooltip></h1></div><a class="btn primary" href="{{ route('master.create') }}">Tambah apotek</a></div>
<section class="card"><div class="table-wrap"><table data-table-sort="server" data-sort-columns="code,name,status," data-sort-default="name"><thead><tr><th>KODE</th><th>APOTEK</th><th>STATUS</th><th>AKSI</th></tr></thead><tbody>
@forelse($pharmacies as $pharmacy)
    @php($deactivationOpen = old('deactivate_pharmacy') === $pharmacy->id)
    <tr>
        <td>{{ $pharmacy->code }}</td><td><strong>{{ $pharmacy->name }}</strong><small>{{ $pharmacy->address }}</small></td>
        <td><span class="badge {{ $pharmacy->status === 'ACTIVE' ? '' : 'neutral' }}">{{ $pharmacy->status }}</span></td>
        <td><div class="actions">
            <a class="btn small" href="{{ route('master.show', $pharmacy) }}">Tinjau master</a>
            @if($pharmacy->status === 'ACTIVE')
                <button type="button" class="btn small danger" data-deactivate-open aria-expanded="{{ $deactivationOpen ? 'true' : 'false' }}" aria-controls="deactivate-{{ $pharmacy->id }}">Nonaktifkan</button>
            @else
                <form method="post" action="{{ route('master.activate', $pharmacy) }}" data-confirm="Aktifkan apotek {{ $pharmacy->name }}? Kelengkapan persyaratan akan diperiksa sebelum aktivasi." data-confirm-title="Aktifkan apotek" data-confirm-label="Ya, aktifkan">@csrf<button class="btn small primary">Aktifkan</button></form>
            @endif
        </div></td>
    </tr>
    @if($pharmacy->status === 'ACTIVE')
        <tr id="deactivate-{{ $pharmacy->id }}" data-deactivate-panel @if(!$deactivationOpen) hidden @endif><td colspan="4">
            <form class="master-deactivation-form" method="post" action="{{ route('master.deactivate', $pharmacy) }}" data-confirm="Nonaktifkan apotek {{ $pharmacy->name }} dan blokir transaksi baru? Riwayat data tetap tersedia sesuai izin." data-confirm-title="Nonaktifkan apotek" data-confirm-label="Ya, nonaktifkan" data-confirm-variant="danger">
                @csrf<input type="hidden" name="deactivate_pharmacy" value="{{ $pharmacy->id }}">
                <h3>Nonaktifkan {{ $pharmacy->name }}</h3><p class="muted">Apotek yang dinonaktifkan tidak dapat membuat transaksi baru. Tuliskan alasan untuk riwayat perubahan.</p>
                <label>Alasan penonaktifan *<textarea name="reason" required minlength="5" maxlength="2000" rows="3">{{ old('deactivate_pharmacy') === $pharmacy->id ? old('reason') : '' }}</textarea></label>
                <div class="actions"><button type="button" class="btn" data-deactivate-cancel>Batal</button><button class="btn danger">Nonaktifkan apotek</button></div>
            </form>
        </td></tr>
    @endif
@empty<tr><td class="empty" colspan="4">Belum ada apotek.</td></tr>@endforelse
</tbody></table></div><div class="pagination">{{ $pharmacies->links('partials.pagination') }}</div></section>
@endsection
