<?php

namespace App\Filament\Clusters\Account\Pages;

use App\Filament\Clusters\Account\AccountCluster;
use App\Models\UserProfile;
use App\Support\Timezone;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * @property mixed $form
 */
class Profile extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.clusters.account.pages.profile';

    protected static ?string $cluster = AccountCluster::class;

    protected static ?string $title = 'Profile';

    public static function getNavigationLabel(): string
    {
        return __('pages/account.profile.title');
    }

    protected ?string $subheading = 'Manage your personal information.';

    public function getSubheading(): string|Htmlable|null
    {
        return __('pages/account.profile.subheading');
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    public array $data = [];

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public function mount(): void
    {
        abort_unless(auth()->check(), 403);

        $user = auth()->user();

        $this->form->fill([
            'email' => $user->email,

            // profile fields
            'first_name' => $user->profile?->first_name,
            'last_name' => $user->profile?->last_name,
            'phone' => $user->profile?->phone,
            'timezone' => $user->profile?->timezone ?? config('app.timezone'),
            'locale' => $user->profile?->locale ?? config('app.locale'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->schema([
                Grid::make()
                    ->columns(['default' => 1, 'lg' => 3])
                    ->schema([
                        Section::make(__('pages/account.profile.account'))
                            ->description(__('pages/account.profile.account_description'))
                            ->columnSpan(['default' => 1, 'lg' => 2])
                            ->schema([
                                TextInput::make('email')
                                    ->label('Email')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->helperText(__('pages/account.profile.email_helper')),

                                TextInput::make('first_name')
                                    ->label(__('pages/account.profile.first_name'))
                                    ->maxLength(120)
                                    ->live(onBlur: true),

                                TextInput::make('last_name')
                                    ->label(__('pages/account.profile.last_name'))
                                    ->maxLength(120)
                                    ->live(onBlur: true),

                                TextInput::make('phone')
                                    ->label(__('pages/account.profile.phone'))
                                    ->maxLength(30)
                                    ->live(onBlur: true),
                            ]),

                        Section::make(__('pages/account.profile.preferences'))
                            ->description(__('pages/account.profile.preferences_description'))
                            ->columnSpan(['default' => 1, 'lg' => 1])
                            ->schema([
                                Select::make('timezone')
                                    ->label(__('pages/account.profile.timezone'))
                                    ->options(Timezone::options())
                                    ->searchable()
                                    ->required()
                                    ->rule('timezone')
                                    ->helperText(__('pages/account.profile.timezone_helper'))
                                    ->live(),

                                Select::make('locale')
                                    ->label(__('pages/account.profile.language'))
                                    ->options([
                                        'en' => 'English',
                                        'vi' => 'Tiếng Việt',
                                    ])
                                    ->required()
                                    ->helperText(__('pages/account.profile.language_helper'))
                                    ->live(),
                            ]),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label(__('pages/account.save_change'))
                ->icon(Heroicon::OutlinedCheckCircle)
                ->keyBindings(['mod+s'])
                ->action('save'),
        ];
    }

    public function save(): void
    {
        $user = auth()->user();
        if (is_null($user)) {
            return;
        }

        $state = $this->form->getState();

        /** @var UserProfile $profile */
        $user->profile()->updateOrCreate(
            ['user_uuid' => $user->uuid],
            [
                'first_name' => $state['first_name'] ?? null,
                'last_name' => $state['last_name'] ?? null,
                'phone' => $state['phone'] ?? null,
                'timezone' => $state['timezone'] ?? config('app.timezone'),
                'locale' => $state['locale'] ?? config('app.locale'),
            ]
        );

        Notification::make()
            ->title(__('pages/account.saved'))
            ->body(__('pages/account.profile.saved'))
            ->success()
            ->send();

        if ($state['locale'] !== app()->getLocale()) {
            redirect(request()->header('Referer'));
        }
    }
}
