@extends('layouts.app')
@section('title', 'Pesanan pembelian')
@include('procurement.assets')
@section('content')
<div class="proc-page"><div class="heading"><div><span class="eyebrow">PEMBELIAN / PURCHASE ORDER</span><h1>Pesanan pembelian <x-help-tooltip label="Informasi Pesanan pembelian">Susun pesanan, pantau penerimaan, dan telusuri dokumen pembelian.</x-help-tooltip></h1></div>@if($access->allows('purchases.create') && $currentPharmacy->status === 'ACTIVE')<a class="btn primary" href="{{ route('purchases.create', $currentPharmacy) }}">+ Buat pembelian</a>@endif</div>
@if($currentPharmacy->status !== 'ACTIVE')<div class="notice warning">Apotek harus aktif untuk membuat dan memproses transaksi pembelian.</div>@endif
<section class="card">@include('procurement.filters', ['placeholder'=>'Nomor PO atau nama supplier','statuses'=>\App\Models\PurchaseOrder::STATUSES,'reset'=>route('purchases.index', $currentPharmacy)])
<div class="table-wrap"><table data-table-sort="server" data-sort-columns="number,date,supplier,total,status," data-sort-default="date" data-sort-default-direction="desc"><thead><tr><th>NOMOR PO</th><th>TANGGAL</th><th>SUPPLIER</th><th>TOTAL PESANAN</th><th>STATUS</th><th>AKSI</th></tr></thead><tbody>
@forelse($orders as $order)<tr><td><strong>{{ $order->number }}</strong></td><td>{{ $order->ordered_on->format('d/m/Y') }}</td><td>{{ $order->supplier_name }}</td><td class="proc-money">Rp {{ number_format($order->total,2,',','.') }}</td><td><span class="badge {{ in_array($order->status,['DRAFT','CANCELLED']) ? 'neutral' : '' }}">{{ \App\Models\PurchaseOrder::STATUSES[$order->status] }}</span></td><td><a class="btn small" href="{{ route('purchases.show', [$currentPharmacy,$order]) }}">Lihat detail</a></td></tr>@empty<tr><td class="empty" colspan="6">Belum ada pesanan yang sesuai. Buat draft pembelian untuk memulai.</td></tr>@endforelse
</tbody></table></div>@include('procurement.footer',['rows'=>$orders])</section></div>
@endsection
