<?php

declare(strict_types=1);

namespace App\Services\System;

use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Douwyn\StarterKit\Contracts\SystemInsightsReader;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

final class StarterKitSystemInsightsReader implements SystemInsightsReader
{
    public function authorize(Authenticatable $actor, string $report): void
    {
        if (($actor->is_inactive ?? false) || $actor->getAuthIdentifier() === null) {
            throw new AuthorizationException;
        }

        $permission = match ($report) {
            'users_statistics' => 'users.view',
            'queue_status' => 'system.queue.view',
            'access_guide' => 'panel.access',
            default => throw new AuthorizationException,
        };
        Gate::forUser($actor)->authorize($permission);
    }

    public function usersStatistics(Authenticatable $actor, DateTimeImmutable $start, DateTimeImmutable $end, ?DateTimeImmutable $comparisonStart = null, ?DateTimeImmutable $comparisonEnd = null): array
    {
        $this->authorize($actor, 'users_statistics');
        $start = CarbonImmutable::instance($start)->utc();
        $end = CarbonImmutable::instance($end)->utc();
        $seconds = $this->validateInterval($start, $end);
        $explicitComparison = $comparisonStart !== null || $comparisonEnd !== null;
        if ($explicitComparison && ($comparisonStart === null || $comparisonEnd === null)) {
            throw ValidationException::withMessages(['comparison' => 'Supply both comparison bounds.']);
        }
        $previousStart = $explicitComparison ? CarbonImmutable::instance($comparisonStart)->utc() : $start->subSeconds($seconds);
        $previousEnd = $explicitComparison ? CarbonImmutable::instance($comparisonEnd)->utc() : $start;
        $this->validateInterval($previousStart, $previousEnd);
        $counts = User::query()->selectRaw(
            'COUNT(*) AS total_accounts, '
            .'COALESCE(SUM(CASE WHEN is_inactive = ? THEN 1 ELSE 0 END), 0) AS active_accounts, '
            .'COALESCE(SUM(CASE WHEN is_inactive = ? THEN 1 ELSE 0 END), 0) AS inactive_accounts, '
            .'COALESCE(SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END), 0) AS registrations, '
            .'COALESCE(SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END), 0) AS previous_registrations',
            [false, true, $start, $end, $previousStart, $previousEnd],
        )->firstOrFail();
        $current = (int) $counts->registrations;
        $previous = (int) $counts->previous_registrations;

        return [
            'period' => ['start_utc' => $start->toIso8601String(), 'end_utc' => $end->toIso8601String(), 'end_exclusive' => true],
            'registrations' => [
                'current' => $current, 'previous' => $previous, 'delta' => $current - $previous,
                'change_percent' => $previous === 0 ? null : round(($current - $previous) * 100 / $previous, 2),
                'previous_start_utc' => $previousStart->toIso8601String(), 'previous_end_utc' => $previousEnd->toIso8601String(),
                'comparison' => $explicitComparison ? 'explicit_period' : 'previous_equal_elapsed_duration', 'zero_baseline_percent' => null,
            ],
            'current_accounts' => [
                'as_of_utc' => CarbonImmutable::now('UTC')->toIso8601String(),
                'total' => (int) $counts->total_accounts, 'active' => (int) $counts->active_accounts, 'inactive' => (int) $counts->inactive_accounts,
                'definition' => 'Current account status from is_inactive, not login activity or historical status during the report period.',
            ],
        ];
    }

    public function queueStatus(Authenticatable $actor): array
    {
        $this->authorize($actor, 'queue_status');
        $observed = CarbonImmutable::now('UTC');
        $connection = config('queue.default');
        $settings = is_string($connection) ? config('queue.connections.'.$connection, []) : [];
        $driver = is_array($settings) ? ($settings['driver'] ?? null) : null;
        $supportedDrivers = ['database', 'redis', 'sqs', 'beanstalkd', 'sync', 'deferred', 'background', 'failover', 'null'];

        return [
            'as_of_utc' => $observed->toIso8601String(),
            'backend' => in_array($driver, $supportedDrivers, true) ? $driver : 'unsupported',
            'jobs' => $this->jobCounts(is_array($settings) ? $settings : [], $observed->getTimestamp()),
            'failed_jobs' => $this->failedJobCount(),
            'definition' => 'Storage counts at observation time. Reserved jobs are claimed records, not proof of a running worker. No worker-health or payload inspection.',
        ];
    }

    public function accessGuide(Authenticatable $actor, string $locale): array
    {
        $this->authorize($actor, 'access_guide');
        if (! in_array($locale, ['en', 'vi'], true)) {
            throw ValidationException::withMessages(['locale' => 'The locale must be en or vi.']);
        }

        return SystemAccessGuide::forLocale($locale);
    }

    private function jobCounts(array $settings, int $now): array
    {
        $result = ['status' => 'unsupported', 'scope' => 'all_queues_in_configured_jobs_table', 'total' => null, 'ready_unreserved' => null, 'delayed_unreserved' => null, 'reserved' => null];
        if (($settings['driver'] ?? null) !== 'database') {
            return $result;
        }
        $table = $settings['table'] ?? 'jobs';
        if (! $this->validTable($table)) {
            return [...$result, 'status' => 'unavailable'];
        }

        try {
            $counts = DB::connection($settings['connection'] ?? null)->table($table)->selectRaw(
                'COUNT(*) AS total, '
                .'COALESCE(SUM(CASE WHEN reserved_at IS NULL AND available_at <= ? THEN 1 ELSE 0 END), 0) AS ready_unreserved, '
                .'COALESCE(SUM(CASE WHEN reserved_at IS NULL AND available_at > ? THEN 1 ELSE 0 END), 0) AS delayed_unreserved, '
                .'COALESCE(SUM(CASE WHEN reserved_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS reserved',
                [$now, $now],
            )->first();

            return [...$result, 'status' => 'available', 'total' => (int) $counts->total, 'ready_unreserved' => (int) $counts->ready_unreserved,
                'delayed_unreserved' => (int) $counts->delayed_unreserved, 'reserved' => (int) $counts->reserved];
        } catch (Throwable) {
            return [...$result, 'status' => 'unavailable'];
        }
    }

    private function failedJobCount(): array
    {
        $result = ['status' => 'unsupported', 'scope' => 'all_stored_failed_jobs', 'total' => null];
        if (! in_array(config('queue.failed.driver'), ['database', 'database-uuids'], true)) {
            return $result;
        }
        $table = config('queue.failed.table', 'failed_jobs');
        if (! $this->validTable($table)) {
            return [...$result, 'status' => 'unavailable'];
        }

        try {
            $count = DB::connection(config('queue.failed.database'))->table($table)->count();

            return [...$result, 'status' => 'available', 'total' => $count];
        } catch (Throwable) {
            return [...$result, 'status' => 'unavailable'];
        }
    }

    private function validateInterval(CarbonImmutable $start, CarbonImmutable $end): int
    {
        $seconds = $end->getTimestamp() - $start->getTimestamp();
        if ($seconds <= 0 || $seconds > 367 * 86400) {
            throw ValidationException::withMessages(['period' => 'The report requires a positive interval of at most 367 elapsed days.']);
        }

        return $seconds;
    }

    private function validTable(mixed $table): bool
    {
        return is_string($table) && preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/', $table) === 1;
    }
}
