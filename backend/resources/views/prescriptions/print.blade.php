<!doctype html>
<html lang="id">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Resep {{ $prescription->number }}</title><link rel="stylesheet" href="{{ asset('css/prescription-print.css') }}?v={{ filemtime(public_path('css/prescription-print.css')) }}"><script src="{{ asset('js/prescription-print.js') }}" defer></script></head>
<body>
<div class="print-toolbar"><button type="button" id="print-prescription">Cetak resep</button><span>A4 portrait · Pilih skala 100% saat mencetak</span></div>
<main class="prescription-sheet">
    <header class="letterhead">
        @if($currentPharmacy->logo_path && \Illuminate\Support\Facades\Storage::disk('local')->exists($currentPharmacy->logo_path))<img class="pharmacy-logo" src="{{ route('master.brand-logo', $currentPharmacy) }}" alt="Logo {{ $currentPharmacy->name }}">@endif
        <h1>PRAKTEK DOKTER MANDIRI</h1>
        @if($currentPharmacy->address)<p>{{ $currentPharmacy->address }}</p>@endif
        @if($currentPharmacy->phone)<p>Telp. {{ $currentPharmacy->phone }}</p>@endif
        <h2>RESEP DOKTER</h2><p>Berlaku sampai: {{ $prescription->valid_until ? \Illuminate\Support\Carbon::parse($prescription->valid_until)->format('d/m/Y') : 'Belum ditetapkan' }}</p>
    </header>
    @php
        $patient = $payload['patient'];
        $issuedAt = \Illuminate\Support\Carbon::parse(($prescription->finalized_at ?? $prescription->ready_at))->timezone($currentPharmacy->timezone);
        $birthDate = !empty($patient['birth_date']) ? \Illuminate\Support\Carbon::parse($patient['birth_date']) : null;
    @endphp
    <dl class="identity">
        <div><dt>No. resep</dt><dd>{{ $prescription->number }}</dd></div>
        <div><dt>Nama pasien</dt><dd><strong>{{ $patient['name'] }}</strong></dd></div>
        <div><dt>No. rekam medis</dt><dd>{{ $patient['medical_record_number'] }}</dd></div>
        <div><dt>Tanggal lahir / umur</dt><dd>{{ $birthDate ? $birthDate->format('d/m/Y').' / '.(int) $birthDate->diffInYears($issuedAt).' tahun' : 'Belum tercatat' }}</dd></div>
        <div><dt>Jenis kelamin</dt><dd>{{ \App\Services\PrescriptionPrint::sex($patient['sex'] ?? null) }}</dd></div>
        <div><dt>Alamat / telepon</dt><dd>{{ $patient['address'] ?? 'Belum tercatat' }} / {{ $patient['phone'] ?? '—' }}</dd></div>
        <div class="identity-gap"><dt>Tanggal resep</dt><dd>{{ $issuedAt->format('d/m/Y H:i') }}</dd></div>
        <div><dt>Dokter</dt><dd>{{ $payload['doctor']['name'] }}</dd></div>
        <div><dt>SIP</dt><dd>{{ $payload['doctor']['sip_number'] ?: 'Belum tercatat' }}</dd></div>
        @if(array_key_exists('allergies', $payload))<div><dt>Riwayat alergi</dt><dd>{{ $payload['allergies'] ?: 'Belum tercatat' }}</dd></div>@endif
    </dl>
    <table class="medicines"><thead><tr><th>Nama obat / aturan pakai</th><th class="quantity">Jumlah</th></tr></thead><tbody>
        @foreach($payload['items'] as $item)
        <tr><td><div class="drug-name"><span class="rx">R/</span><strong>{{ $item['name'] }}</strong></div><div>{{ \App\Support\ProductComposition::label($currentPharmacy, $item['product_id'] ?? null) }}</div><div class="directions">{{ implode(' · ', array_filter([$item['dose'] ?? '', $item['frequency'] ?? '', $item['route'] ?? ''])) }}@if(!empty($item['instructions']))<br>{{ $item['instructions'] }}@endif</div></td><td class="quantity"><strong>{{ (float) $item['quantity'] }}</strong><br><span>{{ $item['unit'] }}</span></td></tr>
        @endforeach
    </tbody></table>
    @if(!empty($payload['notes']))<section class="notes"><strong>Catatan resep</strong><p>{{ $payload['notes'] }}</p></section>@endif
    <section class="sign-off">
        <div class="signatures">
            <div class="recipient"><p>Penerima</p><div class="signature-space"></div><p>(...................................)</p><small>Telah menerima obat<br>dan informasi obat</small></div>
            <div class="doctor"><p>Dokter</p><img class="qr-code" src="{{ $qrCode }}" alt="QR identitas dokter, resep, dan pasien">@if($prescription->signature_id)<img class="doctor-signature" src="{{ route('prescriptions.signature', [$currentPharmacy, $visit->id, 'prescription' => $prescription->id]) }}" alt="Tanda tangan dokter">@else<small>Belum ada gambar tanda tangan dokter.</small>@endif<p class="doctor-name">{{ $payload['doctor']['name'] }}</p><small>SIP: {{ $payload['doctor']['sip_number'] ?: 'Belum tercatat' }}</small></div>
        </div>
        <p class="qr-caption">Pindai QR untuk membaca identitas dokter, nomor resep, data pasien, dan nomor rekam medis.</p>
    </section>
</main>
</body></html>
