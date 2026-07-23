<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use Douwyn\StarterKit\StarterKitServiceProvider;

return [
    StarterKitServiceProvider::class,
    AppServiceProvider::class,
    AdminPanelProvider::class,
];
