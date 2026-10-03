@props(['feedback' => null, 'error' => null])

@if ($feedback)
    @php $severity = $feedback['severity'] ?? 'none'; @endphp
    @if ($severity === 'major')
        <div class="mt-2 rounded-xl border border-danger-line bg-danger-soft px-3 py-2">
            <p class="text-sm text-danger-ink">{{ $feedback['hint'] }}</p>
        </div>
    @elseif ($severity === 'minor')
        <div class="mt-2 rounded-xl border border-warning-line bg-warning-soft px-3 py-2">
            <p class="text-sm text-warning-ink">{{ $feedback['hint'] }}</p>
        </div>
    @elseif ($severity === 'none')
        <div class="mt-2 rounded-xl border border-success/30 bg-success-soft px-3 py-2 dark:border-success-dark/30 dark:bg-success-soft-dark">
            <p class="text-sm text-success dark:text-success-dark">Looks good</p>
        </div>
    @endif
@endif
@if ($error)
    <p class="mt-1 text-xs text-danger-ink">{{ $error }}</p>
@endif
