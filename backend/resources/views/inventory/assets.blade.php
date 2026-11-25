@include('products.assets')
@push('styles')<link rel="stylesheet" href="{{ asset('css/inventory.css') }}?v={{ filemtime(public_path('css/inventory.css')) }}">@endpush
