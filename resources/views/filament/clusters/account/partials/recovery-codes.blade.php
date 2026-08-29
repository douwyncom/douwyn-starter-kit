@if(! $enabled)
    <div class="dw-supporting-text">
        {{__('Enable 2FA to generate recovery codes.')}}
    </div>
@elseif(! empty($codes))
    <div class="dw-callout dw-callout-warning" role="status" aria-live="polite">
        {{ __('These recovery codes are shown once. Store them safely before leaving this page.') }}
    </div>

    <ul
        class="dw-recovery-code-list"
        aria-label="{{ __('pages/account.security.recovery_code_title') }}"
    >
        @foreach($codes as $code)
            <li>
                <code class="dw-recovery-code">{{ $code }}</code>
            </li>
        @endforeach
    </ul>
@elseif(($remaining ?? 0) === 0)
    <div class="dw-supporting-text">
        {{__('2FA is enabled (:method). No recovery codes available. Regenerate them.', [
            'method' => $method
        ])}}
    </div>
@else
    <div class="dw-supporting-text">
        {{ __(':count recovery codes remain. Stored codes cannot be displayed again; regenerate them if you lost your copy.', [
            'count' => $remaining,
        ])}}
    </div>
@endif
