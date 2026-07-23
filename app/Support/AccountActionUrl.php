<?php

namespace App\Support;

class AccountActionUrl
{
    public static function make(string $url, string $token): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.http_build_query(
            ['token' => $token],
            '',
            '&',
            PHP_QUERY_RFC3986,
        );
    }
}
