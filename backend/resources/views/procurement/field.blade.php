@php($fieldId = 'input-'.str_replace(['[',']','.'],'-',$name))
<label>{{ $label }}{{ ($required ?? false) ? ' *' : '' }}
    @if(($type ?? 'text') === 'textarea')<textarea id="{{ $fieldId }}" name="{{ $name }}" @error($name) aria-invalid="true" aria-describedby="{{ $fieldId }}-error" @enderror maxlength="{{ $max ?? 2000 }}" rows="3">{{ old($name, $value ?? '') }}</textarea>
    @else<input id="{{ $fieldId }}" @error($name) aria-invalid="true" aria-describedby="{{ $fieldId }}-error" @enderror type="{{ $type ?? 'text' }}" name="{{ $name }}" value="{{ old($name, $value ?? '') }}" @required($required ?? false) @if(isset($max)) maxlength="{{ $max }}" @endif @if(($type ?? '') === 'number') min="0" max="{{ $numberMax ?? 3650 }}" step="{{ $step ?? 1 }}" @endif>
    @endif
    @if(isset($hint))<small>{{ $hint }}</small>@endif
@error($name)<small class="field-error" id="{{ $fieldId }}-error">{{ $message }}</small>@enderror
</label>
