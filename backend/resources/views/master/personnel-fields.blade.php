<div class="master-fields">
    @foreach([
        ['Nama lengkap apoteker', 'name', 'text', 150, true],
        ['Nomor telepon', 'phone', 'tel', 20, false],
        ['Nomor Surat Tanda Registrasi (STR)', 'str_number', 'text', 100, false],
        ['Nomor Surat Izin Praktik (SIP)', 'sip_number', 'text', 100, false],
        ['SIP berlaku sampai', 'sip_expires_on', 'date', null, false],
    ] as [$label, $name, $type, $max, $required])
        @php($value = $name === 'sip_expires_on' ? $person?->sip_expires_on?->format('Y-m-d') : $person?->{$name})
        <label>{{ $label }}{{ $required ? ' *' : '' }}<input type="{{ $type }}" name="{{ $name }}" value="{{ old('_personnel_form') === $formKey ? old($name, $value) : $value }}" @required($required) @if($max) maxlength="{{ $max }}" @endif></label>
    @endforeach
</div>
