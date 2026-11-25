@php
    $today = now($pharmacy->settings?->timezone ?? 'Asia/Jakarta')->toDateString();
    $assignments = $pharmacy->assignments->sortByDesc('starts_on');
    $currentAssignment = $assignments->first(fn ($item) => $item->starts_on->toDateString() <= $today && (!$item->ends_on || $item->ends_on->toDateString() >= $today));
    $canManagePersonnel = $can('pharmacy.personnel');
    $submittedForm = old('_personnel_form', '');
@endphp
<section class="card personnel-panel personnel-page" data-master-panel="personnel">
    <header class="personnel-header">
        <div><h2>Apoteker penanggung jawab <x-help-tooltip label="Informasi Apoteker penanggung jawab">Kelola data apoteker, penetapan tugas, dan riwayat pergantian dalam satu tempat.</x-help-tooltip></h2></div>
        <x-help-tooltip label="Tentang apoteker penanggung jawab">Apoteker penanggung jawab (APJ) adalah apoteker yang ditetapkan untuk bertanggung jawab atas kegiatan apotek. Pilih apoteker dari daftar untuk menetapkan periode tugas.</x-help-tooltip>
    </header>
    <div class="personnel-wizard-content">



    @include('master.personnel-current')
    @if($canManagePersonnel)
        @include('master.personnel-data')
        <dialog class="personnel-assignment-dialog personnel-panel" id="personnel-assignment-dialog" aria-labelledby="personnel-assignment-title" data-reopen="{{ $submittedForm === 'assign' ? 'true' : 'false' }}">
            <h3 id="personnel-assignment-title">Tetapkan penanggung jawab</h3>
            <p class="personnel-selected-name" data-assignment-name></p>
            <p class="muted">Tentukan periode tugas apoteker yang dipilih. Tanggal akhir boleh dikosongkan.</p>
            <div class="notice warning">Periode tidak boleh bertabrakan dengan penugasan lain. Akhiri periode sebelumnya melalui Riwayat penugasan; pengganti mulai paling cepat pada hari berikutnya.</div>
                    <form class="personnel-assign-form" method="post" action="{{ route('master.assign', $pharmacy) }}" data-confirm="Tetapkan apoteker ini sebagai penanggung jawab untuk periode yang dipilih?" data-confirm-title="Tetapkan penanggung jawab" data-confirm-label="Ya, tetapkan">
                        @csrf<input type="hidden" name="tab" value="personnel"><input type="hidden" name="_personnel_form" value="assign">
                        <div class="master-fields">
                            <input type="hidden" name="personnel_id" value="{{ old('_personnel_form') === 'assign' ? old('personnel_id') : '' }}" data-assignment-person>
                            <label>Tanggal mulai bertugas *<input type="date" name="starts_on" required value="{{ old('_personnel_form') === 'assign' ? old('starts_on') : '' }}"></label>
                            <div class="personnel-date-field"><div class="personnel-title-help"><label for="personnel-assignment-end">Tanggal terakhir bertugas (opsional)</label><x-help-tooltip label="Panduan tanggal akhir tugas">Kosongkan jika tanggal akhir belum ditentukan.</x-help-tooltip></div><input id="personnel-assignment-end" type="date" name="ends_on" value="{{ old('_personnel_form') === 'assign' ? old('ends_on') : '' }}"></div>
                        </div>
                        <div class="personnel-editor-actions"><button type="button" class="btn" data-assignment-cancel>Batal</button><button class="btn primary">Tetapkan penanggung jawab</button></div>
                    </form>
        </dialog>
    @endif

    <details class="personnel-history" id="personnel-history" @if(str_starts_with($submittedForm, 'end-') || request('personnel_step') === 'history') open @endif><summary><span>Riwayat penugasan ({{ $assignments->count() }})</span><svg class="personnel-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></summary>
        <div class="personnel-title-help"><h3>Kelola periode tugas & riwayat ({{ $assignments->count() }})</h3><x-help-tooltip label="Panduan periode dan riwayat tugas">Tanggal akhir termasuk hari terakhir bertugas. Contoh: jika tugas berakhir 10 November, pengganti mulai paling cepat 11 November. Data izin saat penetapan tetap tersimpan dalam riwayat.</x-help-tooltip></div>
        @forelse($assignments as $assignment)
            @php($assignmentStatus = $assignment->starts_on->toDateString() > $today ? 'Terjadwal' : ($assignment->ends_on && $assignment->ends_on->toDateString() < $today ? 'Selesai' : 'Bertugas hari ini'))
            <article class="personnel-period">
                <div class="personnel-period-heading"><h3>{{ $assignment->credential_snapshot['name'] ?? $assignment->personnel?->name }}</h3><span class="badge {{ $assignmentStatus === 'Bertugas hari ini' ? '' : 'neutral' }}">{{ $assignmentStatus }}</span></div>
                <p>{{ $assignment->starts_on->format('d/m/Y') }} — {{ $assignment->ends_on?->format('d/m/Y') ?? 'Tanggal akhir belum ditentukan' }}</p>
                <details><summary><span>Data izin saat ditetapkan</span><svg class="personnel-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></summary><p class="muted">STR: {{ $assignment->credential_snapshot['str_number'] ?? 'Belum diisi' }}<br>SIP: {{ $assignment->credential_snapshot['sip_number'] ?? 'Belum diisi' }}</p></details>
                @if(!$assignment->ends_on && $canManagePersonnel)
                    <form class="personnel-end-form" method="post" action="{{ route('master.assignments.end', [$pharmacy, $assignment->id]) }}" data-confirm="Tanggal yang Anda pilih menjadi hari terakhir tugas apoteker ini. Pengganti dapat mulai pada hari berikutnya. Lanjutkan?" data-confirm-title="Tentukan akhir tugas" data-confirm-label="Simpan tanggal akhir" data-confirm-variant="danger">
                        @csrf @method('PATCH')<input type="hidden" name="tab" value="personnel"><input type="hidden" name="_personnel_form" value="end-{{ $assignment->id }}">
                        <label>Tanggal terakhir bertugas *<input type="date" name="ends_on" min="{{ $assignment->starts_on->format('Y-m-d') }}" required value="{{ old('_personnel_form') === 'end-'.$assignment->id ? old('ends_on') : '' }}"></label>
                        <button class="btn danger">Simpan tanggal akhir tugas</button>
                    </form>
                @endif
            </article>
        @empty<div class="personnel-empty">Belum ada riwayat penugasan. Setelah ditetapkan, periode tugas akan tampil di sini.</div>@endforelse
    </details>
    </div>
</section>
