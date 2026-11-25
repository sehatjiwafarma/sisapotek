@extends('layouts.app')
@section('title','Laporan operasional')
@include('operations.assets')
@push('styles')<link rel="stylesheet" href="{{ asset('css/reports.css') }}?v={{ filemtime(public_path('css/reports.css')) }}">@endpush
@section('content')
<p class="clinical-note">Laporan operasional mencakup penjualan, pembelian, persediaan, dan arus dana. Rekap psikotropika serta laba rugi tersedia melalui menu tersendiri sesuai izin.</p>
@php
    $money = fn($value) => 'Rp '.number_format($value,2,',','.');
    $date = fn($value) => \Illuminate\Support\Carbon::parse($value)->format('d/m/Y');
    $period = $kind === 'inventory' ? 'Posisi stok · '.$date($today) : $date($from).' – '.$date($to);
    $metrics = [['label'=>$kind === 'inventory' ? 'Jumlah produk' : 'Jumlah transaksi','value'=>number_format($summary['count'],0,',','.'),'hint'=>'Sesuai hasil filter']];
    if ($kind === 'inventory') {
        $metrics = array_merge($metrics,[
            ['label'=>'Stok minimum','value'=>number_format($summary['low'],0,',','.'),'hint'=>'Produk di bawah / sama dengan minimum','tone'=>'attention'],
            ['label'=>'Mendekati kedaluwarsa','value'=>number_format($summary['near'],0,',','.'),'hint'=>'Produk dengan batch dekat ED','tone'=>'attention'],
            ['label'=>'Kedaluwarsa','value'=>number_format($summary['expired'],0,',','.'),'hint'=>'Produk dengan batch kedaluwarsa','tone'=>'negative'],
        ]);
    } else {
        $metrics[] = ['label'=>['sales'=>'Penjualan bersih','purchases'=>'Pembelian bersih','finance'=>'Mutasi dana bersih'][$kind],'value'=>$money($summary['amount']),'hint'=>['sales'=>'Setelah refund, termasuk PPN','purchases'=>'Penerimaan dikurangi retur, termasuk PPN','finance'=>'Mutasi masuk dikurangi keluar'][$kind],'tone'=>$summary['amount'] < 0 ? 'negative' : 'primary'];
        if ($kind === 'sales') {
            $metrics[] = ['label'=>'Refund pasien','value'=>$money($summary['refunds']),'hint'=>'Refund pada periode dan hasil filter'];
            $metrics[] = ['label'=>'Estimasi margin obat setelah refund','value'=>$summary['margin'] === null ? 'Belum tersedia' : $money(\App\Services\CashManagement::amount($summary['margin'])),'hint'=>$summary['margin'] === null ? 'Biaya batch belum lengkap' : 'Tidak termasuk PPN, jasa, dan biaya operasional'];
        } elseif ($kind === 'finance') {
            $metrics[] = ['label'=>'Sisa utang terkonfirmasi','value'=>$money($summary['debt']),'hint'=>'Posisi saat ini · seluruh tagihan terkonfirmasi','current'=>true];
            $metrics[] = ['label'=>'Dana retur supplier','value'=>$money($summary['supplier_refunds']),'hint'=>'Belum diterima · posisi saat ini','current'=>true];
        }
    }
    $categories = ['GENERAL'=>'Penjualan umum','PRESCRIPTION'=>'Penebusan resep','REFUND'=>'Refund pasien','SERVICE'=>'Jasa layanan','SALE'=>'Penjualan obat','SERVICE_BILL'=>'Jasa layanan','SALE_REFUND'=>'Refund pasien','EXPENSE'=>'Pengeluaran','SUPPLIER_PAYMENT'=>'Pembayaran supplier','SUPPLIER_RECOVERY'=>'Dana retur supplier','DEPOSIT'=>'Tambahan modal'];
    $statuses = ['cash'=>'Tunai','transfer'=>'Transfer','qris'=>'QRIS','debit'=>'Debit','PAID'=>'Lunas','COMPLETED'=>'Refund selesai','POSTED'=>'Tercatat'];
