@extends('layouts.app')
@section('title', 'Riwayat transaksi')
@include('sales.assets')
@section('content')
<div class="products-page">
    <div class="heading"><div><span class="eyebrow">KASIR / POS</span><h1>Riwayat transaksi <x-help-tooltip label="Informasi Riwayat transaksi">Setiap resep tampil terpisah. Resep dengan nomor pembayaran yang sama dibayar bersama dan dihitung satu kali dalam laporan keuangan. Nilai per resep mencakup alokasi diskon dan PPN.</x-help-tooltip></h1></div>@if($access->allows('sales.create') && $currentPharmacy->status === 'ACTIVE')<a class="btn primary" href="{{ route('sales.create',$currentPharmacy) }}">+ Penjualan umum</a>@endif</div>
    <section class="card">
        <form class="toolbar product-filters" method="get" data-live-filter>
            @include('inventory.sort-fields')
            <label class="search-label">Cari transaksi<input type="search" name="q" maxlength="150" value="{{ request('q') }}" placeholder="Nomor transaksi, resep, atau pasien"></label>
            <label>Status<select name="status"><option value="">Semua status</option>@foreach(['DRAFT'=>'Draft','PAID'=>'Lunas','CANCELLED'=>'Batal'] as $value=>$label)<option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>@endforeach</select></label>
            <label>Dibuat sejak<input type="date" name="from" value="{{ request('from') }}"></label><label>Sampai<input type="date" name="to" value="{{ request('to') }}"></label>
            <button class="btn">Tampilkan</button><a class="btn" href="{{ route('sales.index',$currentPharmacy) }}">Reset</a>
        </form>
        @if($sales->isNotEmpty())
        <div class="table-wrap"><table class="product-table" data-table-sort="server" data-sort-columns="number,date,customer,,status," data-sort-default="date" data-sort-default-direction="desc"><thead><tr><th>TRANSAKSI</th><th>DIBUAT</th><th>PELANGGAN</th><th data-no-sort>NILAI RESEP / PENJUALAN</th><th>STATUS</th><th>AKSI</th></tr></thead><tbody>
        @foreach($sales as $sale)<tr><td><strong>{{ $sale->number }}</strong><small>{{ $sale->kind === 'PRESCRIPTION' ? 'Resep: '.($sale->rx_number ?? 'Tidak tersedia') : 'Penjualan umum' }}</small></td><td>{{ \Illuminate\Support\Carbon::parse($sale->created_at)->timezone($currentPharmacy->timezone ?: 'Asia/Jakarta')->format('d/m/Y H:i') }}</td><td>{{ $sale->kind === 'PRESCRIPTION' ? ($sale->patient_name ?: 'Tidak tersedia') : ($sale->customer_name ?: 'Umum') }}</td><td class="pos-number">Rp {{ number_format($sale->entry_total,2,',','.') }}@if($sale->kind === 'PRESCRIPTION')<small>Bagian dari pembayaran {{ $sale->number }}</small>@endif</td><td><span class="badge {{ $sale->status === 'DRAFT' ? 'neutral' : ($sale->status === 'CANCELLED' ? 'pos-status-cancelled' : '') }}">{{ ['DRAFT'=>'Draft','PAID'=>'Lunas','CANCELLED'=>'Batal'][$sale->status] }}</span></td><td><a class="btn small" href="{{ route('sales.show',[$currentPharmacy,$sale->id]) }}">{{ $sale->status === 'DRAFT' ? 'Periksa draft' : 'Detail' }}</a></td></tr>@endforeach
        </tbody></table></div><div class="product-list-footer"><span>{{ $sales->total() }} entri</span>{{ $sales->links('partials.pagination') }}</div>
        @else<div class="product-empty"><h2>Belum ada transaksi yang sesuai</h2><p>Buat penjualan baru atau ubah filter untuk menemukan transaksi.</p></div>@endif
    </section>
</div>
@endsection
