@extends('layouts.app')
@section('title', 'Profil apotek')
@section('content')
<div class="heading"><div><h1>Profil apotek <x-help-tooltip label="Informasi Profil apotek">Identitas untuk workspace yang sedang aktif.</x-help-tooltip></h1></div></div><section class="card padded narrow"><form method="post" action="{{ route('pharmacy.update', $currentPharmacy) }}">@csrf @method('PUT')<label>Nama apotek<input name="name" value="{{ old('name', $pharmacy->name) }}" required maxlength="150" @disabled(!$access->allows('pharmacy.update'))></label><label>Alamat<textarea name="address" maxlength="500" @disabled(!$access->allows('pharmacy.update'))>{{ old('address', $pharmacy->address) }}</textarea></label>@if($access->allows('pharmacy.update'))<button class="btn primary">Simpan perubahan</button>@endif</form></section>
@endsection
