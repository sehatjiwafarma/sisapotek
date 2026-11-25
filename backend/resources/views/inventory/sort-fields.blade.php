@foreach(['sort','direction'] as $sortParam)
@if(is_string(request($sortParam)))<input type="hidden" name="{{ $sortParam }}" value="{{ request($sortParam) }}">@endif
@endforeach
