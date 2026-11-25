@props(['label' => 'Informasi tambahan'])
@php($tooltipId = 'help-'.\Illuminate\Support\Str::uuid())
<span class="help-tooltip">
    <button type="button" class="help-tooltip-trigger" aria-label="{{ $label }}" aria-describedby="{{ $tooltipId }}" data-help-tooltip>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v6m0-10v1"/></svg>
    </button>
    <span class="help-tooltip-content" id="{{ $tooltipId }}" role="tooltip" popover="manual">{{ $slot }}</span>
</span>
