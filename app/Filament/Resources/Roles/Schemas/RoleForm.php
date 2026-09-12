<?php

namespace App\Filament\Resources\Roles\Schemas;

use App\Models\Permission;
use App\Models\Role;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RoleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(1)
                ->columnSpan(2)
                ->schema([
                    Grid::make([
                        'default' => 1,
                        'md' => 3,
                        'xl' => 4,
                    ])->schema([
                        Section::make(__('resources/role.fields.section_role'))
                            ->description(__('resources/role.fields.section_role_helper'))
                            ->columnSpan([
                                'md' => 1,
                                'xl' => 1,
                            ])
                            ->schema([
                                TextInput::make('name')
                                    ->label(__('resources/role.fields.role_name'))
                                    ->placeholder(__('resources/role.fields.role_placeholder'))
                                    ->required()
                                    ->minLength(2)
                                    ->maxLength(190)
                                    ->autocomplete(false)
                                    ->disabled(fn (?Role $record): bool => $record?->isSystemRole() ?? false)
                                    ->extraInputAttributes(['autocapitalize' => 'words'])
                                    ->helperText(__('resources/role.fields.role_helper')),

                                TextInput::make('guard_name')
                                    ->label(__('resources/role.fields.guard_name'))
                                    ->default('web')
                                    ->disabled()
                                    ->dehydrated()
                                    ->required()
                                    ->maxLength(50)
                                    ->helperText(__('resources/role.fields.guard_name_helper')),
                            ]),

                        Section::make(__('resources/role.fields.section_permissions'))
                            ->description(__('resources/role.fields.section_permissions_helper'))
                            ->columnSpan([
                                'md' => 2,
                                'xl' => 3,
                            ])
                            ->headerActions([
                                Action::make('createPermission')
                                    ->label(__('resources/role.fields.new_permission'))
                                    ->icon(Heroicon::OutlinedPlus)
                                    ->modalHeading(__('resources/role.fields.create_permission'))
                                    ->modalSubmitActionLabel(__('resources/role.fields.create_action'))
                                    ->modalWidth(Width::Medium)
                                    ->authorize(fn (): bool => auth()->user()?->can('permissions.create') ?? false)
                                    ->visible(fn (): bool => auth()->user()?->can('permissions.create') ?? false)
                                    ->schema([
                                        TextInput::make('name')
                                            ->label(__('resources/role.fields.permission_name'))
                                            ->placeholder(__('resources/role.fields.permission_placeholder'))
                                            ->required()
                                            ->maxLength(190),

                                    ])
                                    ->action(function (array $data, Get $get, Set $set): void {
                                        $raw = (string) ($data['name'] ?? '');
                                        $name = Str::of($raw)
                                            ->trim()
                                            ->lower()
                                            ->replaceMatches('/\s+/', '.')      // spaces -> dots
                                            ->replaceMatches('/[^a-z0-9.\-_]/', '') // keep a-z0-9 . - _
                                            ->replaceMatches('/\.{2,}/', '.')   // collapse multiple dots
                                            ->trim('.')
                                            ->toString();

                                        if ($name === '') {
                                            throw ValidationException::withMessages([
                                                'name' => __('resources/role.messages.invalid_permission_name'),
                                            ]);
                                        }

                                        $permission = Permission::firstOrCreate(
                                            ['name' => $name, 'guard_name' => 'web']
                                        );

                                        // Auto-select the newly created permission in the CheckboxList
                                        $selected = (array) ($get('permissions') ?? []);
                                        $selected[] = $permission->getKey();
                                        $selected = array_values(array_unique($selected));

                                        // Setting state triggers a re-render, so the new option becomes available
                                        $set('permissions', $selected);
                                    }),
                            ])
                            ->schema([
                                CheckboxList::make('permissions')
                                    ->label(__('resources/role.fields.permission_name'))
                                    ->relationship(
                                        name: 'permissions',
                                        titleAttribute: 'name',
                                        modifyQueryUsing: fn ($query) => $query->where('guard_name', 'web')->orderBy('name'),
                                    )
                                    // ✅ Make labels readable (prevents ugly wrapping)
                                    ->getOptionLabelFromRecordUsing(fn ($record): string => (string) $record->name)
                                    ->searchable()
                                    ->bulkToggleable()
                                    // ✅ Only increase columns when the container is wide enough
                                    ->columns([
                                        'default' => 1,
                                        'md' => 2,
                                        '2xl' => 3,
                                    ])
                                    ->helperText(__('resources/role.fields.permission_helper')),
                            ]),
                    ]),
                ]),
        ]);
    }
}
