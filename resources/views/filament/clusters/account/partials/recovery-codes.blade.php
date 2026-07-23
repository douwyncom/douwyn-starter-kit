@if(! $enabled)
    <div class="text-sm text-gray-500 dark:text-gray-400">
        {{__('Enable 2FA to generate recovery codes.')}}
    </div>
@elseif(! empty($codes))
    <div class="mb-3 text-xs text-amber-700 dark:text-amber-300">
        {{ __('These recovery codes are shown once. Store them safely before leaving this page.') }}
    </div>

    <div class="space-y-2">
        @foreach($codes as $code)
            <div class="rounded-lg bg-gray-50 px-3 py-2 font-mono text-sm text-gray-900 dark:bg-gray-900 dark:text-gray-100">
                {{ $code }}
            </div>
        @endforeach
    </div>
@elseif(($remaining ?? 0) === 0)
    <div class="text-sm text-gray-500 dark:text-gray-400">
        {{__('2FA is enabled (:method). No recovery codes available. Regenerate them.', [
            'method' => $method
        ])}}
    </div>
@else
    <div class="text-sm text-gray-500 dark:text-gray-400">
        {{ __(':count recovery codes remain. Stored codes cannot be displayed again; regenerate them if you lost your copy.', [
            'count' => $remaining,
        ])}}
    </div>
@endif
