<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$errors = [];

$readJson = static function (string $path) use (&$errors): array {
    if (! is_file($path)) {
        $errors[] = sprintf('Required file is missing: %s', $path);

        return [];
    }

    try {
        return json_decode(
            (string) file_get_contents($path),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    } catch (JsonException $exception) {
        $errors[] = sprintf('Invalid JSON in %s: %s', $path, $exception->getMessage());

        return [];
    }
};

$isCommercialPackage = static fn (string $package): bool => str_starts_with($package, 'douwyncom/starter-kit-')
    && $package !== 'douwyncom/starter-kit-platform';

$composer = $readJson($root.'/composer.json');
$lock = $readJson($root.'/composer.lock');
$nuxtPackage = $readJson($root.'/packages/nuxt-api/package.json');

if (($composer['license'] ?? null) !== 'Apache-2.0') {
    $errors[] = 'The root Composer package must use Apache-2.0.';
}

if (($nuxtPackage['license'] ?? null) !== 'Apache-2.0') {
    $errors[] = 'The official Nuxt API package must use Apache-2.0.';
}

foreach (['require', 'require-dev'] as $dependencyGroup) {
    foreach (array_keys($composer[$dependencyGroup] ?? []) as $package) {
        if ($isCommercialPackage((string) $package)) {
            $errors[] = sprintf(
                'Commercial package in root Composer %s: %s',
                $dependencyGroup,
                $package,
            );
        }
    }
}

foreach ($composer['repositories'] ?? [] as $repository) {
    if (! is_array($repository)) {
        continue;
    }

    $url = ltrim(str_replace('\\', '/', (string) ($repository['url'] ?? '')), './');

    if (($repository['type'] ?? null) === 'path' && str_starts_with($url, 'modules/')) {
        $errors[] = sprintf('Root Composer path repository points into modules/: %s', $url);
    }
}

foreach (['packages', 'packages-dev'] as $packageGroup) {
    foreach ($lock[$packageGroup] ?? [] as $package) {
        $name = (string) ($package['name'] ?? '');

        if ($isCommercialPackage($name)) {
            $errors[] = sprintf('Commercial package in composer.lock: %s', $name);
        }

        foreach (['dist', 'source'] as $sourceType) {
            $url = str_replace('\\', '/', (string) ($package[$sourceType]['url'] ?? ''));

            if (str_contains($url, 'modules/')) {
                $errors[] = sprintf(
                    'composer.lock %s URL points into modules/: %s',
                    $sourceType,
                    $url,
                );
            }
        }
    }
}

foreach (array_keys($lock['stability-flags'] ?? []) as $package) {
    if ($isCommercialPackage((string) $package)) {
        $errors[] = sprintf('Commercial stability flag in composer.lock: %s', $package);
    }
}

$gitignore = (string) file_get_contents($root.'/.gitignore');

if (! preg_match('/^\/modules\/$/m', $gitignore)) {
    $errors[] = '.gitignore must exclude the root /modules/ workspace.';
}

foreach ([
    'LICENSE',
    'NOTICE',
    'TRADEMARKS.md',
    'SECURITY.md',
    'CONTRIBUTING.md',
    'CODE_OF_CONDUCT.md',
    'SUPPORT.md',
    'docs/commercial-modules.md',
    'packages/nuxt-api/LICENSE',
    'packages/nuxt-api/NOTICE',
    'scripts/check-runtime.php',
] as $file) {
    if (! is_file($root.'/'.$file)) {
        $errors[] = sprintf('Required open-source policy file is missing: %s', $file);
    }
}

$generatedFiles = [
    'packages/nuxt-api/openapi.json',
    'packages/nuxt-api/src/openapi.ts',
    'packages/nuxt-api/dist/openapi.d.ts',
];
$privateNeedles = [
    'douwyncom/starter-kit-ledger',
    'starter-kit-ledger',
    'Douwyn\\StarterKit\\Modules\\Ledger',
    'Douwyn\\\\StarterKit\\\\Modules\\\\Ledger',
    '"/ledger/',
    'ledgerList',
    'ledgerGet',
];

foreach ($generatedFiles as $generatedFile) {
    $path = $root.'/'.$generatedFile;

    if (! is_file($path)) {
        $errors[] = sprintf('Generated public contract is missing: %s', $generatedFile);

        continue;
    }

    $contents = (string) file_get_contents($path);

    foreach ($privateNeedles as $needle) {
        if (str_contains($contents, $needle)) {
            $errors[] = sprintf(
                'Generated public contract %s contains private marker: %s',
                $generatedFile,
                $needle,
            );
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, "Public boundary check failed:\n");

    foreach (array_values(array_unique($errors)) as $error) {
        fwrite(STDERR, sprintf("- %s\n", $error));
    }

    exit(1);
}

fwrite(STDOUT, "Public boundary check passed.\n");
