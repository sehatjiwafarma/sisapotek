@if($errors->any())
<div class="notice error" role="alert" data-error-summary tabindex="-1"><strong>Periksa kembali isian Anda.</strong><ul>
@foreach($errors->messages() as $field=>$messages)
    <li><a href="#" data-error-field="{{ $field }}">{{ implode(' ', $messages) }}</a></li>
@endforeach
</ul></div>
@endif
