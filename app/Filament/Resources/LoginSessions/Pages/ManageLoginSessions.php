<?php

namespace App\Filament\Resources\LoginSessions\Pages;

use App\Filament\Concerns\UsesOpaqueLoginSessionTableKeys;
use App\Filament\Resources\LoginSessions\LoginSessionResource;
use App\Models\LoginSession;
use App\Services\Auth\LoginSessionIdentifier;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Database\Eloquent\Builder;

class ManageLoginSessions extends ManageRecords
{
    use UsesOpaqueLoginSessionTableKeys;

    protected static string $resource = LoginSessionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /** @return array<string> */
    public function getAllSelectableTableRecordKeys(): array
    {
        $rawIds = parent::getAllSelectableTableRecordKeys();
        $sessions = LoginSession::query()->whereKey($rawIds)->get()->keyBy(
            fn (LoginSession $session): string => (string) $session->getKey(),
        );
        $identifiers = app(LoginSessionIdentifier::class);

        return collect($rawIds)
            ->map(fn (string $rawId): ?string => $sessions->has($rawId)
                ? $identifiers->forSession($sessions->get($rawId))
                : null)
            ->filter()
            ->values()
            ->all();
    }

    public function getSelectedTableRecordsQuery(
        bool $shouldFetchSelectedRecords = true,
        ?int $chunkSize = null,
    ): Builder {
        $selected = $this->selectedTableRecords;
        $deselected = $this->deselectedTableRecords;
        $identifiers = app(LoginSessionIdentifier::class);

        $this->selectedTableRecords = $identifiers->rawIds(array_map('strval', $selected));
        $this->deselectedTableRecords = $identifiers->rawIds(array_map('strval', $deselected));

        try {
            return parent::getSelectedTableRecordsQuery($shouldFetchSelectedRecords, $chunkSize);
        } finally {
            $this->selectedTableRecords = $selected;
            $this->deselectedTableRecords = $deselected;
        }
    }
}
