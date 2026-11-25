@extends('layouts.app')
@section('title','Laporan laba rugi')
@section('content')
@php($money = fn ($value) => $value === null ? 'Belum dapat dihitung' : 'Rp '.number_format($value / 100, 2, ',', '.'))
<div class="heading"><div><span class="eyebrow">KEUANGAN / KHUSUS OWNER</span><h1>Laporan laba rugi</h1><p>Ringkasan operasional {{ $currentPharmacy->name }} berdasarkan periode transaksi.</p></div></div>
<section class="card padded">
<form method="get" data-live-filter class="actions">
<label>Dari tanggal<input type="date" name="from" value="{{ $from }}" required></label>
<label>Sampai tanggal<input type="date" name="to" value="{{ $to }}" required></label>
<button class="btn primary">Tampilkan</button><a class="btn" href="{{ route('profit-loss.index', $currentPharmacy) }}">Reset</a>
</form>
</section>
@if($summary['unknown'])
<div class="notice warning">Ada {{ $summary['unknown'] }} pergerakan stok tanpa harga pokok yang dapat ditelusuri. HPP dan laba tidak ditampilkan agar tidak menyesatkan.</div>
@endif
<section class="card padded"><h2>Ringkasan periode {{ \Illuminate\Support\Carbon::parse($from)->format('d/m/Y') }} – {{ \Illuminate\Support\Carbon::parse($to)->format('d/m/Y') }}</h2>
<dl>
@foreach([
    'Penjualan obat bersih (tanpa PPN)' => $summary['revenue'],
    'Pendapatan layanan' => $summary['services'],
    'Total pendapatan bersih' => $summary['revenue'] + $summary['services'],
    'Harga pokok penjualan (HPP)' => $summary['unknown'] ? null : $summary['cost'],
    'Estimasi laba kotor' => $summary['gross'],
    'Pengeluaran operasional' => $summary['expenses'],
    'Estimasi laba bersih operasional' => $summary['net'],
] as $label => $value)
<div class="profit-loss-row"><dt>{{ $label }}</dt><dd>{{ $money($value) }}</dd></div>
@endforeach
</dl></section>
<section class="card padded"><h2>Dasar perhitungan</h2><p>Hanya penjualan dan tagihan layanan lunas, refund selesai, serta pengeluaran berstatus POSTED yang dihitung. Penjualan obat sudah dikurangi diskon, refund, dan PPN. Pendapatan layanan tidak menghitung ulang komponen obat pada tagihan.</p><p>HPP menggunakan harga beli lot yang ditelusuri dari penerimaan, tanpa PPN pembelian; retur yang kembali ke stok mengurangi HPP. Pembelian stok dan pembayaran utang supplier tidak langsung dibebankan sebagai biaya.</p><p class="muted">Laporan ini merupakan estimasi operasional, bukan laporan akuntansi lengkap. Penyusutan, pajak penghasilan, dan penyesuaian lain yang belum dicatat sebagai pengeluaran tidak termasuk.</p></section>
@endsection
@push('styles')
<style>.profit-loss-row{display:flex;justify-content:space-between;gap:16px;padding:14px 0;border-bottom:1px solid var(--border)}.profit-loss-row dd{margin:0;text-align:right;font-weight:600}.profit-loss-row:last-child{border-bottom:0;font-weight:700;color:var(--primary)}@media(max-width:680px){.profit-loss-row{flex-direction:column;gap:6px}.profit-loss-row dd{text-align:left}}</style>
@endpush
