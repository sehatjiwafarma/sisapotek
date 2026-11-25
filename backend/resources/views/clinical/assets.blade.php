@include('products.assets')
@push('styles')<link rel="stylesheet" href="{{ asset('css/clinical-master.css') }}?v={{ filemtime(public_path('css/clinical-master.css')) }}">@endpush
