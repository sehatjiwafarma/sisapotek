@extends('layouts.app')
@section('title','Master apotek')
@include('master.assets')
@section('content')
@php($field=fn($label,$name,$value='',$required=false,$type='text',$max=null)=>view('master.field',compact('label','name','value','required','type','max'))->render())
<div class="heading"><div><span class="eyebrow">PENGATURAN / MASTER APOTEK</span><h1>{{ $pharmacy->name }}</h1><p>{{ $pharmacy->code }} · Identitas dan konfigurasi apotek</p></div><div class="actions">@include('partials.status',['status'=>$pharmacy->status])@if($can('pharmacy.update'))<a class="btn primary" href="{{ route('master.edit',$pharmacy) }}">Edit profil</a>@endif</div></div>
@if($pharmacy->status==='DRAFT')<div class="notice warning">Apotek masih DRAFT. Lengkapi persyaratan sebelum aktivasi oleh Owner atau Admin Sistem untuk memulai transaksi operasional.</div>@elseif($pharmacy->status==='INACTIVE')<div class="notice warning">Apotek tidak aktif. Transaksi baru diblokir; histori tetap tersedia sesuai izin. @if($full)Alasan: {{ $pharmacy->deactivation_reason }}@endif</div>@endif
@if($full && $missing)<details class="card master-completeness"><summary>Kelengkapan registrasi · {{ count($missing) }} hal perlu dilengkapi</summary><ul>@foreach($missing as $item)<li>{{ $item }}</li>@endforeach</ul></details>@endif

