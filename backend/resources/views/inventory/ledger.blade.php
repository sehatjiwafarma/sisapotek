@extends('layouts.app')
@section('title', 'Kartu stok')
@include('inventory.assets')
@section('content')
@php($qty = fn($value) => rtrim(rtrim(number_format((float)$value, 4, ',', '.'), '0'), ','))
<div class="products-page">
@include('inventory.header')
<div class="inventory-summary ledger-summary" aria-label="Ringkasan mutasi periode">
@foreach(['Saldo awal'=>$opening,'Masuk'=>$incoming,'Keluar'=>abs($outgoing),'Saldo akhir'=>$closing] as $label=>$value)<div><span>{{ $label }}</span><strong>{{ $qty($value) }}</strong><span>{{ $product->base_unit }}</span></div>@endforeach
</div>
<p class="inventory-note">Saldo awal mencakup mutasi sebelum {{ \Illuminate\Support\Carbon::parse($from)->format('d/m/Y') }}. Saldo akhir mencakup mutasi sampai {{ \Illuminate\Support\Carbon::parse($to)->format('d/m/Y') }}. Saldo fisik termasuk barang kedaluwarsa. Saldo berjalan mengikuti urutan tanggal transaksi, waktu pencatatan, lalu ID.</p>
<section class="card"><form class="toolbar product-filters" method="get" data-live-filter>
@include('inventory.sort-fields')
<label>Dari tanggal<input type="date" name="from" value="{{ $from }}" required></label><label>Sampai tanggal<input type="date" name="to" value="{{ $to }}" min="{{ $from }}" required></label><button class="btn">Tampilkan</button><a class="btn" href="{{ route('inventory.ledger',[$currentPharmacy,$product]) }}">Bulan ini</a>
</form>
@if($movements->isNotEmpty())<div class="table-wrap"><table class="product-table" data-table-sort="server" data-sort-columns="date,,,,,," data-sort-default="date"><thead><tr><th>TANGGAL</th><th>SUMBER MUTASI</th><th>BATCH / LOKASI</th><th>MASUK</th><th>KELUAR</th><th>SALDO</th><th>SATUAN</th></tr></thead><tbody>
@foreach($movements as $movement)
<tr><td>{{ \Illuminate\Support\Carbon::parse($movement->occurred_on)->format('d/m/Y') }}</td><td><strong>{{ ['SUPPLIER_RETURN'=>'Retur ke supplier','PURCHASE_RECEIPT'=>'Penerimaan pembelian','SALE'=>($movement->sale_kind === 'PRESCRIPTION' ? 'Penebusan resep' : 'Penjualan umum'),'SALE_RETURN'=>'Retur penjualan','STOCKTAKE_IN'=>'Koreksi opname masuk','STOCKTAKE_OUT'=>'Koreksi opname keluar'][$movement->type] ?? $movement->type }}</strong>
@if($movement->receipt_id && $access->allows('receipts.view'))<a href="{{ route('receipts.show',[$currentPharmacy,$movement->receipt_id]) }}">{{ $movement->receipt_number }}</a>
@elseif($movement->return_id && $access->allows('returns.view') && ($movement->return_kind !== 'PRESCRIPTION' || $access->allows('dispensing.view')))<a href="{{ route('returns.show',[$currentPharmacy,$movement->return_id]) }}">{{ $movement->return_number }}</a>
@elseif($movement->sale_id && $access->allows('sales.view') && ($movement->sale_kind !== 'PRESCRIPTION' || $access->allows('dispensing.view')))<a href="{{ route('sales.show',[$currentPharmacy,$movement->sale_id]) }}">{{ $movement->sale_number }}</a>
@elseif($movement->stocktake_id && $access->allows('stocktakes.view'))<a href="{{ route('stocktakes.show',[$currentPharmacy,$movement->stocktake_id]) }}">{{ $movement->stocktake_number }}</a>
@else<small>{{ $movement->receipt_number ?: $movement->sale_number ?: $movement->stocktake_number ?: '—' }}</small>@endif
</td><td>{{ $movement->batch_number ?: '—' }}<small>{{ $movement->location ?: 'Lokasi tidak dicatat' }}</small></td><td class="inventory-number">{{ $movement->quantity > 0 ? $qty($movement->quantity) : '—' }}</td><td class="inventory-number">{{ $movement->quantity < 0 ? $qty(abs($movement->quantity)) : '—' }}</td><td class="inventory-number"><strong>{{ $qty($movement->balance) }}</strong></td><td>{{ $movement->base_unit }}</td></tr>
@endforeach
</tbody></table></div><div class="product-list-footer"><span>{{ $movements->total() }} mutasi pada periode ini</span>{{ $movements->links('partials.pagination') }}</div>
@else<div class="product-empty"><h2>Tidak ada mutasi pada periode ini</h2><p>Ubah rentang tanggal untuk menelusuri transaksi lainnya. Saldo awal dan akhir tetap ditampilkan.</p></div>@endif
</section></div>
@endsection
