<?php

declare(strict_types=1);

namespace Douwyn\StarterKit\Contracts;

use Illuminate\Database\Eloquent\Model;

interface UserModelResolver
{
    /** @return class-string<Model> */
    public function modelClass(): string;

    public function newModel(): Model;

    public function table(): string;

    public function keyName(): string;
}
