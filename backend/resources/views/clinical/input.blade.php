@php($fieldId = 'input-'.str_replace(['[',']','.'],'-',$name))
<label class="{{ $wide ?? false ? 'wide' : '' }}">{{ $label }}{{ $required ?? false ? ' *' : '' }}
@if(($type ?? 'text') === 'textarea')<textarea id="{{ $fieldId }}" name="{{ $name }}" @error($name) aria-invalid="true" aria-describedby="{{ $fieldId }}-error" @enderror maxlength="{{ $max ?? 2000 }}" rows="3" @required($required ?? false)>{{ old($name,$value ?? '') }}</textarea>
@else<input id="{{ $fieldId }}" @error($name) aria-invalid="true" aria-describedby="{{ $fieldId }}-error" @enderror type="{{ $type ?? 'text' }}" name="{{ $name }}" value="{{ old($name,$value ?? '') }}" @if(isset($max)) maxlength="{{ $max }}" @endif @if(isset($dateMax)) max="{{ $dateMax }}" @endif @required($required ?? false) autocomplete="off">@endif
@if(isset($hint))<small>{{ $hint }}</small>@endif
@error($name)<small class="field-error" id="{{ $fieldId }}-error">{{ $message }}</small>@enderror
</label>
