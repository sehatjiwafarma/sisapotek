<label>{{ $label }}{{ ($required ?? false) ? ' *' : '' }}@if(!($required ?? false)) <span class="product-optional">Opsional</span>@endif
    <input type="{{ $type ?? 'text' }}" name="{{ $name }}" value="{{ old($name, $product->{$name}) }}" @required($required ?? false) @if(isset($max)) maxlength="{{ $max }}" @endif @if(isset($min)) min="{{ $min }}" @endif @if(isset($step)) step="{{ $step }}" @endif @if(isset($placeholder)) placeholder="{{ $placeholder }}" @endif @error($name) aria-invalid="true" aria-describedby="product-error-{{ $name }}" @enderror>
    @if(isset($hint))<small>{{ $hint }}</small>@endif
    @error($name)<small class="product-field-error" id="product-error-{{ $name }}">{{ $message }}</small>@enderror
</label>
