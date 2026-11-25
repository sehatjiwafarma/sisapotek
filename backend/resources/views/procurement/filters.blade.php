<form class="toolbar" method="get" data-live-filter>
    @foreach(['sort','direction'] as $param) @if(is_string(request($param)))<input type="hidden" name="{{ $param }}" value="{{ request($param) }}">@endif @endforeach
    <label class="search-label">Pencarian<input type="search" name="q" maxlength="150" value="{{ request('q') }}" placeholder="{{ $placeholder }}"></label>
    <label>Status<select name="status"><option value="">Semua status</option>@foreach($statuses as $value=>$label)<option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>@endforeach</select></label>
    <button class="btn">Tampilkan</button><a class="btn" href="{{ $reset }}">Reset</a>
</form>
