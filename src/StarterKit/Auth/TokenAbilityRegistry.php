<?php

declare(strict_types=1);

namespace Douwyn\StarterKit\Auth;

use InvalidArgumentException;

final class TokenAbilityRegistry
{
    /** @var array<string, list<string>> */
    private array $profiles = [];

    /** @param array<string, iterable<string>> $profiles */
    public function __construct(array $profiles = [])
    {
        foreach ($profiles as $profile => $abilities) {
            $this->define($profile, $abilities);
        }
    }

    /** @param iterable<string> $abilities */
    public function define(TokenAbilityProfile|string $profile, iterable $abilities): self
    {
        $this->profiles[$this->profileName($profile)] = $this->normalize($abilities);

        return $this;
    }

    /** @param iterable<string> $abilities */
    public function extend(TokenAbilityProfile|string $profile, iterable $abilities): self
    {
        $name = $this->profileName($profile);
        $this->profiles[$name] = $this->normalize([
            ...($this->profiles[$name] ?? []),
            ...$abilities,
        ]);

        return $this;
    }

    /** @return list<string> */
    public function abilitiesFor(TokenAbilityProfile|string $profile): array
    {
        return $this->profiles[$this->profileName($profile)] ?? [];
    }

    /** @return array<string, list<string>> */
    public function profiles(): array
    {
        return $this->profiles;
    }

    private function profileName(TokenAbilityProfile|string $profile): string
    {
        $name = trim($profile instanceof TokenAbilityProfile ? $profile->value : $profile);

        if ($name === '' || ! preg_match('/^[a-z][a-z0-9_-]*$/', $name)) {
            throw new InvalidArgumentException(sprintf('Token ability profile [%s] is invalid.', $name));
        }

        return $name;
    }

    /**
     * @param  iterable<mixed>  $abilities
     * @return list<string>
     */
    private function normalize(iterable $abilities): array
    {
        $normalized = [];

        foreach ($abilities as $ability) {
            if (! is_string($ability) || ($ability = trim($ability)) === '') {
                continue;
            }

            $normalized[$ability] = true;
        }

        return array_keys($normalized);
    }
}
