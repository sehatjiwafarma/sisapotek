    <div class="personnel-current">
        <div class="personnel-current-label"><span class="eyebrow">PENANGGUNG JAWAB SAAT INI</span><span class="badge {{ $currentAssignment ? '' : 'neutral' }}">{{ $currentAssignment ? 'Sedang bertugas' : 'Belum ditetapkan' }}</span></div>
        @if($currentAssignment)
            <h3>{{ $currentAssignment->personnel?->name ?? $currentAssignment->credential_snapshot['name'] }}</h3>
            <p>Bertugas sejak {{ $currentAssignment->starts_on->format('d/m/Y') }}{{ $currentAssignment->ends_on ? ' sampai '.$currentAssignment->ends_on->format('d/m/Y') : ' tanpa tanggal akhir yang ditentukan' }}.</p>
            <dl class="master-definition">
                <div><dt>Nomor STR</dt><dd>{{ $currentAssignment->personnel?->str_number ?: 'Belum diisi' }}</dd></div>
                <div><dt>Nomor SIP</dt><dd>{{ $currentAssignment->personnel?->sip_number ?: 'Belum diisi' }}</dd></div>
                <div><dt>SIP berlaku sampai</dt><dd>{{ $currentAssignment->personnel?->sip_expires_on?->format('d/m/Y') ?? 'Belum diisi' }}</dd></div>
                <div><dt>Kelengkapan data apoteker</dt><dd>@if(!$currentAssignment->personnel?->active)Data apoteker nonaktif — perlu diperiksa
                    @elseif(!$currentAssignment->personnel?->str_number || !$currentAssignment->personnel?->sip_number || !$currentAssignment->personnel?->sip_expires_on)Belum lengkap
                    @elseif($currentAssignment->personnel->sip_expires_on->toDateString() < $today)SIP kedaluwarsa — perlu diperbarui
                    @else Data terisi, SIP belum kedaluwarsa
                    @endif</dd></div>
            </dl>
            @if($canManagePersonnel)
                <button type="button" class="btn personnel-manage-period" data-personnel-manage>Atur pergantian penanggung jawab</button>
            @endif
        @else
            <h3>Belum ada penanggung jawab untuk hari ini</h3>
            <p>Daftarkan apoteker, lalu klik Tetapkan sebagai APJ pada daftar di bawah untuk menentukan periode tugas.</p>
        @endif
    </div>
