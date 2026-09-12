<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Dotenv\Exception\InvalidFileException;
use Illuminate\Support\Env;
use Laravel\Octane\Octane;

$arguments = array_slice($argv, 1);
$supportedArguments = ['--octane', '--help'];
$unknownArguments = array_values(array_diff($arguments, $supportedArguments));

if (in_array('--help', $arguments, true)) {
    fwrite(STDOUT, <<<'HELP'
Usage: php scripts/check-runtime.php [--octane]

Without options, the script enforces the base Laravel runtime and reports
Octane/Swoole gaps as warnings. Use --octane, or set
OCTANE_RUNTIME_REQUIRED=true, to make Octane/Swoole checks mandatory.
The project's .env file is read when dependencies are installed; exported
environment variables take precedence. Production with OCTANE_SERVER=swoole
also makes these checks mandatory.
HELP.PHP_EOL);

    exit(0);
}

if ($unknownArguments !== []) {
    fwrite(STDERR, sprintf(
        "Unknown runtime check option(s): %s\nUse --help for usage.\n",
        implode(', ', $unknownArguments),
    ));

    exit(2);
}

$autoloadPath = dirname(__DIR__).'/vendor/autoload.php';

if (is_file($autoloadPath)) {
    require_once $autoloadPath;

    try {
        Dotenv::create(Env::getRepository(), dirname(__DIR__))->safeLoad();
    } catch (InvalidFileException) {
        fwrite(STDERR, "Runtime check failed: the .env file is invalid.\n");

        exit(1);
    }
}

$environmentValue = static fn (string $name): mixed => class_exists(Env::class)
    ? Env::get($name)
    : getenv($name);

$environmentFlag = static function (string $name) use ($environmentValue): bool {
    $value = $environmentValue($name);

    return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) === true;
};

$configuredServer = $environmentValue('OCTANE_SERVER');
$configuredServer = is_string($configuredServer) ? strtolower(trim($configuredServer)) : '';
$applicationEnvironment = $environmentValue('APP_ENV');
$applicationEnvironment = is_string($applicationEnvironment)
    ? strtolower(trim($applicationEnvironment))
    : '';

$octaneRequired = in_array('--octane', $arguments, true)
    || $environmentFlag('OCTANE_RUNTIME_REQUIRED')
    || ($applicationEnvironment === 'production' && $configuredServer === 'swoole');

$baseRequirements = [
    'PHP 8.5 or newer' => PHP_VERSION_ID >= 80500,
    'native GD extension' => extension_loaded('gd'),
    'native Mbstring extension' => extension_loaded('mbstring'),
    'Zend OPcache extension' => extension_loaded('Zend OPcache'),
];

$swooleLoaded = extension_loaded('swoole') || extension_loaded('openswoole');
$extension = extension_loaded('openswoole') ? 'openswoole' : 'swoole';
$extensionName = $extension === 'openswoole' ? 'OpenSwoole' : 'Swoole';
$minimumSwooleVersion = $extension === 'openswoole' ? '26.2.0' : '6.2.0';
$swooleVersion = phpversion($extension);
$octaneRequirements = [
    'Composer dependencies' => is_file($autoloadPath),
    'Laravel Octane package' => class_exists(Octane::class),
    'Swoole or OpenSwoole extension' => $swooleLoaded,
    'PCNTL extension' => extension_loaded('pcntl'),
    'POSIX extension' => extension_loaded('posix'),
    'OPcache enabled for the CLI SAPI' => filter_var(
        ini_get('opcache.enable_cli'),
        FILTER_VALIDATE_BOOL,
    ),
    'E in PHP variables_order' => str_contains((string) ini_get('variables_order'), 'E'),
];

if ($swooleLoaded) {
    $octaneRequirements["$extensionName $minimumSwooleVersion or newer"] = is_string($swooleVersion)
        && version_compare($swooleVersion, $minimumSwooleVersion, '>=');
}

$missingBase = array_keys(array_filter(
    $baseRequirements,
    static fn (bool $available): bool => ! $available,
));

$missingOctane = array_keys(array_filter(
    $octaneRequirements,
    static fn (bool $available): bool => ! $available,
));

if ($missingBase !== [] || ($octaneRequired && $missingOctane !== [])) {
    fwrite(STDERR, "Runtime check failed:\n");

    foreach ([...$missingBase, ...($octaneRequired ? $missingOctane : [])] as $requirement) {
        fwrite(STDERR, sprintf("- Missing %s\n", $requirement));
    }

    exit(1);
}

if ($missingOctane !== []) {
    fwrite(STDOUT, sprintf(
        "Base runtime check passed (PHP %s; native GD, Mbstring, and OPcache loaded).\n",
        PHP_VERSION,
    ));
    fwrite(STDOUT, "Octane/Swoole readiness warnings (run with --octane to enforce):\n");

    foreach ($missingOctane as $requirement) {
        fwrite(STDOUT, sprintf("- Missing %s\n", $requirement));
    }

    exit(0);
}

fwrite(STDOUT, sprintf(
    "Runtime check passed (PHP %s; Laravel Octane; %s %s; GD, Mbstring, OPcache, PCNTL, and POSIX loaded).\n",
    PHP_VERSION,
    $extensionName,
    $swooleVersion ?: 'unknown',
));
