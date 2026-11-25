@php($fromRegistration = ($visit->intake_encrypted ?? null) !== null)
<section class="card soap-compact-vitals soap-span-full" aria-labelledby="soap-vitals-title">
<header><h2 id="soap-vitals-title">Pemeriksaan awal</h2><p>{{ $fromRegistration ? 'Data dari pendaftaran kunjungan. Tidak perlu diinput ulang di SOAP.' : 'Kunjungan lama: data pendaftaran belum tersedia, lengkapi jika diperlukan.' }}</p></header>
<div class="soap-vital-inputs">
@foreach(\App\Services\ExaminationRecords::VITALS as $key=>[$label,$unit,$min,$max])
<label for="soap-{{ $key }}"><span>{{ $label }} <small>{{ $unit }}</small></span><input id="soap-{{ $key }}" type="number" name="{{ $key }}" min="{{ $min }}" max="{{ $max }}" step="{{ in_array($key,['systolic','diastolic','pulse']) ? '1' : '0.1' }}" value="{{ $fromRegistration ? ($payload[$key] ?? '') : old($key,$payload[$key] ?? '') }}" @readonly($fromRegistration)>@error($key)<small class="soap-error">{{ $message }}</small>@enderror</label>
@endforeach
<label for="soap-urine" class="soap-urine"><span>Hasil tes urine</span><textarea id="soap-urine" name="urine_test" rows="3" maxlength="2000" placeholder="Belum dicatat saat pendaftaran" @readonly($fromRegistration)>{{ $fromRegistration ? ($payload['urine_test'] ?? '') : old('urine_test',$payload['urine_test'] ?? '') }}</textarea>@error('urine_test')<small class="soap-error">{{ $message }}</small>@enderror</label>
</div></section>
