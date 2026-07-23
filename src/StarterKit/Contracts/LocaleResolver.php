<?php

declare(strict_types=1);

namespace Douwyn\StarterKit\Contracts;

use Illuminate\Http\Request;

interface LocaleResolver
{
    public function resolveForWeb(Request $request): string;

    public function resolveForApi(Request $request): string;

    /** @return list<string> */
    public function supportedLocales(): array;
}
