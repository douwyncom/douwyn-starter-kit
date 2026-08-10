<?php

declare(strict_types=1);

namespace Douwyn\StarterKit\Contracts;

interface PrivateStorageResolver
{
    /**
     * Resolve a configured private filesystem disk.
     *
     * Implementations must fail closed when the requested disk is missing or
     * publicly visible. A remote disk may also serve public media when its
     * filesystem configuration still defaults private; callers must persist
     * private visibility for every confidential object.
     */
    public function resolve(?string $preferredDisk = null): string;
}
