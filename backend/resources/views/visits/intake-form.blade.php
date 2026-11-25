<section class="card visit-form-card" aria-labelledby="visit-intake-title">
<header class="visit-section-heading"><span class="visit-step" aria-hidden="true">3</span><div><h2 id="visit-intake-title">Pemeriksaan awal</h2><p>Opsional. Data otomatis digunakan pada SOAP kunjungan ini. Isi sistolik dan diastolik bersama jika tensi diukur.</p></div></header>
<input type="hidden" name="intake_present" value="1">
<div class="clinical-fields">
@foreach(\App\Services\ExaminationRecords::VITALS as $key => [$label,$unit,$min,$max])
<label>{{ $label }} ({{ $unit }})<input type="number" name="{{ $key }}" min="{{ $min }}" max="{{ $max }}" step="{{ in_array($key,['systolic','diastolic','pulse']) ? '1' : '0.1' }}" value="{{ old($key, $visit->intake[$key] ?? '') }}">@error($key)<small class="visit-field-error">{{ $message }}</small>@enderror</label>
@endforeach
<label class="wide">Hasil tes urine<textarea name="urine_test" maxlength="2000" rows="3" placeholder="Catat hasil jika tes dilakukan; kosongkan jika belum dilakukan.">{{ old('urine_test', $visit->intake['urine_test'] ?? '') }}</textarea>@error('urine_test')<small class="visit-field-error">{{ $message }}</small>@enderror</label>
</div></section>
