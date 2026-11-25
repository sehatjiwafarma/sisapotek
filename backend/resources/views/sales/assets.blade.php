@include('products.assets')
@push('styles')<link rel="stylesheet" href="{{ asset('css/sales.css') }}?v={{ filemtime(public_path('css/sales.css')) }}">@endpush
