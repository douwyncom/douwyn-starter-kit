<?php

declare(strict_types=1);

/**
 * @return array<string, array<mixed>>
 */
function localizationPhpCatalog(string $locale): array
{
    $directory = lang_path($locale);
    $catalog = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $relativePath = str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1));
        $translations = require $file->getPathname();

        expect($translations, "$locale/$relativePath must return an array.")->toBeArray();

        $catalog[$relativePath] = $translations;
    }

    ksort($catalog);

    return $catalog;
}

/**
 * @return array<string, string>
 */
function localizationJsonCatalog(string $locale): array
{
    $path = lang_path("$locale.json");
    $translations = json_decode(
        (string) file_get_contents($path),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($translations, basename($path).' must contain a JSON object.')->toBeArray();

    ksort($translations);

    return $translations;
}

/**
 * @return list<string>
 */
function localizationPlaceholders(mixed $value): array
{
    if (! is_string($value)) {
        return [];
    }

    preg_match_all('/(?<!:):[A-Za-z_][A-Za-z0-9_]*/', $value, $matches);
    $placeholders = array_values(array_unique($matches[0]));
    sort($placeholders);

    return $placeholders;
}

function assertLocalizationParity(mixed $english, mixed $vietnamese, string $path): void
{
    expect(get_debug_type($vietnamese), "Translation type mismatch at [$path].")
        ->toBe(get_debug_type($english));

    if (! is_array($english)) {
        expect(localizationPlaceholders($vietnamese), "Placeholder mismatch at [$path].")
            ->toBe(localizationPlaceholders($english));

        return;
    }

    $englishKeys = array_keys($english);
    $vietnameseKeys = array_keys($vietnamese);
    sort($englishKeys);
    sort($vietnameseKeys);

    expect($vietnameseKeys, "Translation key mismatch below [$path].")->toBe($englishKeys);

    foreach ($englishKeys as $key) {
        assertLocalizationParity($english[$key], $vietnamese[$key], "$path.$key");
    }
}

/**
 * @return list<string>
 */
function localizationSourceFiles(): array
{
    $files = [];

    foreach ([app_path(), base_path('src'), base_path('routes'), resource_path('views')] as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

function localizationDecodeLiteral(string $quote, string $literal): string
{
    if ($quote === '"') {
        return stripcslashes($literal);
    }

    return str_replace(['\\\\', "\\'"], ['\\', "'"], $literal);
}

function localizationHasKey(string $locale, string $key): bool
{
    static $groups = [];
    static $json = [];

    $separator = strpos($key, '.');

    if ($separator !== false) {
        $group = substr($key, 0, $separator);
        $path = lang_path("$locale/$group.php");

        if (is_file($path)) {
            $groups[$locale][$group] ??= require $path;
            $value = $groups[$locale][$group];

            foreach (explode('.', substr($key, $separator + 1)) as $segment) {
                if (! is_array($value) || ! array_key_exists($segment, $value)) {
                    return false;
                }

                $value = $value[$segment];
            }

            return true;
        }
    }

    $json[$locale] ??= localizationJsonCatalog($locale);

    return array_key_exists($key, $json[$locale]);
}

/**
 * @return list<string>
 */
function localizationLiteralTranslationFailures(): array
{
    $failures = [];
    $pattern = '/\b(?:__|trans_choice)\(\s*([\'\"])((?:\\\\.|(?!\1).)*)\1\s*(?=[,)])/s';

    foreach (localizationSourceFiles() as $path) {
        $source = (string) file_get_contents($path);
        preg_match_all($pattern, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $match) {
            $quote = $match[1][0];
            $rawKey = $match[2][0];

            if ($quote === '"' && str_contains($rawKey, '$')) {
                continue;
            }

            $key = localizationDecodeLiteral($quote, $rawKey);
            $missingLocales = array_values(array_filter(
                ['en', 'vi'],
                fn (string $locale): bool => ! localizationHasKey($locale, $key),
            ));

            if ($missingLocales === []) {
                continue;
            }

            $line = substr_count(substr($source, 0, $match[0][1]), "\n") + 1;
            $relativePath = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
            $failures[] = sprintf(
                '%s:%d [%s] %s',
                $relativePath,
                $line,
                implode(', ', $missingLocales),
                $key,
            );
        }
    }

    sort($failures);

    return array_values(array_unique($failures));
}

/**
 * @return list<string>
 */
function localizationVisibleUiHardcodes(): array
{
    $allowed = [
        'Android',
        'Douwyn',
        'English',
        'English (en)',
        'iOS',
        'Laracasts',
        'Tiếng Việt',
        'Tiếng Việt (vi)',
        'UUID',
        'Web',
        '—',
    ];
    $failures = [];
    $patterns = [
        '/(?:(?:->(?:label|title|body|heading|description|helperText|placeholder|modalHeading|modalDescription|modalSubmitActionLabel|modalCancelActionLabel))|(?:Section::make))\(\s*([\'\"])((?:\\\\.|(?!\1).)*)\1\s*(?=[,)])/s',
        '/\$(?:title|navigationLabel|navigationGroup|subheading)\s*=\s*([\'\"])((?:\\\\.|(?!\1).)*)\1\s*;/s',
    ];

    foreach (localizationSourceFiles() as $path) {
        if (! str_starts_with($path, app_path('Filament').DIRECTORY_SEPARATOR)) {
            continue;
        }

        $source = (string) file_get_contents($path);

        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

            foreach ($matches as $match) {
                $value = localizationDecodeLiteral($match[1][0], $match[2][0]);

                if ($value === '' || in_array($value, $allowed, true)) {
                    continue;
                }

                $line = substr_count(substr($source, 0, $match[0][1]), "\n") + 1;
                $relativePath = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
                $failures[] = "$relativePath:$line $value";
            }
        }
    }

    foreach (localizationSourceFiles() as $path) {
        if (! str_starts_with($path, resource_path('views').DIRECTORY_SEPARATOR)
            || ! str_ends_with($path, '.blade.php')) {
            continue;
        }

        $source = (string) file_get_contents($path);
        preg_match_all('/>\s*(\p{L}[^<>{}\n]*?)\s*</u', $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $match) {
            $value = trim($match[1][0]);

            if ($value === '' || in_array($value, $allowed, true)) {
                continue;
            }

            $line = substr_count(substr($source, 0, $match[0][1]), "\n") + 1;
            $relativePath = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
            $failures[] = "$relativePath:$line $value";
        }
    }

    sort($failures);

    return array_values(array_unique($failures));
}

/**
 * @return list<string>
 */
function localizationRuntimeMessageHardcodes(): array
{
    $failures = [];
    $patterns = [
        '/[\'\"]message[\'\"]\s*=>\s*([\'\"])((?:\\\\.|(?!\1).)*)\1/s',
        '/->(?:subject|line)\(\s*([\'\"])((?:\\\\.|(?!\1).)*)\1\s*(?=[,)])/s',
        '/\bMail::raw\(\s*([\'\"])((?:\\\\.|(?!\1).)*)\1\s*,/s',
    ];

    foreach (localizationSourceFiles() as $path) {
        if (! str_starts_with($path, app_path().DIRECTORY_SEPARATOR)) {
            continue;
        }

        $source = (string) file_get_contents($path);

        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

            foreach ($matches as $match) {
                $value = localizationDecodeLiteral($match[1][0], $match[2][0]);

                if (! preg_match('/^\p{Lu}.*(?:[.!?]|\s.*)$/u', $value)) {
                    continue;
                }

                $line = substr_count(substr($source, 0, $match[0][1]), "\n") + 1;
                $relativePath = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
                $failures[] = "$relativePath:$line $value";
            }
        }
    }

    sort($failures);

    return array_values(array_unique($failures));
}

it('keeps English and Vietnamese translation catalogues structurally compatible', function (): void {
    $english = localizationPhpCatalog('en');
    $vietnamese = localizationPhpCatalog('vi');

    expect(array_keys($vietnamese))->toBe(array_keys($english));

    foreach ($english as $file => $translations) {
        assertLocalizationParity($translations, $vietnamese[$file], $file);
    }

    assertLocalizationParity(
        localizationJsonCatalog('en'),
        localizationJsonCatalog('vi'),
        'JSON catalogue',
    );
});

it('defines readable validation attribute names for public API fields', function (): void {
    $required = [
        'app_version',
        'challenge_token',
        'current_password',
        'device_id',
        'device_name',
        'email',
        'first_name',
        'last_name',
        'locale',
        'otp',
        'password',
        'password_confirmation',
        'platform',
        'recovery_code',
        'refresh_token',
        'request_id',
        'setup_token',
        'timezone',
        'token',
    ];

    foreach (['en', 'vi'] as $locale) {
        $validation = require lang_path("$locale/validation.php");

        foreach ($required as $attribute) {
            expect($validation['attributes'][$attribute] ?? null, "$locale validation attribute [$attribute]")
                ->toBeString()
                ->not->toBe('');
        }
    }
});

it('uses readable Vietnamese attribute names in validation messages', function (): void {
    app()->setLocale('vi');

    $validator = validator([], [
        'current_password' => ['required'],
        'refresh_token' => ['required'],
    ]);

    expect($validator->errors()->first('current_password'))
        ->toContain('mật khẩu hiện tại')
        ->not->toContain('current_password')
        ->and($validator->errors()->first('refresh_token'))
        ->toContain('mã làm mới')
        ->not->toContain('refresh_token');
});

it('defines every literal translation key in both supported locales', function (): void {
    expect(localizationLiteralTranslationFailures())
        ->toBe([], 'Literal translation calls without complete en/vi catalogue entries were found.');
});

it('does not hardcode visible application copy', function (): void {
    expect(localizationVisibleUiHardcodes())
        ->toBe([], 'Visible Filament and Blade copy must use the localization catalogue.');
});

it('does not hardcode API or email messages', function (): void {
    expect(localizationRuntimeMessageHardcodes())
        ->toBe([], 'Runtime API and email copy must use the localization catalogue.');
});
