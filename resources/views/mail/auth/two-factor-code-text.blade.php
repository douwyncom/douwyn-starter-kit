{{ $brandName }} · {{ __('notifications.two_factor.eyebrow') }}
{{ $contextLabel }}

{{ $intro }}

{{ __('notifications.two_factor.body', ['code' => $code, 'minutes' => $expirationMinutes]) }}

{{ __('notifications.two_factor.one_time') }}

{{ __('notifications.two_factor.security_title') }}
{{ __('notifications.two_factor.security_body') }}

{{ __('notifications.two_factor.not_requested') }}

{{ __('notifications.two_factor.help') }} {{ $supportAddress }}
{{ $brandUrl }}
