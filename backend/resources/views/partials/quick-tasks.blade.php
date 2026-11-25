@php($quickTasks = collect([
    ['shifts.view','shifts.index','Shift & kas','Periksa shift sebelum transaksi tunai.'],
    ['sales.create','sales.create','Penjualan baru','Cari produk dan susun keranjang.'],
    ['visits.view','visits.index','Antrean pasien','Lanjutkan pendaftaran dan layanan.'],
    ['dispensing.view','dispensing.index','Antrean resep','Siapkan penebusan dan penyerahan obat.'],
    ['examinations.view','examinations.index','Pemeriksaan / SOAP','Lanjutkan dokumentasi pemeriksaan.'],
    ['billing.view','billing.index','Tagihan layanan','Periksa pembayaran yang perlu diselesaikan.'],
    ['payables.view','payables.index','Utang supplier','Periksa tagihan dan jatuh tempo.'],
    ['dashboard.view','reports.index','Laporan operasional','Lihat ringkasan dan hasil transaksi.'],
])->filter(fn($task) => $access->allows($task[0]) && (!str_ends_with($task[1],'.create') || $currentPharmacy->status === 'ACTIVE')))
@if($quickTasks->isNotEmpty())<section class="quick-tasks" aria-labelledby="quick-tasks-title"><h2 id="quick-tasks-title">Mulai pekerjaan</h2><div class="quick-task-grid">@foreach($quickTasks as [$permission,$target,$label,$description])<a class="quick-task" href="{{ route($target,$currentPharmacy) }}"><strong>{{ $label }}</strong><small>{{ $description }}</small></a>
@endforeach
</div></section>@endif
