<label>{{ $label }}{{ ($required ?? false) ? ' *' : '' }}@if(!($required ?? false)) <span class="product-optional">Opsional</span>@endif
    <select name="{{ $name }}" @required($required ?? false) @error($name) aria-invalid="true" @enderror><option value="">Pilih {{ mb_strtolower($label) }}</option>@foreach($options as $option)<option @selected(old($name, $product->{$name}) === $option)>{{ $option }}</option>@endforeach</select>
    @if(isset($hint))<small>{{ $hint }}</small>@endif
    @error($name)<small class="product-field-error">{{ $message }}</small>@enderror
</label>
