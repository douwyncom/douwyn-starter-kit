<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\note;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class StarterKitUserCommand extends Command
{
    protected $signature = 'app:starter-kit-user';

    protected $description = 'Create a Starter Kit user as Super Admin.';

    public function handle(): int
    {
        if (! Role::query()->where('name', 'super_admin')->where('guard_name', 'web')->exists()) {
            $this->error('The super_admin role is missing. Run php artisan app:starter-kit-install first.');

            return self::FAILURE;
        }

        $firstName = text(
            label: 'First Name',
            placeholder: 'Douwyn Starter Kit',
            required: true
        );

        $lastName = text(
            label: 'Last Name',
            placeholder: 'Douwyn Starter Kit',
            required: true
        );

        $email = text(
            label: 'Email',
            placeholder: 'admin@localhost.test',
            required: true,
            validate: function (string $value) {
                $validator = Validator::make(
                    ['email' => $value],
                    ['email' => ['required', 'email', Rule::unique('users', 'email')]]
                );

                return $validator->fails()
                    ? $validator->errors()->first('email')
                    : null;
            }
        );

        $plainPassword = password(
            label: 'Password',
            required: true,
            validate: fn (string $value) => match (true) {
                strlen($value) < 8 => 'Password must be at least 8 characters.',
                strlen($value) > 72 => 'Password must not exceed 72 bytes.',
                default => null,
            }
        );

        $confirmPassword = password(
            label: 'Confirm password',
            required: true,
            validate: fn (string $value) => $value !== $plainPassword ? 'Passwords do not match.' : null
        );

        $shouldCreate = confirm(
            label: "Create user '$firstName' <$email> as super admin?",
        );

        if (! $shouldCreate) {
            note('Cancelled.');

            return self::SUCCESS;
        }

        $user = User::query()->create([
            'email' => $email,
            'password' => Hash::make($confirmPassword),
        ]);

        $user->assignRole('super_admin');

        $user->profile()->create([
            'first_name' => $firstName,
            'last_name' => $lastName,
        ]);

        $this->info("✅ Super admin created: $user->email");

        return self::SUCCESS;
    }
}
