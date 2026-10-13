<?php

declare(strict_types=1);

namespace Douwyn\StarterKit\Audit;

use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** Audit administrator-managed records, without logging queue/telemetry models. */
trait HasModuleActivityLogs
{
    use LogsActivity;
    use SanitizesActivityLogs;

    public function getActivitylogOptions(): LogOptions
    {
        $parts = explode('\\', static::class);
        $module = $parts[array_search('Modules', $parts, true) + 1];

        return LogOptions::defaults()
            ->useLogName('module.'.strtolower($module))
            ->logAll()
            ->logExcept(['created_at', 'updated_at', 'last_seen_at', 'last_health_checked_at', 'last_update_id'])
            ->useAttributeRawValues(array_keys(array_filter(
                $this->getCasts(),
                static fn ($cast): bool => is_string($cast) && str_starts_with($cast, 'encrypted'),
            )))
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
