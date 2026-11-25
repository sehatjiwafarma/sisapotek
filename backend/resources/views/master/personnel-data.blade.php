@php
    $editingPersonnel = $submittedForm === 'create' || $pharmacy->personnel->contains(fn ($person) => $submittedForm === 'edit-'.$person->id);
@endphp
<section class="personnel-step">
    <div data-personnel-list @if($editingPersonnel) hidden @endif>
        <div class="personnel-list-toolbar">
            <div class="personnel-title-help"><h3>Daftar apoteker ({{ $pharmacy->personnel->count() }})</h3><x-help-tooltip label="Panduan pendaftaran apoteker">Daftarkan apoteker, lalu klik Tetapkan sebagai APJ pada baris apoteker yang dipilih. Lengkapi STR, SIP, dan masa berlaku SIP. Mengubah data apoteker tidak otomatis menetapkannya sebagai penanggung jawab.</x-help-tooltip></div>
            <button type="button" class="btn primary" data-personnel-editor-open="create" aria-controls="personnel-editor-create">+ Tambah Apoteker</button>
        </div>
        <div class="table-wrap personnel-list-table"><table>
            <thead><tr><th>NAMA APOTEKER</th><th>TELEPON</th><th>STR / SIP</th><th>SIP BERLAKU SAMPAI</th><th>STATUS</th><th>AKSI</th></tr></thead>
            <tbody>
                @forelse($pharmacy->personnel as $person)
                    @php
                        $isCurrent = $currentAssignment && $currentAssignment->personnel_id === $person->id;
                        $assignmentReason = !$person->active ? 'Data apoteker nonaktif' : (!$person->str_number ? 'STR belum diisi' : (!$person->sip_number ? 'SIP belum diisi' : (!$person->sip_expires_on ? 'Masa berlaku SIP belum diisi' : ($person->sip_expires_on->toDateString() < $today ? 'SIP kedaluwarsa' : null))));
                    @endphp
                    <tr><td><strong>{{ $person->name }}</strong>@if($isCurrent)<small class="personnel-current-marker">Penanggung jawab saat ini</small>@endif</td><td>{{ $person->phone ?: '—' }}</td><td><span>STR: {{ $person->str_number ?: 'Belum diisi' }}</span><br><span>SIP: {{ $person->sip_number ?: 'Belum diisi' }}</span></td><td>{{ $person->sip_expires_on?->format('d/m/Y') ?? 'Belum diisi' }}@if($person->sip_expires_on && $person->sip_expires_on->toDateString() < $today)<small class="personnel-expired">Kedaluwarsa</small>@endif</td><td><span class="badge {{ $person->active ? '' : 'neutral' }}">{{ $person->active ? 'Aktif' : 'Nonaktif' }}</span></td><td><div class="personnel-row-actions">
    @if(!$isCurrent)
        @if($assignmentReason)
            <span class="personnel-unavailable">{{ $assignmentReason }}</span>
        @else
            <button type="button" class="btn" data-assignment-open="{{ $person->id }}" data-person-name="{{ $person->name }}" aria-haspopup="dialog" aria-controls="personnel-assignment-dialog">Tetapkan sebagai APJ</button>
        @endif
    @endif
    <span class="help-tooltip">
        <button type="button" class="btn personnel-icon-action" data-personnel-editor-open="edit-{{ $person->id }}" aria-controls="personnel-editor-edit-{{ $person->id }}" aria-label="Ubah data {{ $person->name }}" aria-describedby="personnel-edit-tip-{{ $person->id }}" data-help-tooltip><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m15 5 4 4M4 20l4-1L20 7a2.8 2.8 0 0 0-4-4L4 15l-1 6 5-2"/></svg></button>
        <span id="personnel-edit-tip-{{ $person->id }}" class="help-tooltip-content" role="tooltip" popover="manual">Ubah data apoteker</span>
    </span>
    <form method="post" action="{{ route('master.personnel.delete', [$pharmacy, $person->id]) }}" data-confirm="Hapus data apoteker {{ $person->name }}? Tindakan ini tidak dapat dibatalkan. Apoteker dengan riwayat penugasan tidak dapat dihapus." data-confirm-title="Hapus apoteker" data-confirm-label="Ya, hapus apoteker" data-confirm-variant="danger">
        @csrf @method('DELETE')<input type="hidden" name="tab" value="personnel"><input type="hidden" name="_personnel_form" value="delete">
        <span class="help-tooltip">
            <button class="btn personnel-icon-action danger" aria-label="Hapus data {{ $person->name }}" aria-describedby="personnel-delete-tip-{{ $person->id }}" data-help-tooltip><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7m4-7v7"/></svg></button>
            <span id="personnel-delete-tip-{{ $person->id }}" class="help-tooltip-content" role="tooltip" popover="manual">Hapus data apoteker</span>
        </span>
    </form>
</div></td></tr>
                @empty<tr><td class="empty" colspan="6">Belum ada apoteker terdaftar. Klik Tambah Apoteker untuk menambahkan data.</td></tr>@endforelse
            </tbody>
        </table></div>
    </div>

    <div id="personnel-editor-create" data-personnel-editor="create" @if($submittedForm !== 'create') hidden @endif>
        <div class="personnel-editor-heading personnel-title-help"><h3>Tambah Apoteker</h3><x-help-tooltip label="Panduan data apoteker">Lengkapi STR, SIP, dan masa berlaku SIP agar apoteker dapat dipilih untuk penugasan.</x-help-tooltip></div>
        <form method="post" action="{{ route('master.personnel', $pharmacy) }}">
            @csrf<input type="hidden" name="tab" value="personnel"><input type="hidden" name="active" value="1"><input type="hidden" name="_personnel_form" value="create">
            @include('master.personnel-fields', ['person' => null, 'formKey' => 'create'])
            <div class="personnel-editor-actions"><button type="button" class="btn" data-personnel-editor-cancel>Batal</button><button class="btn primary">Simpan apoteker</button></div>
        </form>
    </div>

    @foreach($pharmacy->personnel as $person)
        <div id="personnel-editor-edit-{{ $person->id }}" data-personnel-editor="edit-{{ $person->id }}" @if($submittedForm !== 'edit-'.$person->id) hidden @endif>
            <div class="personnel-editor-heading personnel-title-help"><h3>Ubah data apoteker</h3><x-help-tooltip label="Tentang perubahan data apoteker">Mengubah data apoteker tidak otomatis mengubah periode penugasan atau data izin yang sudah tercatat dalam riwayat.</x-help-tooltip></div>
            <form method="post" action="{{ route('master.personnel.update', [$pharmacy, $person->id]) }}">
                @csrf @method('PUT')<input type="hidden" name="tab" value="personnel"><input type="hidden" name="_personnel_form" value="edit-{{ $person->id }}">
                @include('master.personnel-fields', ['formKey' => 'edit-'.$person->id])
                <label>Status data apoteker<select name="active"><option value="1" @selected((int)($submittedForm === 'edit-'.$person->id ? old('active') : $person->active) === 1)>Aktif — dapat dipilih untuk penugasan</option><option value="0" @selected((int)($submittedForm === 'edit-'.$person->id ? old('active') : $person->active) === 0)>Nonaktif — tidak dapat dipilih untuk penugasan baru</option></select></label>
                <div class="personnel-editor-actions"><button type="button" class="btn" data-personnel-editor-cancel>Batal</button><button class="btn primary">Simpan perubahan</button></div>
            </form>
        </div>
    @endforeach
</section>
