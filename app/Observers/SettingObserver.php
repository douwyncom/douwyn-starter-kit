<?php

namespace App\Observers;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

class SettingObserver
{
    /**
     * Handle the Setting "created" event.
     */
    public function created(Setting $setting): void
    {
        $this->flush($setting);
    }

    /**
     * Handle the Setting "updated" event.
     */
    public function updated(Setting $setting): void
    {
        // If group/key changed, clear old cache
        if ($setting->wasChanged(['group', 'key'])) {
            $oldGroup = $setting->getOriginal('group');
            $oldKey = $setting->getOriginal('key');

            Cache::forget("settings:$oldGroup.$oldKey");
        }

        $this->flush($setting);
    }

    /**
     * Handle the Setting "deleted" event.
     */
    public function deleted(Setting $setting): void
    {
        $this->flush($setting);
    }

    /**
     * Handle the Setting "restored" event.
     */
    public function restored(Setting $setting): void
    {
        $this->flush($setting);
    }

    /**
     * Flush cache
     */
    private function flush(Setting $setting): void
    {
        Cache::forget("settings:$setting->group.$setting->key");
        Cache::forget('settings:autoload');
    }
}
