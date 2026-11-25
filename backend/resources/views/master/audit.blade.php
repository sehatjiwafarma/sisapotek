@extends('layouts.app')
@section('title','Riwayat master apotek')
@section('content')
<div class="heading"><div><h1>Riwayat perubahan master</h1><p>{{ $pharmacy->name }}</p></div><a class="btn" href="{{ route('master.show',$pharmacy) }}">Kembali</a></div>
<section class="card"><div class="table-wrap"><table data-table-sort="server" data-sort-columns="time,actor,action,details" data-sort-default="time" data-sort-default-direction="desc"><thead><tr><th>WAKTU WIB</th><th>ID PENGGUNA</th><th>AKSI</th><th>PERUBAHAN</th></tr></thead><tbody>@forelse($entries as $entry)<tr><td>{{ \Illuminate\Support\Carbon::parse($entry->created_at)->timezone('Asia/Jakarta')->format('d/m/Y H:i:s') }}</td><td>{{ $entry->actor_id }}</td><td>{{ $entry->action }}</td><td><details><summary>Lihat sebelum dan sesudah</summary><pre>{{ json_encode(json_decode($entry->details),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre></details></td></tr>@empty<tr><td colspan="4" class="empty">Belum ada perubahan.</td></tr>@endforelse</tbody></table></div><div class="pagination">{{ $entries->links('partials.pagination') }}</div></section>
@endsection
