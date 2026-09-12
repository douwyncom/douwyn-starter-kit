<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Filament\Clusters\Settings\SettingsCluster;
use App\Support\Settings as SettingsStore;
use App\Support\Timezone;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
// Schemas (layout)
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
// Fields
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * @property mixed $form
 */
class GeneralSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.clusters.settings.pages.general-settings';

    protected static ?string $cluster = SettingsCluster::class;

    public static function getNavigationLabel(): string
    {
        return __('pages/settings.general.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('pages/settings.general.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('pages/settings.general.subheading');
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    public array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('settings.view') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->form->fill($this->defaults());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->schema([
                Grid::make()
                    ->columns(['default' => 1, 'lg' => 3])
                    ->disabled(fn (): bool => ! static::canUpdate())
                    ->schema([
                        Section::make(__('pages/settings.general.title'))
                            ->description(__('pages/settings.general.section_description'))
                            ->columnSpan(['default' => 1, 'lg' => 2])
                            ->afterHeader([
                                Action::make('resetGeneral')
                                    ->label(__('pages/settings.general.action_reset_default'))
                                    ->icon(Heroicon::OutlinedArrowPath)
                                    ->color('gray')
                                    ->visible(fn (): bool => static::canUpdate())
                                    ->action(fn () => $this->resetSection()),
                            ])
                            ->schema([
                                TextInput::make('site_name')
                                    ->label(__('pages/settings.general.site_name'))
                                    ->helperText(__('pages/settings.general.site_name_helper'))
                                    ->required()
                                    ->maxLength(120)
                                    // live validation: validate on blur for smooth UX
                                    ->live(onBlur: true),

                                TextInput::make('site_description')
                                    ->label(__('pages/settings.general.site_description'))
                                    ->helperText(__('pages/settings.general.site_description_helper'))
                                    ->maxLength(255)
                                    ->live(onBlur: true),

                                Select::make('timezone')
                                    ->label(__('pages/settings.general.timezone'))
                                    ->helperText(__('pages/settings.general.timezone_helper'))
                                    ->searchable()
                                    ->options(Timezone::options())
                                    ->required()
                                    ->rule('timezone')
                                    ->live(),

                                Select::make('locale')
                                    ->label(__('pages/settings.general.locale'))
                                    ->helperText(__('pages/settings.general.locale_helper'))
                                    ->options([
                                        'vi' => 'Tiếng Việt (vi)',
                                        'en' => 'English (en)',
                                    ])
                                    ->required()
                                    ->live(),
                            ]),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label(__('pages/settings.save_change'))
                ->icon(Heroicon::OutlinedCheckCircle)
                ->keyBindings(['mod+s'])
                ->visible(fn (): bool => static::canUpdate())
                ->action('save'),
        ];
    }

    public function save(): void
    {
        abort_unless(static::canUpdate(), 403);

        $state = $this->form->getState();

        SettingsStore::set('general', 'site_name', $state['site_name']);
        SettingsStore::set('general', 'site_description', $state['site_description'] ?? null);
        SettingsStore::set('general', 'timezone', $state['timezone']);
        SettingsStore::set('general', 'locale', $state['locale']);

        $locale = (string) $state['locale'];
        $profileLocale = auth()->user()?->profile?->locale;
        $localeChanged = false;

        if (! in_array($profileLocale, config('app.supported_locales', []), true)
            && $locale !== app()->getLocale()) {
            session()->put('locale', $locale);
            app()->setLocale($locale);
            $localeChanged = true;
        }

        Notification::make()
            ->title(__('pages/settings.saved'))
            ->body(__('pages/settings.general.saved'))
            ->success()
            ->send();

        if ($localeChanged) {
            redirect(request()->header('Referer'));
        }
    }

    private function defaults(): array
    {
        return [
            'site_name' => SettingsStore::get('general', 'site_name', config('app.name')),
            'site_description' => SettingsStore::get('general', 'site_description'),
            'timezone' => SettingsStore::get('general', 'timezone', config('app.timezone')),
            'locale' => SettingsStore::get('general', 'locale', config('app.locale')),
        ];
    }

    private static function canUpdate(): bool
    {
        return auth()->user()?->can('settings.update') ?? false;
    }

    private function resetSection(): void
    {
        $this->form->fill([
            'site_name' => config('app.name'),
            'timezone' => config('app.timezone'),
            'locale' => config('app.locale'),
        ]);

        Notification::make()
            ->title(__('pages/settings.reset'))
            ->body(__('pages/settings.general.reset'))
            ->info()
            ->send();
    }
}
