<!doctype html><html lang="id"><head><meta charset="utf-8"><title>Surat pesanan {{ $order->number }}</title>
<style>
@page { margin: 18mm 17mm; }
body { font-family: 'DejaVu Serif', serif; font-size: 10pt; line-height: 1.25; color: #111; }
h1 { font-size: 19pt; margin: 0 0 3mm; } h2 { font-size: 12pt; margin: 0; text-decoration: underline; }
.kop { text-align: center; border-bottom: 2px solid #111; padding-bottom: 5mm; margin-bottom: 6mm; }
.logo { float: left; width: 18mm; height: 18mm; object-fit: contain; }
.title { text-align: center; margin-bottom: 4mm; } p { margin: 2mm 0; }
table { border-collapse: collapse; width: 100%; table-layout: fixed; }
.details { table-layout: auto; } .details td:first-child { width: 27mm; } .details td:nth-child(2) { width: 4mm; } .details td { vertical-align: top; padding: 0.5mm 0; overflow-wrap: break-word; } .label { width: 27mm; } .colon { width: 4mm; }
.items { margin: 3mm 0 5mm; } .items th, .items td { border: 1px solid #111; padding: 2mm; overflow-wrap: break-word; }
.items th { font-size: 9pt; } .items td { vertical-align: top; } thead { display: table-header-group; } tr { page-break-inside: avoid; }
.closing { page-break-inside: avoid; } .sign { margin-top: 5mm; margin-left: 95mm; text-align: center; } .space { height: 20mm; }
.draft { color: #a21c1c; font-weight: bold; letter-spacing: 2px; } small { font-size: 8pt; } .muted { color: #555; }
</style></head><body>
<div class="kop">@if($logo)<img class="logo" src="{{ $logo }}" alt="Logo apotek">@endif<h1>{{ $pharmacy->name }}</h1><div>{{ $pharmacy->address }}</div>@if($pharmacy->phone)<div>Telp: {{ $pharmacy->phone }}</div>@endif</div>
<div class="title"><h2>SURAT PESANAN {{ $data['kind'] === 'psikotropika' ? 'PSIKOTROPIKA' : 'OBAT' }}</h2><div>Nomor: {{ $order->number }}</div>@if($order->status === 'DRAFT')<div class="draft">DRAFT · BELUM DIKONFIRMASI</div>@endif</div>
<p>Yang bertanda tangan di bawah ini:</p>
<table class="details"><tr><td class="label">Nama</td><td class="colon">:</td><td>{{ $data['signer'] }}</td></tr><tr><td>Jabatan</td><td>:</td><td>Apoteker Penanggung Jawab</td></tr><tr><td>Alamat</td><td>:</td><td>{{ $data['signer_address'] }}</td></tr><tr><td>Telp</td><td>:</td><td>{{ $data['signer_phone'] ?: '—' }}</td></tr><tr><td>SIPA</td><td>:</td><td>{{ $data['sip'] }}</td></tr></table>
<p>Mengajukan pesanan {{ $data['kind'] === 'psikotropika' ? 'obat psikotropika' : 'obat' }} kepada:</p>
<table class="details"><tr><td class="label">Nama PBF</td><td class="colon">:</td><td>{{ $order->supplier_name }}</td></tr><tr><td>Alamat</td><td>:</td><td>{{ $data['supplier_address'] }}</td></tr></table>
<p>Jenis {{ $data['kind'] === 'psikotropika' ? 'obat psikotropika' : 'obat' }} yang akan dipesan adalah:</p>
<table class="items"><thead><tr><th style="width:6%">No</th><th style="width:24%">Nama Obat</th><th style="width:22%">Zat Aktif</th><th style="width:14%">Dosis</th><th style="width:10%">Satuan</th><th style="width:24%">Jumlah<br>(angka &amp; huruf)</th></tr></thead><tbody>
@foreach($order->items as $item)<tr><td>{{ $loop->iteration }}</td><td>{{ $item->product_name }}</td><td>{{ $data['ingredients'][$item->id] ?? '—' }}</td><td>{{ $data['strengths'][$item->id] ?? '—' }}</td><td>{{ $item->unit }}</td><td>{{ \App\Services\PurchaseLetter::quantity($item->quantity) }}</td></tr>@endforeach
</tbody></table>
<div class="closing"><p>{{ $data['kind'] === 'psikotropika' ? 'Obat psikotropika' : 'Obat' }} tersebut akan digunakan untuk keperluan:</p>
<table class="details"><tr><td class="label">Apotek</td><td class="colon">:</td><td>{{ $pharmacy->name }}</td></tr><tr><td>Alamat</td><td>:</td><td>{{ $pharmacy->address }}</td></tr><tr><td>SIA</td><td>:</td><td>{{ $pharmacy->license?->license_number ?: '—' }}</td></tr></table>
<div class="sign"><div>{{ $data['city'] }}, {{ $order->ordered_on->locale('id')->translatedFormat('d F Y') }}</div><div class="space"></div><div><u>{{ $data['signer'] }}</u></div><small>Apoteker Penanggung Jawab</small></div>
</div></body></html>
