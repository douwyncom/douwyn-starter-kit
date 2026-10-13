<?php

namespace App\Filament\Concerns;

use App\Models\LoginSession;
use App\Services\Auth\LoginSessionIdentifier;
use Closure;
use Filament\Support\ArrayRecord;
use Illuminate\Database\Eloquent\Model;
use LogicException;

trait UsesOpaqueLoginSessionTableKeys
{
    /** Keep browser session IDs private when resolving a bulk selection. */
    protected function withRawLoginSessionSelection(Closure $callback): mixed
    {
        $selected = $this->selectedTableRecords;
        $deselected = $this->deselectedTableRecords;
        $identifiers = app(LoginSessionIdentifier::class);
        $this->selectedTableRecords = $identifiers->rawIds(array_map('strval', $selected));
        $this->deselectedTableRecords = $identifiers->rawIds(array_map('strval', $deselected));

        try {
            return $callback();
        } finally {
            $this->selectedTableRecords = $selected;
            $this->deselectedTableRecords = $deselected;
        }
    }

    /** @param Model|array<string, mixed> $record */
    public function getTableRecordKey(Model|array $record): string
    {
        if (is_array($record)) {
            return (string) ($record[ArrayRecord::getKeyName()]
                ?? throw new LogicException('Record arrays must have a unique key.'));
        }

        if (! $record instanceof LoginSession) {
            return (string) $record->getKey();
        }

        return app(LoginSessionIdentifier::class)->forSession($record);
    }

    /** @return Model|array<string, mixed>|null */
    protected function resolveTableRecord(?string $key): Model|array|null
    {
        if ($key === null) {
            return null;
        }

        $record = app(LoginSessionIdentifier::class)->resolve($key);
        $query = $this->getTable()->getQuery(isResolvingRecord: true);

        if (! $record || ! $query) {
            return null;
        }

        return $query->whereKey($record->getKey())->first();
    }
}
