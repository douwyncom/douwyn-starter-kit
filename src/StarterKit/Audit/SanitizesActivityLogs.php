<?php

declare(strict_types=1);

namespace Douwyn\StarterKit\Audit;

use Spatie\Activitylog\Contracts\Activity;

trait SanitizesActivityLogs
{
    public function tapActivity(Activity $activity, string $eventName): void
    {
        $properties = $activity->properties->all();
        // Hidden/encrypted fields may have arbitrary names. Keep the changed
        // field in the audit trail, but never persist its value.
        $protected = $this->getHidden();
        foreach ($this->getCasts() as $field => $cast) {
            if (is_string($cast) && str_starts_with($cast, 'encrypted')) {
                $protected[] = $field;
            }
        }
        foreach (['attributes', 'old'] as $section) {
            foreach ($protected as $field) {
                if (array_key_exists($field, $properties[$section] ?? [])) {
                    $properties[$section][$field] = '[REDACTED]';
                }
            }
        }
        $activity->properties = collect(ActivityLogSanitizer::sanitize($properties));
    }
}
