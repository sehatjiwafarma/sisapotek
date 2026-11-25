@include('procurement.assets')
@push('styles')<link rel="stylesheet" href="{{ asset('css/operations.css') }}?v={{ filemtime(public_path('css/operations.css')) }}">@endpush
@push('master-scripts')<script src="{{ asset('js/operations.js') }}?v={{ filemtime(public_path('js/operations.js')) }}" defer></script>@endpush
