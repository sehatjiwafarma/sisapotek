<label>{{ $label }}{{ ($required ?? false) ? ' *' : '' }}
@if(($type ?? 'text') === 'textarea')
<textarea name="{{ $name }}" @required($required ?? false) maxlength="{{ $max ?? 2000 }}">{{ old($name, $value ?? '') }}</textarea>
@else
<input type="{{ $type ?? 'text' }}" name="{{ $name }}" value="{{ old($name, $value ?? '') }}" @required($required ?? false) @if(isset($max)) maxlength="{{ $max }}" @endif @if(($type ?? '') === 'number') step="any" @endif>
@endif
</label>
