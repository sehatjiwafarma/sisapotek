@php($selectedServices = old('service_ids', array_column(\App\Services\VisitRegistration::services($visit), 'tariff_id')))
<section class="card visit-form-card visit-services-card" aria-labelledby="visit-services-title">
<header class="visit-section-heading"><div><h2 id="visit-services-title">Layanan & tarif * <x-help-tooltip label="Informasi Layanan & tarif *">Pilih satu atau beberapa layanan. Tarif tersimpan saat pendaftaran disimpan.</x-help-tooltip></h2></div>
@if($access->allows('billing.tariffs'))<a class="btn small" href="{{ route('operations.catalog', [$currentPharmacy,'tariffs']) }}" target="_blank" rel="noopener">Master layanan ↗</a>@endif
</header><input type="hidden" name="services_present" value="1">
<div class="visit-service-options" data-visit-services>
@forelse($tariffs as $tariff)
<label class="visit-service-option"><input type="checkbox" name="service_ids[]" value="{{ $tariff->id }}" data-price="{{ $tariff->price }}" @checked(is_array($selectedServices) && in_array($tariff->id,$selectedServices,true))><span>{{ $tariff->name }}</span><strong>Rp {{ number_format($tariff->price,2,',','.') }}</strong></label>
@empty<div class="visit-services-empty"><strong>Belum ada layanan aktif</strong><p>Tambahkan layanan beserta tarif melalui Master layanan sebelum mendaftarkan kunjungan.</p></div>@endforelse
@if($tariffs->isNotEmpty())
<p class="visit-service-total" role="status">Total tarif layanan: <strong data-service-total>Rp {{ number_format($tariffs->whereIn('id',is_array($selectedServices)?$selectedServices:[])->sum('price'),2,',','.') }}</strong></p>
@endif
@error('service_ids')<p class="visit-field-error">{{ $message }}</p>@enderror
@error('services')<p class="visit-field-error">{{ $message }}</p>@enderror
</div></section>
@push('master-scripts')<script src="{{ asset('js/visit-services.js') }}?v={{ filemtime(public_path('js/visit-services.js')) }}" defer></script>@endpush
