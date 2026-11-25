@php
    $brandPharmacy = ($pharmacyBrand ?? false) ? request()->route('pharmacy') : null;
    $brandName = $brandPharmacy?->name;
    $brandLogo = $brandPharmacy?->logo_path && \Illuminate\Support\Facades\Storage::disk('local')->exists($brandPharmacy->logo_path);
    $brandInitials = $brandName ? collect(preg_split('/\s+/u', trim($brandName)))->take(2)->map(fn ($word) => mb_substr($word, 0, 1))->join('') : '';
@endphp
@if($brandPharmacy)
<a class="brand sejiwa-brand pharmacy-brand" href="{{ route('pharmacies.index') }}" aria-label="{{ $brandName }} — Pilih apotek" title="{{ $brandName }}">
    @if($brandLogo)
    <img class="brand-icon sejiwa-mark pharmacy-brand-logo" src="{{ route('master.brand-logo', $brandPharmacy) }}" width="40" height="40" alt="">
    @else
    <span class="brand-icon sejiwa-mark pharmacy-brand-initials" aria-hidden="true">{{ mb_strtoupper($brandInitials) }}</span>
    @endif
    <span class="sidebar-brand-label sejiwa-wordmark"><span>{{ $brandName }}</span><span class="brand-light">Sistem Manajemen Apotek</span></span>
</a>
@else
<a class="brand sejiwa-brand" href="{{ auth()->check() ? route('pharmacies.index') : url('/') }}" aria-label="Sehat Jiwa — Beranda">
    <img class="brand-icon sejiwa-mark" src="{{ asset('brand/sejiwa-mark.svg') }}" width="40" height="40" alt="">
    <span class="sidebar-brand-label sejiwa-wordmark"><span>Sehat Jiwa</span><span class="brand-light">Sistem Manajemen Apotek</span></span>
</a>
@endif
