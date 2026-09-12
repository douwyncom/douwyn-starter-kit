<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class UserRegistrationService
{
    public function __construct(private readonly AccountLifecycleService $lifecycle) {}

    public function register(array $data): User
    {
        $user = DB::transaction(function () use ($data): User {
            $user = User::query()->createOrFirst([
                'email' => $data['email'],
            ], [
                'password' => $data['password'],
            ]);

            if (! $user->wasRecentlyCreated) {
                throw ValidationException::withMessages([
                    'email' => [__('validation.unique', ['attribute' => 'email'])],
                ]);
            }

            $user->profile()->create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'locale' => $data['locale'] ?? app()->getLocale(),
                'timezone' => $data['timezone'] ?? config('app.timezone'),
            ]);

            return $user;
        });

        try {
            $this->lifecycle->sendEmailVerification($user);
        } catch (Throwable $exception) {
            // Account creation must remain successful during a transient mail or
            // queue outage. The authenticated resend endpoint can issue a new link.
            report($exception);
        }

        return $user;
    }
}
