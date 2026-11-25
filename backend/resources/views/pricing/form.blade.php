@extends('layouts.app')
@section('title', ($rule->id ? 'Edit' : 'Tambah').' master '.($kind === 'tax' ? 'PPN' : 'diskon'))
@include('sales.assets')
@section('content')
@php($label = $kind === 'tax' ? 'PPN' : 'diskon')
<div class="products-page"><div class="heading"><div><span class="eyebrow">PENGATURAN / MASTER POS</span><h1>{{ $rule->id ? 'Edit' : 'Tambah' }} master {{ $label }}</h1><p>Atur pilihan {{ $label }} untuk transaksi POS di apotek ini.</p></div><a class="btn" href="{{ route('pricing.index',[$currentPharmacy,$kind]) }}">Kembali</a></div>
<div class="pos-instructions">Diskon diterapkan lebih dahulu pada subtotal. PPN belum termasuk harga akan menambah total; PPN termasuk harga diuraikan dari nilai setelah diskon. Perubahan master mengharuskan draft terkait diperiksa dan disimpan ulang; transaksi lunas tetap memakai data tersimpan.</div>
<form class="card" style="max-width:760px" method="post" action="{{ $rule->id ? route('pricing.update',[$currentPharmacy,$kind,$rule->id]) : route('pricing.store',[$currentPharmacy,$kind]) }}">@csrf @if($rule->id) @method('PUT') @endif
<div class="pos-details">
<label>Kode *<input name="code" maxlength="30" pattern="[A-Za-z0-9_-]+" value="{{ old('code',$rule->code) }}" required><small>Huruf, angka, tanda hubung, atau underscore. Disimpan dalam huruf kapital.</small></label>
<label>Nama *<input name="name" maxlength="150" value="{{ old('name',$rule->name) }}" required placeholder="Nama yang tampil di POS dan struk"></label>
@if($kind === 'discount')<label>Jenis diskon *<select name="type"><option value="percentage" @selected(old('type',$rule->type) === 'percentage')>Persentase (%)</option><option value="nominal" @selected(old('type',$rule->type) === 'nominal')>Nominal (Rp)</option></select></label>@else<input type="hidden" name="type" value="percentage">@endif
<label>{{ $kind === 'tax' ? 'Tarif PPN (%)' : 'Nilai diskon (% atau Rp sesuai jenis)' }} *<input type="number" name="value" min="0" max="{{ $kind === 'tax' ? 100 : 10000000000000 }}" step="0.01" value="{{ old('value',$rule->value) }}" required><small>Persentase maksimal 100%, hingga 2 desimal. Tarif tidak diisi otomatis oleh sistem.</small></label>
@if($kind === 'tax')<label>Perlakuan harga *<select name="tax_mode"><option value="exclusive" @selected(old('tax_mode',$rule->tax_mode) === 'exclusive')>Belum termasuk PPN — ditambahkan ke total</option><option value="inclusive" @selected(old('tax_mode',$rule->tax_mode) === 'inclusive')>Sudah termasuk PPN — diuraikan dari harga</option></select></label>@endif
<label>Berlaku mulai (opsional)<input type="date" name="starts_on" value="{{ old('starts_on',$rule->starts_on) }}"></label><label>Berlaku sampai (opsional)<input type="date" name="ends_on" value="{{ old('ends_on',$rule->ends_on) }}"><small>Kosongkan untuk tanpa batas tanggal. Mengikuti zona waktu apotek.</small></label>
<label>Pilihan awal di POS<select name="is_default"><option value="0" @selected(!old('is_default',$rule->is_default))>Tidak</option><option value="1" @selected(old('is_default',$rule->is_default))>Ya, pilih otomatis pada transaksi baru</option></select><small>Hanya satu pilihan awal per jenis. Kasir tetap dapat memilih aturan lain atau tanpa aturan.</small></label>
<button class="btn primary">Simpan master {{ $label }}</button>
</div></form></div>
@endsection
