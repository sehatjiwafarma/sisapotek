@push('styles')<link rel="stylesheet" href="{{ asset('css/master.css') }}?v={{ filemtime(public_path('css/master.css')) }}">@endpush
@push('master-scripts')<script src="{{ asset('js/master.js') }}?v={{ filemtime(public_path('js/master.js')) }}" defer></script>@endpush
