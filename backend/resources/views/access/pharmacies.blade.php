@extends('layouts.app')
@section('title', 'Daftar apotek')
@section('content')
<div class="heading"><div><h1>Apotek Anda</h1><p>{{ $memberships->isNotEmpty() ? 'Apotek Anda sudah terdaftar. Pilih workspace di bawah untuk melanjutkan.' : 'Daftarkan apotek pertama Anda atau bergabung melalui undangan.' }}</p></div>@if($memberships->isNotEmpty())<span class="badge">{{ $memberships->count() }} apotek tersedia</span>@endif</div>
<section class="pharmacy-directory" aria-label="Apotek yang dapat diakses">
@forelse($memberships as $member)
    <article class="card pharmacy-directory-entry">
    <a class="pharmacy-card" href="{{ route('pharmacy.home', $member->pharmacy) }}">
        <span class="tile pharmacy-card-logo" aria-hidden="true">
            @if($member->pharmacy->logo_path && \Illuminate\Support\Facades\Storage::disk('local')->exists($member->pharmacy->logo_path))
                <img src="{{ route('master.brand-logo', $member->pharmacy) }}" width="48" height="48" alt="">
            @else
                ✚
            @endif
        </span>
        <div><h2>{{ $member->pharmacy->name }}</h2><p>{{ $member->roles->pluck('name')->join(', ') }}</p><small>{{ $member->pharmacy->address ?: 'Alamat belum diisi' }}</small><span class="pharmacy-open">Buka workspace →</span></div>
    </a>
    @if($member->isOwner())
    <form class="pharmacy-delete" method="post" action="{{ route('pharmacies.destroy', $member->pharmacy) }}" data-confirm="Hapus {{ $member->pharmacy->name }} dari daftar? Seluruh anggota kehilangan akses workspace ini. Riwayat transaksi dan rekam medis tetap tersimpan sebagai arsip." data-confirm-title="Hapus apotek" data-confirm-label="Hapus apotek" data-confirm-variant="danger">
        @csrf @method('DELETE')
        <button class="btn small danger" type="submit">Hapus apotek</button>
    </form>
    @endif
    </article>
@empty
    <div class="card empty"><h2>Belum ada apotek</h2><p>Buat apotek baru menggunakan formulir di bawah, atau buka tautan undangan dari pengelola apotek Anda.</p></div>
@endforelse
</section>
<a class="btn primary" href="{{ route('master.create') }}">{{ $memberships->isEmpty() ? 'Daftarkan apotek pertama' : '+ Tambah apotek lain' }}</a>
@endsection
