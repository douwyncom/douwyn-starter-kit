<?php

declare(strict_types=1);

namespace Douwyn\StarterKit\Contracts;

use Douwyn\StarterKit\Modules\ModuleManifest;

interface StarterKitModule
{
    public function manifest(): ModuleManifest;
}
