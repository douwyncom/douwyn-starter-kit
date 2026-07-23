<?php

declare(strict_types=1);

namespace App\Support;

use Douwyn\StarterKit\Contracts\LocaleResolver;
use Douwyn\StarterKit\Platform;
use Illuminate\Http\Request;
use Throwable;

final class StarterKitLocaleResolver implements LocaleResolver
{
    private const array PLATFORM_LOCALES = ['en', 'vi'];

    public function resolveForWeb(Request $request): string
    {
        return $this->firstSupported([
            $this->profileLocale($request->user(Platform::AUTH_GUARD)),
            $request->hasSession() ? $request->session()->get('locale') : null,
            $this->generalLocale(),
            config('app.locale'),
            config('app.fallback_locale'),
        ]);
    }

    public function resolveForApi(Request $request): string
    {
        return $this->firstSupported([
            $this->profileLocale($request->user('sanctum')),
            $this->preferredRequestLocale($request),
            $this->generalLocale(),
            config('app.locale'),
            config('app.fallback_locale'),
        ]);
    }

    public function supportedLocales(): array
    {
        $configured = config('app.supported_locales', self::PLATFORM_LOCALES);

        if (! is_array($configured)) {
            return self::PLATFORM_LOCALES;
        }

        $supported = [];

        foreach ($configured as $locale) {
            if (is_string($locale) && in_array($locale, self::PLATFORM_LOCALES, true)) {
                $supported[$locale] = true;
            }
        }

        return array_keys($supported) ?: self::PLATFORM_LOCALES;
    }

    private function profileLocale(mixed $user): ?string
    {
        $locale = data_get($user, 'profile.locale');

        return is_string($locale) ? $locale : null;
    }

    private function preferredRequestLocale(Request $request): ?string
    {
        if (! $request->headers->has('Accept-Language')) {
            return null;
        }

        $supported = $this->supportedLocales();

        foreach ($request->getLanguages() as $requested) {
            $requested = strtolower(str_replace('_', '-', $requested));

            if (in_array($requested, $supported, true)) {
                return $requested;
            }

            $language = explode('-', $requested, 2)[0];

            if (in_array($language, $supported, true)) {
                return $language;
            }
        }

        return null;
    }

    private function generalLocale(): mixed
    {
        try {
            return Settings::get('general', 'locale');
        } catch (Throwable) {
            // Locale resolution must remain available before migrations and
            // during a transient settings-store outage.
            return null;
        }
    }

    /** @param iterable<mixed> $candidates */
    private function firstSupported(iterable $candidates): string
    {
        $supported = $this->supportedLocales();

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && in_array($candidate, $supported, true)) {
                return $candidate;
            }
        }

        return $supported[0];
    }
}