@endphp
<div class="proc-page op-page reports-page">
    <header class="heading reports-heading">
        <div><span class="eyebrow">KEUANGAN / LAPORAN OPERASIONAL</span><h1>Dashboard & laporan</h1><p>Pantau transaksi dan persediaan di {{ $currentPharmacy->name }}.</p></div>
        @if($access->allows('reports.export'))
            <a class="btn reports-export" href="{{ route('reports.index',array_merge(['pharmacy'=>$currentPharmacy],request()->except(['page','export']),['kind'=>$kind,'export'=>'xlsx'])) }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 3v12m-4-4 4 4 4-4M5 16v5h14v-5"/></svg>Ekspor Excel</a>
        @endif
    </header>

    <nav class="reports-tabs" aria-label="Jenis laporan">
        @foreach($types as $key=>$label)
            <a href="{{ route('reports.index',[$currentPharmacy,'kind'=>$key,'from'=>$from,'to'=>$to]) }}" @if($kind === $key) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>

    <section class="card reports-filter-card" aria-labelledby="reports-filter-title">
        <div class="reports-filter-heading"><h2 id="reports-filter-title">Filter laporan</h2><span>{{ $currentPharmacy->timezone }}</span></div>
        <form method="get" class="reports-filters" data-live-filter data-today="{{ $today }}">
            <input type="hidden" name="kind" value="{{ $kind }}">
            <input type="hidden" name="sort" value="{{ request('sort',$kind === 'inventory' ? 'number' : 'date') }}">
            <input type="hidden" name="direction" value="{{ request('direction','desc') }}">
            <div class="reports-filter-fields {{ $kind === 'inventory' ? 'reports-filter-inventory' : '' }}">
                @if($kind !== 'inventory')
                    <label>Dari tanggal<input type="date" name="from" value="{{ $from }}" required></label>
                    <label>Sampai tanggal<input type="date" name="to" value="{{ $to }}" required></label>
                @endif
                <label class="reports-search">{{ $kind === 'inventory' ? 'Cari produk' : 'Cari transaksi' }}<input type="search" name="q" maxlength="150" value="{{ request('q') }}" placeholder="{{ $kind === 'inventory' ? 'SKU atau nama produk…' : 'Nomor atau jenis transaksi…' }}"></label>
                <div class="reports-filter-actions"><button class="btn primary">{{ $kind === 'inventory' ? 'Tampilkan' : 'Terapkan periode' }}</button><a class="btn" href="{{ route('reports.index',[$currentPharmacy,'kind'=>$kind]) }}">Reset</a></div>
            </div>
            @if($kind !== 'inventory')
                <div class="date-presets reports-presets" data-date-presets hidden aria-label="Periode cepat"><span>Periode cepat</span><button class="btn small" type="button" data-date-preset="today">Hari ini</button><button class="btn small" type="button" data-date-preset="week">7 hari</button><button class="btn small" type="button" data-date-preset="month">Bulan ini</button></div>
            @else
                <p class="reports-stock-note">Menampilkan stok saat ini dalam satuan dasar. Filter periode tidak berlaku untuk persediaan.</p>
            @endif
        </form>
    </section>

    <section class="reports-overview" aria-labelledby="reports-overview-title">
        <div class="reports-section-heading"><h2 id="reports-overview-title">Ringkasan {{ $types[$kind] }}</h2><span>{{ $period }}</span></div>
        <div class="reports-metrics {{ count($metrics) === 2 ? 'reports-metrics-pair' : '' }}">
            @foreach($metrics as $metric)
                <article class="reports-metric reports-metric-{{ $metric['tone'] ?? 'neutral' }}">
                    <div class="reports-metric-label"><h3>{{ $metric['label'] }}</h3>@if($metric['current'] ?? false)<span>Saat ini</span>@endif</div>
                    <strong class="reports-metric-value">{{ $metric['value'] }}</strong>
                    <p>{{ $metric['hint'] }}</p>
                </article>
            @endforeach
        </div>
        @if($kind === 'sales')
            <p class="reports-context"><span>Estimasi harga pokok obat</span><strong>{{ $summary['unknown'] ? 'Belum lengkap' : $money(\App\Services\CashManagement::amount($summary['cost'])) }}</strong><span>· Sesuai periode dan hasil filter</span></p>
        @elseif($kind === 'finance')
            <p class="reports-context"><span>Tagihan perlu konfirmasi saldo</span><strong>{{ number_format($summary['unconfirmed'],0,',','.') }} tagihan</strong><span>· Posisi saat ini, terpisah dari periode mutasi</span></p>
        @endif
    </section>

    <section class="card reports-results" aria-labelledby="reports-results-title">
        <header class="reports-results-heading">
            <div><h2 id="reports-results-title">{{ $kind === 'inventory' ? 'Rincian persediaan' : 'Rincian transaksi' }}</h2><p>{{ $period }}@if(request('q'))<span class="reports-query">Pencarian: {{ request('q') }}</span>@endif</p></div>
            <span class="reports-record-count">{{ number_format($records->total(),0,',','.') }} {{ $kind === 'inventory' ? 'produk' : 'transaksi' }}</span>
        </header>
        <div class="table-wrap"><table class="reports-table" data-table-sort="server" data-sort-columns="{{ $kind === 'inventory' ? 'number,category,status,amount,available,near,expired' : 'number,date,category,status,amount' }}" data-sort-default="{{ $kind === 'inventory' ? 'number' : 'date' }}" data-sort-default-direction="desc">
            <thead><tr><th>{{ $kind === 'inventory' ? 'SKU' : 'NOMOR' }}</th>
                @if($kind === 'inventory')<th>PRODUK</th><th>SATUAN</th><th class="reports-number">FISIK</th><th class="reports-number">LAYAK JUAL</th><th class="reports-number">DEKAT ED</th><th class="reports-number">KEDALUWARSA</th>
                @else<th>TANGGAL</th><th>JENIS</th><th>{{ $kind === 'finance' ? 'METODE' : 'STATUS' }}</th><th class="reports-number">NOMINAL</th>@endif
            </tr></thead>
            <tbody>@forelse($records as $row)
                <tr><td class="reports-reference">{{ $row->number }}</td>
                    @if($kind === 'inventory')
                        <td>{{ $row->category }}</td><td>{{ $row->status }}</td>
                        <td class="reports-number">{{ number_format($row->amount,4,',','.') }}</td><td class="reports-number">{{ number_format($row->available,4,',','.') }}</td><td class="reports-number">{{ number_format($row->near_expiry,4,',','.') }}</td><td class="reports-number {{ $row->expired > 0 ? 'op-negative' : '' }}">{{ number_format($row->expired,4,',','.') }}</td>
                    @else
                        <td class="reports-date">{{ $date($row->date) }}</td><td>{{ $categories[$row->category] ?? $row->category }}</td><td><span class="reports-status">{{ $statuses[$row->status] ?? $row->status }}</span></td><td class="reports-number {{ $row->amount < 0 ? 'op-negative' : '' }}">{{ $money($row->amount) }}</td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ $kind === 'inventory' ? 7 : 5 }}" class="reports-empty"><strong>Belum ada data yang sesuai</strong><p>Ubah kata kunci{{ $kind === 'inventory' ? '' : ' atau periode' }} untuk melihat hasil lainnya.</p></td></tr>
            @endforelse</tbody>
        </table></div>
        <footer class="reports-table-footer"><span>@if($records->total()){{ $records->firstItem() }}–{{ $records->lastItem() }} dari {{ number_format($records->total(),0,',','.') }} hasil @else 0 hasil @endif</span>{{ $records->links('partials.pagination') }}</footer>
    </section>

    <details class="reports-help"><summary>Cara membaca laporan {{ $types[$kind] }}</summary><div>
        @if($kind === 'inventory')<p>Stok saat ini per {{ $date($today) }}, dalam satuan dasar masing-masing produk. Pengaturan pengingat ED mengikuti master apotek; laporan ini bukan saldo historis periode.</p>
        @elseif($kind === 'finance')<p>Mutasi dana tersedia sejak modul keuangan dipasang. Modal awal shift tidak termasuk; tambahan modal ditandai terpisah. Pembelian tunai yang belum dicatat pembayarannya tidak dibuat sebagai kas keluar otomatis. Ini bukan laporan laba rugi. Sisa utang dan dana retur supplier merupakan posisi saat ini dan tidak mengikuti filter periode mutasi.</p>
        @elseif($kind === 'sales')<p>Nilai jasa hanya bagian layanan: obat pada tagihan gabungan dihitung sekali dari transaksi POS. Angka termasuk PPN dan dikurangi refund pada tanggal refund. Estimasi margin obat mengecualikan PPN, jasa, dan biaya operasional; harga pokok berdasarkan batch penerimaan, dengan pengurangan untuk barang retur yang kembali ke stok. Margin tidak ditampilkan jika ada batch tanpa harga beli.</p>
        @else<p>Nilai pembelian adalah penerimaan dikurangi retur supplier pada periode terpilih, termasuk PPN. Penerimaan dana retur supplier tidak dihitung ulang sebagai pengurangan pembelian.</p>@endif
    </div></details>
</div>
@endsection