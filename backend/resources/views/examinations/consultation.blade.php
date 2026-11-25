@push('styles')<link rel="stylesheet" href="{{ asset('css/consultation-evidence.css') }}?v={{ filemtime(public_path('css/consultation-evidence.css')) }}">@endpush
@push('master-scripts')<script src="{{ asset('js/consultation-evidence.js') }}?v={{ filemtime(public_path('js/consultation-evidence.js')) }}" defer></script>@endpush
<section class="card consultation-card" id="bukti-konsultasi">
    <header class="clinical-section"><div class="help-heading"><h2>Bukti konsultasi online</h2><x-help-tooltip label="Informasi Bukti konsultasi online">Dokumentasi proses konsultasi, terpisah dari SOAP dan lampiran pemeriksaan.</x-help-tooltip></div>@if($evidenceRecord)<small>Versi {{ $evidenceRecord->version }} · {{ \Illuminate\Support\Carbon::parse($evidenceRecord->created_at)->timezone($currentPharmacy->timezone)->format('d/m/Y H:i') }} · {{ $evidenceRecord->creator_name }}</small>@endif</header>
    @if($canEditEvidence)
    <form method="post" enctype="multipart/form-data" action="{{ route('examinations.consultation.save', [$currentPharmacy, $visit->id]) }}" class="consultation-form" data-consultation-form @if($exam?->status === 'FINAL') data-confirm="Simpan koreksi bukti sebagai versi baru? Bukti sebelumnya dan SOAP final tetap tersimpan." data-confirm-title="Koreksi bukti konsultasi" data-confirm-label="Simpan versi baru" @endif>
        @csrf<input type="hidden" name="evidence_revision" value="{{ $evidenceRecord->version ?? 0 }}">
        <div class="consultation-fields">
            <label>Metode konsultasi<select name="method" data-consultation-method>@foreach(\App\Services\ConsultationEvidence::METHODS as $key=>$label)<option value="{{ $key }}" @selected(old('method', $evidenceData['method'] ?? 'in_person') === $key)>{{ $label }}</option>@endforeach</select></label>
            <label>Waktu konsultasi ({{ $currentPharmacy->timezone }})<input type="datetime-local" name="consulted_at" value="{{ old('consulted_at', $evidenceData['consulted_at'] ?? '') }}">@error('consulted_at')<small class="field-error">{{ $message }}</small>@enderror</label>
            <label class="consultation-full">Catatan konsultasi<textarea name="consultation_notes" rows="2" maxlength="3000">{{ old('consultation_notes', $evidenceData['notes'] ?? '') }}</textarea></label>
        </div>
        <div data-consultation-online>
            <p class="consultation-guidance">Unggah percakapan yang relevan dengan pasien ini. Hindari percakapan pribadi atau data pasien lain. JPG/PNG maksimal 5 MB per gambar; maksimal 6 gambar baru dan 10 gambar per versi.</p>
            <div class="consultation-gallery">
            @foreach($evidenceData['image_ids'] ?? [] as $image)
                <div class="consultation-image"><a href="{{ route('examinations.consultation.image', [$currentPharmacy, $visit->id, $image]) }}" target="_blank" rel="noopener"><img loading="lazy" src="{{ route('examinations.consultation.image', [$currentPharmacy, $visit->id, $image]) }}" alt="Screenshot bukti konsultasi tersimpan"></a><label><input type="checkbox" name="retained_images[]" value="{{ $image }}" @checked(in_array($image, old('retained_images', $evidenceData['image_ids'] ?? [])))> Sertakan di versi baru</label><label>Keterangan<input name="captions[{{ $image }}]" maxlength="300" value="{{ old('captions.'.$image, $evidenceData['captions'][$image] ?? '') }}"></label></div>
            @endforeach
            </div>
            <label>Tambah screenshot<input type="file" name="screenshots[]" multiple accept="image/png,image/jpeg" data-consultation-images></label>
            @error('screenshots')<small class="field-error">{{ $message }}</small>@enderror
            <p data-consultation-feedback class="field-error" role="status"></p><div class="consultation-gallery" data-consultation-previews></div>
            <label data-consultation-phone>Alasan tanpa screenshot (khusus konsultasi telepon)<textarea name="exception_reason" rows="2" maxlength="1000">{{ old('exception_reason', $evidenceData['exception_reason'] ?? '') }}</textarea><small>Jika telepon tanpa screenshot, alasan dan catatan konsultasi wajib diisi sebelum finalisasi.</small></label>
        </div>
        @if($exam?->status === 'FINAL')<label>Alasan penambahan / koreksi *<textarea name="change_reason" required minlength="5" maxlength="1000" rows="2">{{ old('change_reason') }}</textarea></label>@endif
        <div class="consultation-save"><small>Simpan bukti sebelum finalisasi SOAP. Screenshot tidak dicetak pada resep dan tidak dimasukkan ke QR.</small><button class="btn primary">Simpan bukti konsultasi</button></div>
    </form>
    @else
        <div class="clinical-section consultation-reading"><dl><div><dt>Metode</dt><dd>{{ \App\Services\ConsultationEvidence::METHODS[$evidenceData['method'] ?? ''] ?? 'Belum dicatat' }}</dd></div><div><dt>Waktu konsultasi</dt><dd>{{ !empty($evidenceData['consulted_at']) ? str_replace('T', ' ', $evidenceData['consulted_at']).' '.($evidenceData['timezone'] ?? '') : 'Belum dicatat' }}</dd></div></dl>
        @foreach(['notes'=>'Catatan konsultasi', 'exception_reason'=>'Alasan tanpa screenshot', 'change_reason'=>'Alasan koreksi'] as $key=>$label)@if(!empty($evidenceData[$key]))<h3>{{ $label }}</h3><p>{{ $evidenceData[$key] }}</p>@endif @endforeach
        <div class="consultation-gallery">@foreach($evidenceData['image_ids'] ?? [] as $image)<figure><a href="{{ route('examinations.consultation.image', [$currentPharmacy, $visit->id, $image]) }}" target="_blank" rel="noopener"><img loading="lazy" src="{{ route('examinations.consultation.image', [$currentPharmacy, $visit->id, $image]) }}" alt="Bukti konsultasi"></a><figcaption>{{ $evidenceData['captions'][$image] ?? '' }}</figcaption></figure>@endforeach</div>
        </div>
    @endif
    @if(isset($evidenceHistory) && $evidenceHistory->isNotEmpty())<details class="clinical-section"><summary>Riwayat bukti konsultasi ({{ $evidenceHistory->total() }} versi)</summary><ul class="consultation-history">@foreach($evidenceHistory as $item)<li><span>Versi {{ $item->version }} · {{ \Illuminate\Support\Carbon::parse($item->created_at)->timezone($currentPharmacy->timezone)->format('d/m/Y H:i') }} · {{ $item->creator_name }}</span><a class="btn small" href="{{ route('examinations.consultation.version', [$currentPharmacy, $visit->id, $item->id]) }}">Lihat versi</a></li>@endforeach</ul>{{ $evidenceHistory->links('partials.pagination') }}</details>@endif
</section>
