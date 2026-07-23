@if($isPending)
    <div class="flex items-center justify-center py-2">
        @if($qrSvg)
            <div class="max-w-full overflow-hidden">{!! $qrSvg !!}</div>
        @endif
    </div>

    @if($secret)
        <div class="mt-3">
            <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('Can’t scan? Enter this key manually:') }}</div>
            <div class="mt-1 flex items-center gap-2">
                <code class="rounded-lg bg-gray-50 px-2 py-1 font-mono text-sm dark:bg-gray-900">
                    {{ $secret }}
                </code>
            </div>
        </div>
    @endif

    <div class="mt-3 text-sm text-amber-700 dark:text-amber-300">
        {{ __('The current authenticator remains active until this new secret is confirmed.') }}
        @if(! empty($expiresAt))
            {{ __('This setup expires at :time.', ['time' => $expiresAt]) }}
        @endif
    </div>
@elseif($isEnabled)
    <div class="text-sm text-gray-500 dark:text-gray-400">
        {{ __('Authenticator app is enabled. Regenerate the secret to replace it safely.') }}
    </div>
@else
    <div class="text-sm text-gray-500 dark:text-gray-400">
        {{ __('Start the authenticator setup to generate a QR code.') }}
    </div>
@endif
