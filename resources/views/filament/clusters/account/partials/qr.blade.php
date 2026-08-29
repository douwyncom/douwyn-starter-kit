@if($isPending)
    <div class="dw-qr-ctn">
        @if($qrSvg)
            <div
                class="dw-qr-code"
                role="img"
                aria-label="{{ __('pages/account.security.authenticator_title') }}"
            >
                {!! $qrSvg !!}
            </div>
        @endif
    </div>

    @if($secret)
        <div class="dw-technical-block">
            <div class="dw-supporting-text">{{ __('Can’t scan? Enter this key manually:') }}</div>
            <div class="dw-technical-value-ctn">
                <code class="dw-technical-value">
                    {{ $secret }}
                </code>
            </div>
        </div>
    @endif

    <div class="dw-callout dw-callout-warning" role="status" aria-live="polite">
        {{ __('The current authenticator remains active until this new secret is confirmed.') }}
        @if(! empty($expiresAt))
            {{ __('This setup expires at :time.', ['time' => $expiresAt]) }}
        @endif
    </div>
@elseif($isEnabled)
    <div class="dw-supporting-text">
        {{ __('Authenticator app is enabled. Regenerate the secret to replace it safely.') }}
    </div>
@else
    <div class="dw-supporting-text">
        {{ __('Start the authenticator setup to generate a QR code.') }}
    </div>
@endif
