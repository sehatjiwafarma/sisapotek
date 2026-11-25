@extends('layouts.app')
@section('title', 'Ekspor surat pesanan')
@include('procurement.assets')
@section('content')
<div class="proc-page letter-page">
    <div class="heading"><div><span class="eyebrow">PEMBELIAN / SURAT PESANAN</span><h1>Ekspor surat pesanan</h1><p>{{ $order->number }} · {{ $order->supplier_name }}</p></div><a class="btn" href="{{ route('purchases.show', [$currentPharmacy, $order]) }}">Kembali ke pesanan</a></div>
    @if($order->status === 'DRAFT')<div class="notice warning">Pesanan masih draft. PDF akan diberi penanda DRAFT sampai pesanan dikonfirmasi.</div>@endif
    <form method="post" action="{{ route('purchases.letter.export', [$currentPharmacy, $order]) }}" class="letter-form">@csrf
        <section class="card">
            <header class="proc-section-header"><div><h2>Informasi surat <x-help-tooltip label="Informasi Informasi surat">Pilih jenis surat dan kota yang akan dicantumkan pada dokumen.</x-help-tooltip></h2></div><span class="badge neutral">PDF · A4 landscape</span></header>
            <div class="proc-fields">
                <label>Jenis surat<select name="kind"><option value="obat" @selected(old('kind', 'obat') === 'obat')>Surat Pesanan Obat</option><option value="psikotropika" @selected(old('kind') === 'psikotropika')>Surat Pesanan Psikotropika</option></select></label>
                @include('procurement.field', ['label'=>'Kota penandatanganan', 'name'=>'city', 'required'=>true, 'max'=>150, 'value'=>$defaults['city']])
            </div>
        </section>
        <div class="letter-parties">
            <section class="card">
                <header class="proc-section-header"><div><h2>Penanggung jawab <x-help-tooltip label="Informasi Penanggung jawab">Periksa identitas dan izin praktik apoteker.</x-help-tooltip></h2></div></header>
                <div class="proc-fields">
                    <div class="proc-full">@include('procurement.field', ['label'=>'Nama apoteker penanggung jawab', 'name'=>'signer', 'required'=>true, 'max'=>150, 'value'=>$defaults['signer']])</div>
                    @include('procurement.field', ['label'=>'SIPA', 'name'=>'sip', 'required'=>true, 'max'=>100, 'value'=>$defaults['sip']])
                    @include('procurement.field', ['label'=>'Telepon', 'name'=>'signer_phone', 'type'=>'tel', 'max'=>30, 'value'=>$defaults['signer_phone']])
                    <div class="proc-full">@include('procurement.field', ['label'=>'Alamat penanggung jawab', 'name'=>'signer_address', 'type'=>'textarea', 'required'=>true, 'max'=>1000, 'value'=>$defaults['signer_address']])</div>
                </div>
            </section>
            <section class="card">
                <header class="proc-section-header"><div><h2>Tujuan pesanan <x-help-tooltip label="Informasi Tujuan pesanan">Identitas PBF penerima surat pesanan.</x-help-tooltip></h2></div></header>
                <div class="proc-fields">
                    <div class="proc-full letter-supplier"><span>Nama PBF</span><strong>{{ $order->supplier_name }}</strong></div>
                    <div class="proc-full">@include('procurement.field', ['label'=>'Alamat PBF', 'name'=>'supplier_address', 'type'=>'textarea', 'required'=>true, 'max'=>1000, 'value'=>$defaults['supplier_address']])</div>
                </div>
            </section>
        </div>
        <section class="card letter-items">
            <header class="proc-section-header"><div><h2>Rincian obat <x-help-tooltip label="Informasi Rincian obat">Lengkapi zat aktif dan dosis. Nama, satuan, serta jumlah mengikuti pesanan.</x-help-tooltip></h2></div><span class="badge neutral">{{ $order->items->count() }} item</span></header>
            <div class="table-wrap"><table><thead><tr><th data-no-sort>Obat</th><th data-no-sort>Zat aktif</th><th data-no-sort>Dosis / kekuatan</th><th data-no-sort>Satuan</th><th data-no-sort>Jumlah</th></tr></thead><tbody>
            @foreach($order->items as $item)
                <tr><td><strong>{{ $item->product_name }}</strong><small>{{ $item->brand }}</small></td>
                @foreach(['ingredients'=>'Zat aktif', 'strengths'=>'Dosis / kekuatan'] as $field=>$label)
                    <td><input aria-label="{{ $label }} {{ $item->product_name }}" name="{{ $field }}[{{ $item->id }}]" maxlength="500" placeholder="{{ $field === 'ingredients' ? 'Nama zat aktif' : 'Contoh: 2 mg' }}" value="{{ old($field.'.'.$item->id, $defaults[$field][$item->id]) }}" @error($field.'.'.$item->id) aria-invalid="true" @enderror>@error($field.'.'.$item->id)<small class="field-error">{{ $message }}</small>@enderror</td>
                @endforeach
                <td>{{ $item->unit }}</td><td>{{ \App\Services\PurchaseLetter::quantity($item->quantity) }}</td></tr>
            @endforeach
            </tbody></table></div>
            <div class="letter-note">Zat aktif dan dosis diisi dari master terkini. Perubahan pada form ini hanya berlaku untuk PDF yang diunduh.</div>
        </section>
        <div class="proc-save-bar letter-save-bar"><span>Tanda tangan dan stempel dibubuhkan pada hasil cetak.</span><div class="actions"><a class="btn" href="{{ route('purchases.show', [$currentPharmacy, $order]) }}">Batal</a><button type="submit" class="btn primary">Unduh PDF</button></div></div>
    </form>
</div>
@endsection
