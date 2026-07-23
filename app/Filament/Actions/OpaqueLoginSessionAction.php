<?php

namespace App\Filament\Actions;

use App\Models\LoginSession;
use App\Services\Auth\LoginSessionIdentifier;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Model;

class OpaqueLoginSessionAction extends Action
{
    /** @param Model|array<string, mixed> $record */
    public function resolveRecordKey(Model|array $record): ?string
    {
        if ($record instanceof LoginSession) {
            return app(LoginSessionIdentifier::class)->forSession($record);
        }

        return parent::resolveRecordKey($record);
    }
}
