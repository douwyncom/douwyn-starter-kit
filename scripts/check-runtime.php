<?php

declare(strict_types=1);

$requirements = [
    'PHP 8.5 or newer' => PHP_VERSION_ID >= 80500,
    'native GD extension' => extension_loaded('gd'),
    'native Mbstring extension' => extension_loaded('mbstring'),
    'Zend OPcache extension' => extension_loaded('Zend OPcache'),
];

$missing = array_keys(array_filter(
    $requirements,
    static fn (bool $available): bool => ! $available,
));

if ($missing !== []) {
    fwrite(STDERR, "Runtime check failed:\n");

    foreach ($missing as $requirement) {
        fwrite(STDERR, sprintf("- Missing %s\n", $requirement));
    }

    exit(1);
}

fwrite(STDOUT, sprintf(
    "Runtime check passed (PHP %s; native GD, Mbstring, and OPcache loaded).\n",
    PHP_VERSION,
));
