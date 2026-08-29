<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\UserProfile;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;

final class ReencryptUserProfilesCommand extends Command
{
    protected $signature = 'app:user-profiles-reencrypt
        {--chunk=200 : Number of profiles to process per database transaction}
        {--force : Run without confirmation in production}';

    protected $description = 'Re-encrypt protected user profile fields with the current APP_KEY.';

    public function handle(): int
    {
        $chunkSize = filter_var(
            $this->option('chunk'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 5000]],
        );

        if ($chunkSize === false) {
            $this->components->error('The --chunk value must be between 1 and 5000.');

            return self::INVALID;
        }

        if (
            app()->isProduction()
            && ! $this->option('force')
            && ! $this->confirm(
                'Re-encrypt every protected user profile field with the current APP_KEY?',
            )
        ) {
            $this->components->warn('User profile re-encryption was cancelled.');

            return self::SUCCESS;
        }

        /** @var Encrypter $encrypter */
        $encrypter = Crypt::getFacadeRoot();
        $currentKeyEncrypter = new Encrypter(
            $encrypter->getKey(),
            (string) config('app.cipher'),
        );
        $processed = 0;

        $connection = (new UserProfile)->getConnection();

        UserProfile::query()
            ->select('uuid')
            ->chunkById(
                $chunkSize,
                function ($profiles) use (
                    &$processed,
                    $connection,
                    $currentKeyEncrypter,
                ): void {
                    $profileUuids = $profiles->modelKeys();

                    $connection->transaction(function () use (
                        $profileUuids,
                        &$processed,
                        $currentKeyEncrypter,
                    ): void {
                        $lockedProfiles = UserProfile::query()
                            ->select(['uuid', ...UserProfile::ENCRYPTED_ATTRIBUTES])
                            ->whereKey($profileUuids)
                            ->orderBy('uuid')
                            ->lockForUpdate()
                            ->get();

                        foreach ($lockedProfiles as $profile) {
                            /** @var UserProfile $profile */
                            $plaintext = [];

                            foreach (UserProfile::ENCRYPTED_ATTRIBUTES as $attribute) {
                                $plaintext[$attribute] = $profile->getAttribute($attribute);
                            }

                            $profile->forceFill($plaintext);
                            $encrypted = array_intersect_key(
                                $profile->getAttributes(),
                                array_flip(UserProfile::ENCRYPTED_ATTRIBUTES),
                            );

                            foreach ($encrypted as $value) {
                                if ($value !== null) {
                                    $currentKeyEncrypter->decryptString((string) $value);
                                }
                            }

                            $profile->getConnection()
                                ->table($profile->getTable())
                                ->where($profile->getKeyName(), $profile->getKey())
                                ->update($encrypted);

                            $processed++;
                        }
                    });
                },
                'uuid',
            );

        $this->components->info(
            "Re-encrypted and verified $processed user profile(s) with the current APP_KEY.",
        );

        return self::SUCCESS;
    }
}
