@extends('layouts.app')
@section('title',$amend ? 'Amendemen pemeriksaan' : 'Isi draft SOAP')
@include('examinations.assets')
@push('master-scripts')<script src="{{ asset('js/examination-images.js') }}?v={{ filemtime(public_path('js/examination-images.js')) }}" defer></script>@endpush
@section('content')
<div class="products-page soap-page soap-compact">
    <div class="heading"><div><span class="eyebrow">LAYANAN PASIEN / PEMERIKSAAN</span><h1>{{ $amend ? 'Amendemen pemeriksaan' : 'Isi draft SOAP' }}</h1><p>{{ $amend ? 'Isi hasil koreksi lengkap beserta alasan amendemen.' : 'Simpan draft, tinjau hasil, lalu finalkan pemeriksaan.' }}</p></div><a class="btn" href="{{ route('examinations.show',[$currentPharmacy,$visit->id]) }}">Kembali ke pemeriksaan</a></div>
    @include('partials.clinical-path')
@include('examinations.context')
    <div class="soap-compact-intro"><p><strong>Keluhan pendaftaran:</strong> <span class="visit-text">{{ $visit->complaint }}</span></p><small>Pilih formulir SOAP atau gambar hasil pemeriksaan. Kolom terstruktur wajib saat finalisasi mode formulir.</small></div>
    <form method="post" action="{{ route($amend ? 'examinations.amend' : 'examinations.save',[$currentPharmacy,$visit->id]) }}" class="soap-compact-form" enctype="multipart/form-data" autocomplete="off" @if($amend) data-confirm="Simpan amendemen sebagai versi final baru? Versi sebelumnya tetap tersimpan dan kunjungan tidak dibuka ulang." data-confirm-title="Simpan amendemen" data-confirm-label="Simpan versi baru" data-confirm-variant="primary" @endif>
        @csrf @if(!$amend) @method('PUT') @endif<input type="hidden" name="revision" value="{{ $exam?->revision ?? 0 }}">
        @include('examinations.image-editor')
@include('examinations.intake')
        @foreach(['subjective'=>['S','Subjektif / anamnesis','Keluhan dan riwayat yang disampaikan pasien'], 'objective'=>['O','Temuan objektif','Hasil pemeriksaan fisik dan temuan lain'], 'assessment'=>['A','Diagnosis / assessment','Diagnosis atau penilaian berdasarkan pemeriksaan'], 'plan'=>['P','Rencana / tindakan','Rencana layanan, terapi, dan kontrol']] as $key=>[$letter,$label,$placeholder])
            <section class="card soap-compact-field" data-soap-structured><label for="soap-{{ $key }}"><span class="soap-letter" aria-hidden="true">{{ $letter }}</span><span>{{ $label }}{{ $amend && $key !== 'objective' ? ' *' : '' }}</span></label><textarea id="soap-{{ $key }}" name="{{ $key }}" rows="3" maxlength="10000" placeholder="{{ $placeholder }}"  @if($errors->has($key)) aria-invalid="true" aria-describedby="soap-error-{{ $key }}" @endif>{{ old($key,$payload[$key] ?? '') }}</textarea>@error($key)<p class="soap-error" id="soap-error-{{ $key }}">{{ $message }}</p>@enderror</section>
        @endforeach
        <section class="card soap-compact-field soap-span-full soap-followup"><label for="soap-followup">Catatan tindak lanjut <small>Opsional</small></label><textarea id="soap-followup" name="followup" rows="2" maxlength="10000" placeholder="Instruksi lanjutan atau jadwal kontrol bila diperlukan">{{ old('followup',$payload['followup'] ?? '') }}</textarea>@error('followup')<p class="soap-error">{{ $message }}</p>@enderror</section>
        @if($amend)<section class="card soap-compact-field soap-span-full soap-followup"><label for="soap-reason">Alasan amendemen *</label><textarea id="soap-reason" name="reason" required maxlength="2000" rows="2" placeholder="Jelaskan bagian yang dikoreksi dan alasannya">{{ old('reason') }}</textarea>@error('reason')<p class="soap-error">{{ $message }}</p>@enderror<small>Versi sebelumnya tetap tersimpan.</small></section>@endif
        <div class="soap-save soap-span-full"><div class="soap-save-description"><p>{{ $amend ? 'Amendemen disimpan sebagai versi final baru.' : 'Draft belum menyelesaikan kunjungan. Finalisasi dilakukan setelah meninjau hasil.' }}</p>@if($access->isOwner())<small>Akses Owner · Dokter mengikuti kunjungan, tindakan dicatat atas akun Anda.</small>@endif</div><div><a class="btn" href="{{ route('examinations.show',[$currentPharmacy,$visit->id]) }}">Batal</a><button class="btn primary">{{ $amend ? 'Simpan amendemen' : 'Simpan draft' }}</button></div></div>
    </form>
</div>
@endsection
