@php
    $statusClass = match($color ?? 'gray') {
        'success' => 'dw-status-success',
        'warning' => 'dw-status-warning',
        default => 'dw-status-neutral',
    };
@endphp

<div class="dw-status-ctn">
    <span class="dw-status {{ $statusClass }}" role="status" aria-live="polite">
        {{ $label }}
    </span>
</div>
