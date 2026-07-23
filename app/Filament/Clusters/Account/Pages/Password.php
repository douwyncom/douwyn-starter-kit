<?php

namespace App\Filament\Clusters\Account\Pages;

use App\Enums\TokenRevokeReason;
use App\Filament\Clusters\Account\AccountCluster;
use App\Models\User;
use App\Rules\PasswordWithinHashLimit;
use App\Services\Auth\AccessRevocationService;
use App\Services\Security\SecurityTelemetry;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * @property mixed $form
 */
class Password extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.clusters.account.pages.password';

    protected static ?string $cluster = AccountCluster::class;

    protected static ?string $title = 'Password';

    public static function getNavigationLabel(): string
    {
        return __('pages/account.password.title');
    }

    protected ?string $subheading = 'Change your account password.';

    public function getSubheading(): string|Htmlable|null
    {
        return __('pages/account.password.subheading');
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?int $navigationSort = 2;

    public array $data = [];

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public function mount(): void
    {
        abort_unless(auth()->check(), 403);

        $this->form->fill([
            'current_password' => '',
            'password' => '',
            'password_confirmation' => '',
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->schema([
                Section::make(__('pages/account.password.change_password'))
                    ->description(__('pages/account.password.change_password_helper'))
                    ->schema([
                        TextInput::make('current_password')
                            ->label(__('pages/account.password.current_password'))
                            ->password()
                            ->revealable()
                            ->required()
                            ->maxLength(72)
                            ->rule(new PasswordWithinHashLimit)
                            ->live(onBlur: true)
                            ->rule(function () {
                                return function (string $attribute, $value, $fail) {
                                    $user = auth()->user();
                                    if (! $user || ! Hash::check($value, $user->password)) {
                                        $fail(__('pages/account.password.current_password_helper'));
                                    }
                                };
                            }),

                        TextInput::make('password')
                            ->label(__('pages/account.password.new_password'))
                            ->password()
                            ->revealable()
                            ->required()
                            ->minLength(8)
                            ->maxLength(72)
                            ->rule(new PasswordWithinHashLimit)
                            ->live(onBlur: true)
                            ->helperText(__('pages/account.password.new_password_helper')),

                        TextInput::make('password_confirmation')
                            ->label(__('pages/account.password.confirm_new_password'))
                            ->password()
                            ->revealable()
                            ->required()
                            ->same('password')
                            ->live(onBlur: true),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('updatePassword')
                ->label(__('pages/account.password.update_password'))
                ->icon(Heroicon::OutlinedCheckCircle)
                ->action('save'),
        ];
    }

    public function save(): void
    {
        $user = auth()->user();

        if (is_null($user)) {
            abort(403);
        }

        $state = $this->form->getState();
        request()->attributes->set(SecurityTelemetry::CHANNEL_ATTRIBUTE, 'filament');

        $user = DB::transaction(function () use ($user, $state): User {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if (! Hash::check($state['current_password'], $lockedUser->password)) {
                throw ValidationException::withMessages([
                    'data.current_password' => [__('pages/account.password.current_password_helper')],
                ]);
            }

            $lockedUser->forceFill(['password' => $state['password']])->save();
            app(AccessRevocationService::class)->revokeOtherAccess(
                $lockedUser,
                request(),
                TokenRevokeReason::PASSWORD_CHANGED,
            );

            return $lockedUser;
        });
        Auth::guard('web')->setUser($user);
        app(SecurityTelemetry::class)->passwordChanged($user, request(), 'filament');

        $this->form->fill([
            'current_password' => '',
            'password' => '',
            'password_confirmation' => '',
        ]);

        Notification::make()
            ->title(__('pages/account.updated'))
            ->body(__('pages/account.password.updated_body'))
            ->success()
            ->send();
    }
}
