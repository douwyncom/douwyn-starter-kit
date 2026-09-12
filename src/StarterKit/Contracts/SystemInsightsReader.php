<?php

declare(strict_types=1);

namespace Douwyn\StarterKit\Contracts;

use DateTimeImmutable;
use Illuminate\Contracts\Auth\Authenticatable;

/** Bounded, authorized host reports. Implementations must not expose row data or credentials. */
interface SystemInsightsReader
{
    public function authorize(Authenticatable $actor, string $report): void;

    /** UTC starts are inclusive and ends exclusive; optional comparison bounds must be supplied together. */
    public function usersStatistics(Authenticatable $actor, DateTimeImmutable $start, DateTimeImmutable $end, ?DateTimeImmutable $comparisonStart = null, ?DateTimeImmutable $comparisonEnd = null): array;

    /** Aggregate configured queue storage only; unsupported or unavailable counts are null. */
    public function queueStatus(Authenticatable $actor): array;

    /** Curated instructions for locale en or vi; no arbitrary document or filesystem lookup. */
    public function accessGuide(Authenticatable $actor, string $locale): array;
}
