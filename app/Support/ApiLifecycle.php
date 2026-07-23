<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ApiLifecycle
{
    public const string REQUEST_ID_HEADER = 'X-Request-ID';

    public const string VERSION_HEADER = 'X-API-Version';

    public const string HTTP_DATE_FORMAT = 'D, d M Y H:i:s \G\M\T';

    public function begin(Request $request): string
    {
        $requestId = $this->requestId($request);

        $request->attributes->set('request_id', $requestId);
        $request->headers->set(self::REQUEST_ID_HEADER, $requestId);
        Context::add('request_id', $requestId);

        return $requestId;
    }

    public function apply(Response $response, Request $request): Response
    {
        $requestId = $request->attributes->get('request_id');

        if (! is_string($requestId) || ! Str::isUuid($requestId)) {
            $requestId = $this->begin($request);
        }

        $response->headers->set(self::REQUEST_ID_HEADER, $requestId);
        $response->headers->set(self::VERSION_HEADER, (string) config('api.version'));

        foreach ($this->deprecationHeaders() as $name => $value) {
            if ($name === 'Link') {
                if (! in_array($value, $response->headers->all($name), true)) {
                    $response->headers->set($name, $value, false);
                }

                continue;
            }

            $response->headers->set($name, $value);
        }

        return $response;
    }

    public function finish(): void
    {
        Context::forget('request_id');
    }

    private function requestId(Request $request): string
    {
        $candidate = trim((string) $request->headers->get(self::REQUEST_ID_HEADER, ''));

        return Str::isUuid($candidate) ? $candidate : (string) Str::uuid7();
    }

    /** @return array<string, string> */
    private function deprecationHeaders(): array
    {
        if (! config('api.lifecycle.deprecated', false)) {
            return [];
        }

        $deprecationAt = $this->parseDate(config('api.lifecycle.deprecation_at'));

        if (! $deprecationAt) {
            return [];
        }

        $headers = ['Deprecation' => '@'.$deprecationAt->getTimestamp()];
        $sunsetAt = $this->parseDate(config('api.lifecycle.sunset_at'));

        if ($sunsetAt && $sunsetAt >= $deprecationAt) {
            $headers['Sunset'] = $sunsetAt
                ->setTimezone(new DateTimeZone('GMT'))
                ->format(self::HTTP_DATE_FORMAT);
        }

        $documentationUrl = trim((string) config('api.lifecycle.deprecation_documentation_url'));

        if ($this->isSafeHttpUrl($documentationUrl)) {
            $headers['Link'] = sprintf('<%s>; rel="deprecation"; type="text/html"', $documentationUrl);
        }

        return $headers;
    }

    private function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function isSafeHttpUrl(string $url): bool
    {
        if ($url === '' || str_contains($url, '<') || str_contains($url, '>')) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        return in_array($scheme, ['http', 'https'], true)
            && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
