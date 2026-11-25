@if($soapReminders)
<details class="soap-notifications">
    <summary aria-label="Tugas SOAP: {{ $soapReminders['total'] }} belum selesai">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9 M10 21h4"/></svg>
        @if($soapReminders['total'])<span class="soap-notification-count">{{ $soapReminders['total'] > 99 ? '99+' : $soapReminders['total'] }}</span>@endif
    </summary>
    <div class="soap-notification-panel">
        <div class="soap-notification-heading"><strong>Tugas SOAP</strong><span>{{ $soapReminders['total'] }} belum selesai</span></div>
        @forelse($soapReminders['items'] as $task)
        <a class="soap-notification-item" href="{{ route('examinations.show', [$currentPharmacy, $task->id]) }}">
            <strong>{{ $task->number }}</strong>
            <span>{{ $task->soap_status === 'DRAFT' ? 'SOAP masih draft' : 'SOAP belum diisi' }} · {{ \Illuminate\Support\Carbon::parse($task->first_prescription_at)->locale('id')->diffForHumans() }}</span>
            @if(\App\Services\SoapReminders::overdue($currentPharmacy, $task->first_prescription_at))<small class="soap-overdue">SOAP terlambat</small>@endif
        </a>
        @empty<p class="soap-notification-empty">Tidak ada tugas SOAP yang tertunda.</p>@endforelse
        <a class="soap-notification-all" href="{{ route('soap-tasks.index', $currentPharmacy) }}">Lihat semua tugas SOAP →</a>
    </div>
</details>
@endif
