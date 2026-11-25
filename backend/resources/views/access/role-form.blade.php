@extends('layouts.app')
@section('title', $role->exists ? 'Ubah role' : 'Tambah role')
@push('styles')
<link rel="stylesheet" href="{{ asset('css/role-permissions.css') }}?v={{ filemtime(public_path('css/role-permissions.css')) }}">
@endpush
@section('content')
<div class="heading"><div><h1>{{ $role->exists ? 'Ubah role' : 'Tambah role' }}</h1><p>Pilih fungsi yang dapat dijalankan oleh pemegang role ini.</p></div><a class="btn" href="{{ route('roles.index', $currentPharmacy) }}">Kembali</a></div><form method="post" action="{{ $role->exists ? route('roles.update', [$currentPharmacy, $role]) : route('roles.store', $currentPharmacy) }}">@csrf @if($role->exists) @method('PUT') @endif
<section class="card padded"><div class="two-col"><label>Nama role<input name="name" value="{{ old('name', $role->name) }}" required maxlength="80" placeholder="Contoh: Kasir shift pagi"></label><label>Deskripsi<input name="description" value="{{ old('description', $role->description) }}" maxlength="300" placeholder="Tanggung jawab pemegang role"></label></div></section>
<section class="permission-editor" aria-labelledby="permissions-title">
<div class="permission-editor-heading"><div><h2 id="permissions-title">Hak akses per modul <x-help-tooltip label="Informasi Hak akses per modul">Centang aksi yang diizinkan. Gunakan pilihan di setiap kartu untuk mengatur satu modul sekaligus.</x-help-tooltip></h2></div><span class="badge neutral" id="permission-count" aria-live="polite">{{ count(old('permissions', $role->permissions ?? [])) }} izin dipilih</span></div>
<div class="permission-controls">
<label class="permission-search">Cari modul atau aksi<input type="search" id="permission-search" placeholder="Contoh: pengguna, kasir, setujui" autocomplete="off"></label>
<label class="check permission-code-toggle"><input type="checkbox" id="permission-codes"> Tampilkan kode izin</label>
<div class="permission-bulk"><button class="btn small" type="button" data-permissions="all">Pilih semua</button><button class="btn small" type="button" data-permissions="none">Kosongkan pilihan</button></div>
</div>
<p class="permission-help" id="permission-help">Pilihan berlaku untuk semua modul. Izin yang tidak dapat Anda berikan ditandai terkunci.</p>
@php($selected = old('permissions', $role->permissions ?? []))
<div class="permission-grid" id="permission-grid">
@foreach($groups as $module => [$label,$actions])
<section class="permission-card" data-module="{{ $module }}" data-search="{{ mb_strtolower($label.' '.$module.' '.implode(' ', $actions).' '.implode(' ', array_keys($actions))) }}" aria-labelledby="module-{{ $module }}">
<div class="permission-card-heading"><div><h3 id="module-{{ $module }}">{{ $label }}</h3><span class="permission-module-count">{{ count(array_intersect($selected, array_map(fn($action) => $module.'.'.$action, array_keys($actions)))) }} dari {{ count($actions) }} izin</span></div>
<label class="check permission-select-module"><input type="checkbox" data-module-toggle aria-label="Pilih semua izin {{ $label }}"><span>Pilih semua</span></label></div>
<div class="permission-options">
@foreach($actions as $action => $title)
@php($key = $module.'.'.$action)
<label class="permission-option"><input type="checkbox" name="permissions[]" value="{{ $key }}" @checked(in_array($key, $selected)) @disabled(!$access->allows($key))><span class="permission-option-text"><span>{{ $title }}</span><small class="permission-code">{{ $key }}</small></span>@if(!$access->allows($key))<span class="permission-locked">Terkunci</span>@endif</label>
@endforeach
</div></section>
@endforeach
</div><div class="card empty" id="permission-empty" role="status" hidden><h3>Modul atau aksi tidak ditemukan</h3><p>Gunakan kata kunci lain. Pilihan yang sudah dicentang tetap tersimpan.</p></div>
<p class="permission-footnote">Izin modul operasional disiapkan untuk fitur yang akan dikembangkan berikutnya.</p>
</section><div class="sticky-actions"><span>Perubahan berlaku untuk semua pengguna role ini.</span><button class="btn primary">Simpan role & izin</button></div></form>
@endsection
