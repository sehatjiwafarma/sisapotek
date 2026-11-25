<section class="card dispensing-transaction-context">
    <div class="dispensing-transaction-info">
        <h2>Resep dalam transaksi</h2>
        <ul>@foreach($prescriptions as $rx)<li><a href="{{ route('dispensing.show',[$currentPharmacy,$rx->id]) }}">{{ $rx->number }}</a><span>{{ $rx->patient_name }}</span></li>@endforeach</ul>
        @if($sale->handed_over_at)<p>Obat sudah diserahkan.</p>@endif
    </div>
    <div class="dispensing-transaction-actions">
        <a class="btn small" href="{{ route('dispensing.show',[$currentPharmacy,$prescription->id]) }}">Sisa resep & penyerahan</a>
        @if($sale->status === 'PAID' && $access->allows('dispensing.print'))<a class="btn small" target="_blank" rel="noopener" href="{{ route('dispensing.labels',[$currentPharmacy,$sale->id]) }}">Cetak etiket</a>@endif
    </div>
</section>
