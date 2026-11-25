@if($soapReminders && $soapReminders['total'] > 0)
<section class="soap-reminder-card" aria-labelledby="soap-reminder-heading">
    <div><span class="eyebrow">TUGAS DOKUMENTASI</span><h2 id="soap-reminder-heading">Ada {{ $soapReminders['total'] }} pemeriksaan yang SOAP-nya belum difinalkan.</h2>
    <p>{{ $access->isOwner() ? 'Pantau penyelesaian SOAP seluruh dokter di apotek ini.' : 'Lengkapi catatan pemeriksaan yang menjadi tanggung jawab Anda.' }} @if($soapReminders['overdue'])<strong>{{ $soapReminders['overdue'] }} tugas melewati batas waktu.</strong>@endif</p></div>
    <a class="btn primary" href="{{ route('soap-tasks.index', $currentPharmacy) }}">{{ $access->isOwner() ? 'Pantau SOAP' : 'Lengkapi SOAP' }}</a>
</section>
@endif
