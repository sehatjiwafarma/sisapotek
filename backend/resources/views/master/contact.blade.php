<section class="card contact-panel" data-master-panel="contact">
    <header class="contact-header"><div class="help-heading"><h2>Alamat & kontak</h2><x-help-tooltip label="Informasi Alamat & kontak">Lokasi apotek dan informasi yang dapat dihubungi.</x-help-tooltip></div></header>
    <div class="contact-layout">
        <section class="contact-location" aria-labelledby="contact-location-title">
            <div class="contact-section-heading">
                <h3 id="contact-location-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M20 10c0 6-8 11-8 11S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></svg>Lokasi apotek</h3>
                @if($can('pharmacy.update'))<a class="contact-edit-link" href="{{ route('master.edit', $pharmacy) }}#address">Edit alamat</a>@endif
            </div>
            <p class="contact-street">{{ $pharmacy->address ?: 'Alamat belum diisi' }}</p>
            <dl class="contact-location-details">
                @foreach(['province'=>'Provinsi','city'=>'Kabupaten/kota','district'=>'Kecamatan','village'=>'Kelurahan/desa'] as $level=>$label)
                    <div><dt>{{ $label }}</dt><dd>{{ \App\Models\Region::find($pharmacy->{$level.'_code'})?->name ?? 'Belum diisi' }}</dd></div>
                @endforeach
                <div><dt>Kode pos</dt><dd>{{ $pharmacy->postal_code ?: 'Belum diisi' }}</dd></div>
                <div><dt>Koordinat</dt><dd>{{ $pharmacy->latitude !== null && $pharmacy->longitude !== null ? $pharmacy->latitude.', '.$pharmacy->longitude : 'Belum diisi' }}</dd></div>
            </dl>
        </section>
        <section class="contact-details" aria-labelledby="contact-details-title">
            <div class="contact-section-heading"><h3 id="contact-details-title">Informasi kontak</h3></div>
            @if($can('pharmacy.update') || $can('pharmacy.update_limited'))
                <form method="post" action="{{ route('master.contact', $pharmacy) }}">
                    @csrf @method('PATCH')<input type="hidden" name="tab" value="contact">
                    <div class="master-fields">
                        {!! $field('Telepon','phone',$pharmacy->phone,true,'tel',20) !!}
                        {!! $field('Email','email',$pharmacy->email,false,'email',254) !!}
                        {!! $field('Website','website',$pharmacy->website,false,'url',500) !!}
                        {!! $field('Tautan Google Maps','maps_url',$pharmacy->maps_url,false,'url',500) !!}
                    </div>
                    <div class="contact-form-footer"><span>Lengkapi telepon agar apotek mudah dihubungi.</span><button class="btn primary">Simpan kontak</button></div>
                </form>
            @else
                <dl class="contact-location-details">
                    @foreach(['Telepon'=>$pharmacy->phone,'Email'=>$pharmacy->email,'Website'=>$pharmacy->website,'Tautan Google Maps'=>$pharmacy->maps_url] as $label=>$value)
                        <div><dt>{{ $label }}</dt><dd>{{ $value ?: 'Belum diisi' }}</dd></div>
                    @endforeach
                </dl>
            @endif
        </section>
    </div>
</section>
