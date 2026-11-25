@include('clinical.assets')
@push('styles')<link rel="stylesheet" href="{{ asset('css/examinations.css') }}?v={{ filemtime(public_path('css/examinations.css')) }}">@endpush