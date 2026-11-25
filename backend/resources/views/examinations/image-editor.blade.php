<section class="card soap-image-editor soap-documentation soap-span-full" aria-labelledby="soap-documentation-title">
    <header class="soap-documentation-heading"><div class="help-heading"><h2 id="soap-documentation-title">Dokumentasi pemeriksaan</h2><x-help-tooltip label="Informasi Dokumentasi pemeriksaan">Pilih cara pencatatan hasil pemeriksaan.</x-help-tooltip></div>
    </header>
    <div class="soap-documentation-mode">
        <label for="soap-documentation-mode">Metode pencatatan</label>
        <select id="soap-documentation-mode" name="documentation_mode" aria-describedby="soap-mode-help">
            <option value="FORM" @selected(old('documentation_mode',$payload['documentation_mode'] ?? 'FORM') === 'FORM')>Formulir SOAP</option>
            <option value="IMAGE" @selected(old('documentation_mode',$payload['documentation_mode'] ?? 'FORM') === 'IMAGE')>Gambar SOAP / pemeriksaan online</option>
        </select>
        <p id="soap-mode-help">Isi formulir S–O–A–P, atau pilih mode gambar untuk mengunggah SOAP lengkap.</p>
    </div>
    <div class="soap-documentation-fields">
        <div>
            <label for="soap-image-upload">Tambah gambar SOAP <span>Opsional pada mode formulir</span></label>
            <input id="soap-image-upload" type="file" name="images[]" multiple accept="image/png,image/jpeg" aria-describedby="soap-upload-help soap-image-feedback">
            <small id="soap-upload-help">PNG/JPG · maks. 5 MB/gambar<br>Maks. 6 gambar baru dan 10 gambar per versi.</small>
        </div>
        <div>
            <label for="soap-image-notes">Keterangan gambar <span>Opsional</span></label>
            <textarea id="soap-image-notes" name="image_notes" maxlength="5000" rows="2" placeholder="Contoh: pemeriksaan online atau urutan halaman">{{ old('image_notes',$payload['image_notes'] ?? '') }}</textarea>
            <small>Tambahkan konteks singkat untuk gambar yang diunggah.</small>
        </div>
    </div>
    <p id="soap-image-feedback" class="soap-error" role="alert"></p>
    <div id="soap-image-previews" class="soap-image-grid"></div>
    @if(!empty($payload['image_ids']))
        <div class="soap-retained-images">
            <h3>Gambar tersimpan</h3>
            <small>Centang gambar yang disertakan. Klik gambar untuk memperbesar.</small>
            <div class="soap-image-grid">
                @foreach($payload['image_ids'] as $imageId)
                    <div class="soap-image-choice">
                        <a href="{{ route('examinations.image',[$currentPharmacy,$visit->id,$imageId]) }}" target="_blank" rel="noopener"><img src="{{ route('examinations.image',[$currentPharmacy,$visit->id,$imageId]) }}" alt="Buka gambar SOAP {{ $loop->iteration }}" loading="lazy"></a>
                        <label><input type="checkbox" name="image_ids[]" value="{{ $imageId }}" @checked(in_array($imageId,old('image_ids',$payload['image_ids']),true))> Sertakan gambar {{ $loop->iteration }}</label>
                    </div>
                @endforeach
            </div>
            <small>Gambar yang tidak dicentang dikeluarkan dari draft/versi baru. Versi final sebelumnya tetap tersimpan.</small>
        </div>
    @endif
</section>