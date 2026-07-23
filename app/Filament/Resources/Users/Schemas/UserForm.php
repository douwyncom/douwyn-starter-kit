<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\Role;
use App\Rules\PasswordWithinHashLimit;
use App\Support\Timezone;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'xl' => 3])
                ->columnSpanFull()
                ->schema([
                    Section::make(__('resources/user.sections.account'))
                        ->description(__('resources/user.sections.account_helper'))
                        ->columnSpan(['default' => 1, 'xl' => 2])
                        ->schema([
                            TextInput::make('email')
                                ->label(__('resources/user.fields.email'))
                                ->email()
                                ->required()
                                ->mutateStateForValidationUsing(fn (?string $state): string => Str::lower(trim((string) $state)))
                                ->dehydrateStateUsing(fn (?string $state): string => Str::lower(trim((string) $state)))
                                ->unique(ignoreRecord: true)
                                ->maxLength(190),

                            TextInput::make('password')
                                ->label(__('resources/user.fields.password'))
                                ->password()
                                ->revealable()
                                ->required(fn (string $operation): bool => $operation === 'create')
                                ->dehydrated(fn (?string $state): bool => filled($state))
                                ->minLength(8)
                                ->maxLength(72)
                                ->rule(new PasswordWithinHashLimit)
                                ->same('password_confirmation')
                                ->helperText(__('resources/user.fields.password_helper')),

                            TextInput::make('password_confirmation')
                                ->label(__('resources/user.fields.password_confirmation'))
                                ->password()
                                ->revealable()
                                ->required(fn (string $operation, ?string $state): bool => $operation === 'create' || filled($state))
                                ->dehydrated(false),

                            DateTimePicker::make('email_verified_at')
                                ->label(__('resources/user.fields.email_verified_at'))
                                ->seconds(false),
                        ]),

                    Section::make(__('resources/user.sections.access'))
                        ->description(__('resources/user.sections.access_helper'))
                        ->columnSpan(['default' => 1, 'xl' => 1])
                        ->schema([
                            Select::make('roles')
                                ->label(__('resources/user.fields.roles'))
                                ->relationship(
                                    name: 'roles',
                                    titleAttribute: 'name',
                                    modifyQueryUsing: fn (Builder $query) => $query
                                        ->where('guard_name', 'web')
                                        ->when(
                                            ! (auth()->user()?->hasRole('super_admin') ?? false),
                                            fn (Builder $query) => $query->whereNotIn('name', Role::SYSTEM_ROLES),
                                        )
                                        ->orderBy('name'),
                                )
                                ->multiple()
                                ->preload()
                                ->searchable()
                                ->visible(fn (): bool => auth()->user()?->can('users.assign_roles') ?? false),

                            Toggle::make('is_inactive')
                                ->label(__('resources/user.fields.is_inactive'))
                                ->helperText(__('resources/user.fields.is_inactive_helper')),
                        ]),

                    Section::make(__('resources/user.sections.profile'))
                        ->description(__('resources/user.sections.profile_helper'))
                        ->relationship('profile')
                        ->columnSpanFull()
                        ->columns(['default' => 1, 'md' => 2])
                        ->schema([
                            TextInput::make('first_name')
                                ->label(__('resources/user.fields.first_name'))
                                ->required()
                                ->maxLength(120),

                            TextInput::make('last_name')
                                ->label(__('resources/user.fields.last_name'))
                                ->required()
                                ->maxLength(120),

                            TextInput::make('phone')
                                ->label(__('resources/user.fields.phone'))
                                ->tel()
                                ->maxLength(30),

                            Select::make('locale')
                                ->label(__('resources/user.fields.locale'))
                                ->options(['en' => 'English', 'vi' => 'Tiếng Việt'])
                                ->default(config('app.locale')),

                            Select::make('timezone')
                                ->label(__('resources/user.fields.timezone'))
                                ->options(Timezone::options())
                                ->searchable()
                                ->required()
                                ->rule('timezone')
                                ->default(config('app.timezone'))
                                ->helperText(__('resources/user.fields.timezone_helper')),
                        ]),
                ]),
        ]);
    }
}
