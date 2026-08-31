@props(['feedback' => null, 'error' => null])

@if ($feedback)
    @php $severity = $feedback['severity'] ?? 'none'; @endphp
    @if ($severity === 'major')
        <div class="mt-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 dark:border-red-900 dark:bg-red-950">
            <p class="text-sm text-red-700 dark:text-red-400">{{ $feedback['hint'] }}</p>
        </div>
    @elseif ($severity === 'minor')
        <div class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 dark:border-amber-900 dark:bg-amber-950">
            <p class="text-sm text-amber-700 dark:text-amber-400">{{ $feedback['hint'] }}</p>
        </div>
    @elseif ($severity === 'none')
        <div class="mt-2 rounded-lg border border-green-200 bg-green-50 px-3 py-2 dark:border-green-900 dark:bg-green-950">
            <p class="text-sm text-green-700 dark:text-green-400">Looks good</p>
        </div>
    @endif
@endif
@if ($error)
    <p class="mt-1 text-xs text-red-600">{{ $error }}</p>
@endif
