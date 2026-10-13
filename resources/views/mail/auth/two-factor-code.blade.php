<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $locale) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>{{ __('notifications.two_factor.subject') }}</title>
    <style>
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        table { border-collapse: collapse; }
        img { border: 0; outline: none; text-decoration: none; -ms-interpolation-mode: bicubic; }
        a[x-apple-data-detectors] { color: inherit !important; text-decoration: none !important; }
        @media only screen and (max-width: 600px) {
            .email-outer { padding: 24px 12px !important; }
            .email-padding { padding-left: 24px !important; padding-right: 24px !important; }
            .email-title { font-size: 28px !important; line-height: 36px !important; }
            .email-code { font-size: 36px !important; letter-spacing: 8px !important; }
            .email-context { font-size: 10px !important; letter-spacing: 0.5px !important; }
        }
        @media (prefers-color-scheme: dark) {
            .email-background { background-color: #0d0d0f !important; }
            .email-surface { background-color: #151517 !important; border-color: #343437 !important; }
            .email-rule { border-color: #343437 !important; }
            .email-ink { color: #f1f1ec !important; }
            .email-muted { color: #a5a5a9 !important; }
            .email-logo { filter: invert(1); }
            .email-code-panel { background-color: #202023 !important; }
        }
    </style>
</head>
<body class="email-background" style="margin: 0; padding: 0; width: 100%; background-color: #f4f4f0; color: #111113; font-family: 'Manrope Variable', Manrope, 'Segoe UI', Arial, sans-serif;">
    <div style="display: none; font-size: 1px; line-height: 1px; max-height: 0; max-width: 0; opacity: 0; overflow: hidden; mso-hide: all;" aria-hidden="true">{{ __('notifications.two_factor.preheader', ['minutes' => $expirationMinutes]) }}</div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="email-background" style="width: 100%; background-color: #f4f4f0;">
        <tr>
            <td align="center" class="email-outer" style="padding: 48px 20px;">
                <!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0"><tr><td><![endif]-->
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="email-surface" style="width: 100%; max-width: 600px; background-color: #fbfbf8; border: 1px solid #d8d8d3; border-radius: 10px; border-collapse: separate; overflow: hidden;">
                    <tr>
                        <td class="email-padding email-rule" style="padding: 28px 40px; border-bottom: 1px solid #deded9;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="vertical-align: middle;">
                                        <a href="{{ $brandUrl }}" class="email-ink" style="display: inline-block; color: #111113; text-decoration: none; font-size: 18px; font-weight: 600; letter-spacing: -0.5px;">
                                            @if (is_file($logoPath))
                                                <img src="{{ $message->embed($logoPath) }}" alt="" width="32" height="32" class="email-logo" style="display: inline-block; width: 32px; height: 32px; vertical-align: middle; margin-right: 10px;">
                                            @endif
                                            <span style="vertical-align: middle;">{{ $brandName }}</span>
                                        </a>
                                    </td>
                                    <td align="right" class="email-muted email-context" style="vertical-align: middle; padding-left: 12px; color: #66666c; font-family: 'IBM Plex Mono', Consolas, monospace; font-size: 11px; line-height: 18px; letter-spacing: 0.7px;">{{ $contextLabel }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td class="email-padding" style="padding: 36px 40px 32px;">
                            <p class="email-muted" style="margin: 0 0 14px; color: #66666c; font-family: 'IBM Plex Mono', Consolas, monospace; font-size: 11px; line-height: 18px; letter-spacing: 1.8px; text-transform: uppercase;">{{ __('notifications.two_factor.eyebrow') }}</p>
                            <h1 class="email-title email-ink" style="margin: 0 0 16px; color: #111113; font-size: 32px; line-height: 40px; font-weight: 600; letter-spacing: -1.2px;">{{ __('notifications.two_factor.title') }}</h1>
                            <p class="email-muted" style="margin: 0 0 28px; color: #66666c; font-size: 15px; line-height: 25px;">{{ $intro }}</p>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td class="email-ink" style="padding-bottom: 12px; color: #111113; font-size: 12px; line-height: 18px; font-weight: 600;">{{ __('notifications.two_factor.code_label') }}</td>
                                    <td align="right" class="email-muted" style="padding-bottom: 12px; padding-left: 8px; color: #66666c; font-size: 12px; line-height: 18px;">{{ __('notifications.two_factor.expires', ['minutes' => $expirationMinutes]) }}</td>
                                </tr>
                                <tr>
                                    <td colspan="2" align="center" class="email-code-panel" style="padding: 24px 12px; background-color: #111113; border-radius: 6px;">
                                        <span data-otp-code class="email-code" style="display: inline-block; padding-left: 10px; color: #f1f1ec; font-family: 'IBM Plex Mono', Consolas, 'Courier New', monospace; font-size: 42px; font-weight: 500; line-height: 52px; letter-spacing: 10px; font-variant-numeric: tabular-nums; white-space: nowrap;">{{ $code }}</span>
                                    </td>
                                </tr>
                            </table>
                            <p class="email-muted" style="margin: 14px 0 0; color: #66666c; font-size: 12px; line-height: 20px;">{{ __('notifications.two_factor.one_time') }}</p>
                        </td>
                    </tr>
                    <tr>
                        <td class="email-padding email-rule" style="padding: 24px 40px 28px; border-top: 1px solid #deded9;">
                            <h2 class="email-ink" style="margin: 0 0 8px; color: #111113; font-size: 14px; line-height: 22px; font-weight: 600;">{{ __('notifications.two_factor.security_title') }}</h2>
                            <p class="email-muted" style="margin: 0 0 12px; color: #66666c; font-size: 13px; line-height: 22px;">{{ __('notifications.two_factor.security_body') }}</p>
                            <p class="email-muted" style="margin: 0; color: #66666c; font-size: 13px; line-height: 22px;">{{ __('notifications.two_factor.not_requested') }}</p>
                        </td>
                    </tr>
                </table>
                <!--[if mso]></td></tr></table><![endif]-->
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width: 100%; max-width: 600px;">
                    <tr>
                        <td align="center" style="padding: 24px 12px 0;">
                            <p class="email-muted" style="margin: 0 0 8px; color: #66666c; font-size: 12px; line-height: 20px;">{{ __('notifications.two_factor.help') }} <a href="mailto:{{ $supportAddress }}" class="email-ink" style="color: #111113; text-decoration: underline;">{{ $supportAddress }}</a></p>
                            <p class="email-muted" style="margin: 0 0 14px; color: #66666c; font-size: 11px; line-height: 18px;">{{ __('notifications.two_factor.automated') }}</p>
                            <a href="{{ $brandUrl }}" class="email-ink" style="color: #111113; font-size: 11px; line-height: 18px; text-decoration: none;">{{ $brandName }} &middot; {{ parse_url($brandUrl, PHP_URL_HOST) ?: $brandUrl }}</a>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
