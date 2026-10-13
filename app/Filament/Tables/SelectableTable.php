<?php

declare(strict_types=1);

namespace App\Filament\Tables;

use App\Filament\Actions\ExportSelectedRecords;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * Keep the shared bulk action even when a resource replaces its toolbar.
 * Binding the table once also covers commercial modules without copying code.
 */
class SelectableTable extends Table
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->maxSelectableRecords(1000);
        $this->toolbarActions([]);
    }

    public function toolbarActions(array|ActionGroup $actions): static
    {
        $actions = Arr::wrap($actions);
        $hasExport = collect($actions)->contains(static function ($action): bool {
            return $action instanceof ActionGroup
                ? array_key_exists('exportSelected', $action->getFlatActions())
                : $action->getName() === 'exportSelected';
        });

        if (! $hasExport) {
            $group = collect($actions)->first(fn ($action): bool => $action instanceof BulkActionGroup);

            if ($group) {
                $group->actions([...$group->getActions(), ExportSelectedRecords::make()]);
            } else {
                $actions[] = BulkActionGroup::make([ExportSelectedRecords::make()]);
            }
        }

        return parent::toolbarActions($actions);
    }

    public function isRecordSelectable(Model|array $record): bool
    {
        $canExport = $record instanceof Model && ExportSelectedRecords::canExport($this)
            && ExportSelectedRecords::canRead($this, $record);
        $hasOtherAction = collect($this->getFlatBulkActions())->except('exportSelected')
            ->contains(fn ($action): bool => $action->isVisible());

        return $hasOtherAction ? parent::isRecordSelectable($record) || $canExport : $canExport;
    }
}
