@extends('layouts.app')
@section('title', 'Terima barang')
@include('procurement.assets')
@section('content')
<div class="proc-page"><div class="heading"><div><span class="eyebrow">PEMBELIAN / PENERIMAAN</span><h1>Terima barang</h1><p>{{ $order->number }} · {{ $order->supplier_name }}</p></div><a class="btn" href="{{ route('purchases.show',[$currentPharmacy,$order]) }}">Kembali ke PO</a></div>
<div class="notice">Isi jumlah yang benar-benar diterima. Gunakan 0 untuk item yang belum tiba. Untuk batch berbeda, buat penerimaan terpisah.</div>
<form method="post" action="{{ isset($receipt) ? route('receipts.update',[$currentPharmacy,$receipt]) : route('receipts.store',[$currentPharmacy,$order]) }}">@csrf @if(isset($receipt)) @method('PUT') @endif
<section class="card"><header class="proc-section-header"><div class="help-heading"><h2>Dokumen penerimaan</h2><x-help-tooltip label="Informasi Dokumen penerimaan">Simpan draft untuk diperiksa sebelum stok diposting.</x-help-tooltip></div></header><div class="proc-fields">
@include('procurement.field',['label'=>'Tanggal penerimaan','name'=>'received_on','type'=>'date','required'=>true,'value'=>isset($receipt) ? $receipt->received_on->format('Y-m-d') : now($currentPharmacy->settings?->timezone ?? 'Asia/Jakarta')->toDateString()])
@include('procurement.field',['label'=>'Nomor faktur supplier','name'=>'invoice_number','max'=>80,'value'=>$receipt->invoice_number ?? ''])
</div></section>
<section class="card"><header class="proc-section-header"><div class="help-heading"><h2>Realisasi barang</h2><x-help-tooltip label="Informasi Realisasi barang">Qty dan harga aktual menggunakan satuan yang tercantum pada PO.</x-help-tooltip></div></header><div class="table-wrap"><table class="proc-receive-table"><thead><tr><th data-no-sort>PRODUK / SATUAN</th><th data-no-sort>SISA PO</th><th data-no-sort>DITERIMA</th><th data-no-sort>BATCH{{ $currentPharmacy->settings?->track_batch ? ' *' : '' }}</th><th data-no-sort>KEDALUWARSA{{ $currentPharmacy->settings?->track_expiry ? ' *' : '' }}</th><th data-no-sort>HARGA AKTUAL (RP)</th><th data-no-sort>RAK / LOKASI</th></tr></thead><tbody>
@foreach($order->items as $index=>$item)
@if((float)$item->quantity > (float)$item->received_quantity)
@php($existingLine = isset($receipt) ? $receipt->items->firstWhere('purchase_order_item_id', $item->id) : null)
<tr><td><strong>{{ $item->product_name }}</strong><small>{{ \App\Support\ProductComposition::label($currentPharmacy, $item->product_id ?? null) }}</small><small>{{ $item->brand }} · {{ $item->unit }}</small><input type="hidden" name="items[{{ $index }}][order_item_id]" value="{{ $item->id }}"></td><td>{{ (float)$item->quantity - (float)$item->received_quantity }} {{ $item->unit }}</td>
<td><input type="number" name="items[{{ $index }}][quantity]" value="{{ old('items.'.$index.'.quantity',$existingLine->quantity ?? 0) }}" min="0" max="{{ (float)$item->quantity - (float)$item->received_quantity }}" step="0.001" required aria-label="Jumlah diterima {{ $item->product_name }}"></td>
<td><input name="items[{{ $index }}][batch_number]" value="{{ old('items.'.$index.'.batch_number',$existingLine->batch_number ?? '') }}" maxlength="80" aria-label="Nomor batch {{ $item->product_name }}"></td>
<td><input type="date" name="items[{{ $index }}][expires_on]" value="{{ old('items.'.$index.'.expires_on',$existingLine->expires_on ?? '') }}" aria-label="Kedaluwarsa {{ $item->product_name }}"></td>
<td><input type="number" name="items[{{ $index }}][unit_price]" value="{{ old('items.'.$index.'.unit_price',$existingLine->unit_price ?? $item->unit_price) }}" min="0" max="10000000" step="0.01" required aria-label="Harga aktual {{ $item->product_name }}"></td>
<td><input name="items[{{ $index }}][location]" value="{{ old('items.'.$index.'.location',$existingLine->location ?? '') }}" maxlength="100" aria-label="Lokasi {{ $item->product_name }}"></td></tr>
@endif
@endforeach
</tbody></table></div></section>
<section class="card"><header class="proc-section-header"><div class="help-heading"><h2>PPN penerimaan</h2><x-help-tooltip label="Informasi PPN penerimaan">PPN ditambahkan pada subtotal barang diterima (qty ? harga aktual), tanpa menambahkan pajak PO kembali.</x-help-tooltip></div></header><div class="proc-fields">
<label>Dasar pembulatan PPN<select name="ppn_scope"><option value="total" @selected(old('ppn_scope', $receipt->ppn_scope ?? 'total') === 'total')>Total penerimaan</option><option value="product" @selected(old('ppn_scope', $receipt->ppn_scope ?? 'total') === 'product')>Per produk</option></select><small>Untuk PPN persentase, tarif sama diterapkan pada setiap produk; hasil dibulatkan per produk.</small></label><label>Jenis PPN<select name="ppn_type"><option value="nominal" @selected(old('ppn_type', $receipt->ppn_type ?? 'nominal') === 'nominal')>Nominal (Rp)</option><option value="percentage" @selected(old('ppn_type', $receipt->ppn_type ?? 'nominal') === 'percentage')>Persentase (%)</option></select>@error('ppn_type')<small class="proc-error">{{ $message }}</small>@enderror</label>
@include('procurement.field',['label'=>'Nilai PPN (sesuai jenis pilihan)','name'=>'ppn_value','type'=>'number','required'=>true,'value'=>$receipt->ppn_value ?? 0,'numberMax'=>100000000000,'step'=>'0.01'])
</div></section>
<section class="card"><div class="proc-fields"><div class="proc-full">@include('procurement.field',['label'=>'Catatan / alasan selisih','name'=>'notes','type'=>'textarea','value'=>$receipt->notes ?? '','hint'=>'Wajib jika penerimaan belum melengkapi seluruh sisa PO. Contoh: sisa barang menyusul pada pengiriman berikutnya.'])</div></div></section>
<div class="proc-save-bar"><span>Stok belum berubah sampai penerimaan diposting.</span><button class="btn primary">Simpan draft penerimaan</button></div>
</form></div>
@endsection
