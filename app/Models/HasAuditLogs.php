<?php

namespace App\Models;

use Douwyn\StarterKit\Audit\SanitizesActivityLogs;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

trait HasAuditLogs
{
    use LogsActivity;
    use SanitizesActivityLogs;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logExcept([
                'password',
                'remember_token',
                'two_factor_secret',
                'two_factor_recovery_codes',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
