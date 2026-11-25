@if($rx)
<section class="card return-audit">
    <h2>Retur penebusan {{ $rx['combined'] ? 'gabungan' : $rx['number'] }}</h2>
    @if($rx['combined'])
        <p>Transaksi memuat beberapa resep. Periksa identitas pasien dan resep pada setiap barang.</p>
    @else
        <p>Pasien: <strong>{{ $rx['patient']['name'] }}</strong> - {{ $rx['patient']['medical_record_number'] }}</p>
    @endif
    @if($originalRecipient)<p>Pengambil pada transaksi asal: {{ $originalRecipient['name'] }} - {{ $originalRecipient['type'] === 'self' ? 'Pasien sendiri' : $originalRecipient['relationship'] }}</p>@endif
    <p>Refund tidak otomatis menambah hak tebus. Pemulihan memerlukan persetujuan khusus setelah refund selesai.</p>
    @if(!$rx['combined'])<a class="btn small" href="{{ route('dispensing.show',[$currentPharmacy,$rx['id']]) }}">Sisa hak tebus & riwayat</a>@endif
</section>
@endif