@if($access->isOwner() && !auth()->user()->is_system_admin)
<section class="card padded"><h2>Status apotek</h2>
@if($pharmacy->status !== 'ACTIVE')
<p>Lengkapi seluruh persyaratan registrasi untuk mengaktifkan apotek.</p>
<form method="post" action="{{ route('master.activate', $pharmacy) }}" data-confirm="Aktifkan apotek ini untuk memulai transaksi operasional?" data-confirm-title="Aktifkan apotek" data-confirm-label="Aktifkan apotek">@csrf<button class="btn primary">Aktifkan apotek</button></form>
@else
<form method="post" action="{{ route('master.deactivate', $pharmacy) }}" data-confirm="Nonaktifkan apotek ini? Transaksi baru akan diblokir dan histori tetap tersedia." data-confirm-title="Nonaktifkan apotek" data-confirm-label="Nonaktifkan apotek" data-confirm-variant="danger">@csrf<label>Alasan penonaktifan *<textarea name="reason" required minlength="5" maxlength="2000">{{ old('reason') }}</textarea></label><button class="btn danger">Nonaktifkan apotek</button></form>
@endif
</section>
@endif
<div class="master-tabs" role="tablist" aria-label="Bagian master apotek">
@foreach(['general'=>'Informasi umum','contact'=>'Alamat & kontak','legal'=>'Legalitas','personnel'=>'Penanggung jawab','hours'=>'Jam operasional','settings'=>'Konfigurasi','documents'=>'Dokumen'] as $tab=>$label)
@if(!in_array($tab,['legal','personnel','settings']) || $full)<button type="button" data-master-tab="{{ $tab }}">{{ $label }}</button>@endif
@endforeach
@if($can('pharmacy.audit'))<a href="{{ route('master.audit',$pharmacy) }}">Riwayat perubahan ↗</a>@endif</div>
<section class="card padded" data-master-panel="general"><h2>Informasi umum</h2><div class="master-identity">@if($pharmacy->logo_path)<img class="master-logo" src="{{ route('master.logo',$pharmacy) }}" alt="Logo {{ $pharmacy->name }}">@endif<dl class="master-definition"><div><dt>Kode apotek</dt><dd>{{ $pharmacy->code }}</dd></div><div><dt>Nama apotek</dt><dd>{{ $pharmacy->name }}</dd></div><div><dt>Kepemilikan</dt><dd>{{ $pharmacy->ownership_type==='Individual'?'Perorangan':'Badan usaha' }}</dd></div>@if($full)<div><dt>Badan usaha</dt><dd>{{ $pharmacy->business_name ?: 'Belum diisi' }} {{ $pharmacy->legal_form }}</dd></div>@endif</dl></div>
@if($full)
<h2>Pemilik & saham</h2>
<div class="table-wrap"><table><thead><tr><th>Nama pemilik</th><th>Telepon</th><th>Email</th><th>Saham (%)</th></tr></thead><tbody>
@forelse($pharmacy->owners as $owner)
<tr><td>{{ $owner['name'] }}</td><td>{{ $owner['phone'] ?: 'Belum diisi' }}</td><td>{{ $owner['email'] ?? 'Belum diisi' }}</td><td>{{ isset($owner['share_percentage']) ? number_format((float) $owner['share_percentage'], 2, ',', '.') . '%' : 'Belum diisi' }}</td></tr>
@empty<tr><td colspan="4">Belum ada informasi pemilik.</td></tr>@endforelse
</tbody></table></div>
@endif
</section>
@include('master.contact')
@if($full)
<section class="card padded" data-master-panel="legal"><h2>Legalitas apotek</h2><p class="muted">Status Verified adalah pemeriksaan administratif internal, bukan verifikasi resmi pemerintah.</p>
<form method="post" action="{{ route('master.license',$pharmacy) }}">@csrf @method('PUT')<input type="hidden" name="tab" value="legal"><fieldset @disabled(!$can('pharmacy.update'))><div class="master-fields">
{!! $field('Nomor NIB','nib',$pharmacy->license?->nib,false,'text',30) !!}{!! $field('Nomor izin apotek','license_number',$pharmacy->license?->license_number,false,'text',100) !!}{!! $field('Tanggal terbit izin','issued_on',$pharmacy->license?->issued_on?->format('Y-m-d'),false,'date') !!}
<label>Izin memiliki masa berlaku<select name="has_expiry"><option value="0" @selected(!old('has_expiry',$pharmacy->license?->has_expiry))>Tidak</option><option value="1" @selected(old('has_expiry',$pharmacy->license?->has_expiry))>Ya</option></select></label>
{!! $field('Tanggal berakhir izin','expires_on',$pharmacy->license?->expires_on?->format('Y-m-d'),false,'date') !!}{!! $field('NPWP','npwp',$pharmacy->license?->npwp,false,'text',30) !!}{!! $field('Instansi penerbit','issuer',$pharmacy->license?->issuer,false,'text',150) !!}
<label>Status dokumen izin<select name="document_status">@foreach(['Recorded','Verified','Expired'] as $status)<option value="{{ $status }}" @selected(old('document_status',$pharmacy->license?->document_status)===$status)>{{ ['Recorded'=>'Tercatat','Verified'=>'Diperiksa internal','Expired'=>'Kedaluwarsa'][$status] }}</option>@endforeach</select></label>
</div>@if($can('pharmacy.update'))<button class="btn primary">Simpan legalitas</button>@endif</fieldset></form></section>
@include('master.personnel')
@endif
<section class="card padded" data-master-panel="hours"><h2>Jam operasional</h2><p class="muted">Mendukung beberapa sesi per hari. Tandai lintas malam untuk sesi yang berakhir pada hari berikutnya.</p>
<form method="post" action="{{ route('master.hours',$pharmacy) }}" id="hours-form">@csrf @method('PUT')<input type="hidden" name="tab" value="hours"><fieldset @disabled(!$can('pharmacy.update') && !$can('pharmacy.update_limited'))><div class="table-wrap"><table><thead><tr><th>HARI</th><th>STATUS</th><th>BUKA</th><th>TUTUP</th><th>LINTAS MALAM</th><th>KETERANGAN</th><th></th></tr></thead><tbody id="hours-rows">
@php($hourRows=old('hours',$pharmacy->hours->isEmpty()?collect(range(0,6))->map(fn($day)=>['day'=>$day,'is_open'=>false,'overnight'=>false])->all():$pharmacy->hours->toArray()))
@foreach($hourRows as $index=>$hour)<tr data-hours-row><td><select name="hours[{{ $index }}][day]" aria-label="Hari sesi">@foreach(['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'] as $day=>$label)<option value="{{ $day }}" @selected((int)$hour['day']===$day)>{{ $label }}</option>@endforeach</select></td><td><select name="hours[{{ $index }}][is_open]" aria-label="Status buka"><option value="0" @selected(!$hour['is_open'])>Tutup</option><option value="1" @selected($hour['is_open'])>Buka</option></select></td><td><input type="time" name="hours[{{ $index }}][opens_at]" value="{{ substr($hour['opens_at']??'',0,5) }}" aria-label="Jam buka"></td><td><input type="time" name="hours[{{ $index }}][closes_at]" value="{{ substr($hour['closes_at']??'',0,5) }}" aria-label="Jam tutup"></td><td><select name="hours[{{ $index }}][overnight]" aria-label="Lintas tengah malam"><option value="0" @selected(!$hour['overnight'])>Tidak</option><option value="1" @selected($hour['overnight'])>Ya</option></select></td><td><input name="hours[{{ $index }}][note]" value="{{ $hour['note']??'' }}" maxlength="200" aria-label="Keterangan sesi"></td><td><button class="btn small danger" type="button" data-hours-remove>Hapus sesi</button></td></tr>@endforeach
</tbody></table></div>@if($can('pharmacy.update') || $can('pharmacy.update_limited'))<div class="actions"><button class="btn" type="button" id="hours-add">+ Tambah sesi</button><button class="btn primary">Simpan jam operasional</button></div>@endif</fieldset></form></section>
@if($full)
<section class="card padded" data-master-panel="settings"><h2>Konfigurasi dasar</h2><form method="post" action="{{ route('master.settings.save',$pharmacy) }}">@csrf @method('PATCH')<input type="hidden" name="tab" value="settings"><fieldset @disabled(!$can('pharmacy.update'))><div class="master-fields">
@foreach(['timezone'=>['Zona waktu',['Asia/Jakarta','Asia/Makassar','Asia/Jayapura']],'currency'=>['Mata uang',['IDR']],'stock_issue_method'=>['Metode pengeluaran stok',['FEFO','FIFO']],'valuation_method'=>['Metode penilaian persediaan',['MOVING_AVERAGE','FIFO']]] as $key=>[$label,$options])<label>{{ $label }}<select name="{{ $key }}">@foreach($options as $option)<option @selected(old($key,$pharmacy->settings?->$key)===$option)>{{ $option }}</option>@endforeach</select></label>@endforeach
@foreach(['allow_negative_stock'=>'Izinkan stok negatif','track_batch'=>'Tracking batch','track_expiry'=>'Tracking expired date','minimum_stock_notification'=>'Notifikasi minimum stok'] as $key=>$label)<label>{{ $label }}<select name="{{ $key }}"><option value="0" @selected(!old($key,$pharmacy->settings?->$key))>Tidak</option><option value="1" @selected(old($key,$pharmacy->settings?->$key))>Ya</option></select></label>@endforeach
{!! $field('Batas penyelesaian SOAP (jam, 1–720)','soap_reminder_hours',$pharmacy->settings?->soap_reminder_hours??24,true,'number') !!}{!! $field('Pengingat kedaluwarsa (hari)','expiry_reminder_days',$pharmacy->settings?->expiry_reminder_days??90,true,'number') !!}{!! $field('Header struk','receipt_header',$pharmacy->settings?->receipt_header,false,'textarea') !!}{!! $field('Footer struk','receipt_footer',$pharmacy->settings?->receipt_footer,false,'textarea') !!}
</div>@if($can('pharmacy.update'))<button class="btn primary">Simpan konfigurasi</button>@endif</fieldset></form></section>
@endif
<section class="card padded" data-master-panel="documents"><h2>Dokumen apotek</h2><p class="muted">Dokumen tersimpan privat. PDF/JPG/PNG maksimal {{ config('pharmacy.document_max_kb')/1024 }} MB per file.</p>
@if($can('pharmacy.documents_upload'))<form class="master-subform" method="post" enctype="multipart/form-data" action="{{ route('master.documents.upload',$pharmacy) }}">@csrf<input type="hidden" name="tab" value="documents"><div class="master-fields">{!! $field('Nama dokumen','name','',true,'text',150) !!}<label>Jenis *<select name="type">@foreach(['license'=>'Izin apotek','nib'=>'NIB','npwp'=>'NPWP','sip'=>'SIP','other'=>'Lainnya'] as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>{!! $field('Berlaku sampai (opsional)','expires_on','',false,'date') !!}<label>File *<input type="file" name="file" required accept="application/pdf,image/jpeg,image/png"></label></div><button class="btn primary">Unggah dokumen</button></form>@endif
@if($can('pharmacy.documents_view'))<div class="table-wrap"><table><thead><tr><th>DOKUMEN</th><th>JENIS</th><th>MASA BERLAKU</th><th>AKSI</th></tr></thead><tbody>@forelse($documents as $document)<tr><td>{{ $document->name }}</td><td>{{ $document->type }}</td><td>{{ $document->expires_on?->format('d/m/Y') ?? 'Tidak dicatat' }}</td><td><div class="actions"><a class="btn small" href="{{ route('master.documents.download',[$pharmacy,$document->id]) }}">Unduh</a>@if($can('pharmacy.documents_delete'))<form method="post" action="{{ route('master.documents.delete',[$pharmacy,$document->id]) }}" data-confirm="Hapus dokumen ini?">@csrf @method('DELETE')<input type="hidden" name="tab" value="documents"><button class="btn small danger">Hapus</button></form>@endif</div></td></tr>@empty<tr><td colspan="4" class="empty">Belum ada dokumen.</td></tr>@endforelse</tbody></table></div>@else<div class="notice">Anda tidak memiliki izin melihat dokumen apotek.</div>@endif</section>
@endsection
