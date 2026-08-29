<?php

namespace App\Services\Auth;

use App\Models\LoginSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class LoginSessionIdentifier
{
    private const string PREFIX = 'douwyn_ls_';

    public function encode(string $rawSessionId): string
    {
        return self::PREFIX.$this->digest($rawSessionId);
    }

    public function digest(string $rawSessionId): string
    {
        return hash_hmac(
            'sha256',
            "browser-login-session\0$rawSessionId",
            (string) config('app.key'),
        );
    }

    public function forSession(LoginSession $session): string
    {
        $digest = (string) $session->public_id_hash;

        if (! preg_match('/^[a-f0-9]{64}$/D', $digest)) {
            $digest = $this->digest((string) $session->getKey());
            $this->persistLegacyDigest($session, $digest);
        }

        return self::PREFIX.$digest;
    }

    public function matches(string $identifier, string $rawSessionId): bool
    {
        return hash_equals($this->encode($rawSessionId), $identifier);
    }

    public function resolveForUser(User $user, string $identifier): ?LoginSession
    {
        $digest = $this->identifierDigest($identifier);

        if ($digest === null) {
            return null;
        }

        /** @var Builder<LoginSession> $query */
        $query = $user->loginSessions()->getQuery();
        $query->notRevoked();

        return $this->resolveDigest($query, $digest);
    }

    public function resolve(string $identifier): ?LoginSession
    {
        $digest = $this->identifierDigest($identifier);

        if ($digest === null) {
            return null;
        }

        return $this->resolveDigest(LoginSession::query(), $digest);
    }

    /**
     * @param  array<int, string>  $identifiers
     * @return array<int, string>
     */
    public function rawIds(array $identifiers): array
    {
        $digests = array_values(array_filter(array_map(
            fn (string $identifier): ?string => $this->identifierDigest($identifier),
            $identifiers,
        )));

        if ($digests === []) {
            return [];
        }

        $sessions = LoginSession::query()
            ->whereIn('public_id_hash', $digests)
            ->get(['id', 'public_id_hash']);

        $resolvedDigests = $sessions
            ->pluck('public_id_hash')
            ->filter()
            ->map(fn ($digest): string => (string) $digest)
            ->all();

        foreach (array_diff($digests, $resolvedDigests) as $digest) {
            if ($legacy = $this->resolveDigest(LoginSession::query(), $digest)) {
                $sessions->push($legacy);
            }
        }

        return $sessions
            ->unique(fn (LoginSession $session): string => (string) $session->getKey())
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * @param  Builder<LoginSession>  $query
     */
    private function resolveDigest(Builder $query, string $digest): ?LoginSession
    {
        $session = (clone $query)
            ->where('public_id_hash', $digest)
            ->first();

        if ($session) {
            return $session;
        }

        // Transitional fallback for an identifier issued before the public
        // hash backfill completed. The migration makes this path unnecessary
        // after deployment, but keeping it avoids breaking an in-flight UI.
        /** @var LoginSession|null $legacy */
        $legacy = (clone $query)
            ->whereNull('public_id_hash')
            ->orderBy('id')
            ->lazy(200)
            ->first(fn (LoginSession $candidate): bool => hash_equals(
                $digest,
                $this->digest((string) $candidate->getKey()),
            ));

        if (! $legacy) {
            return null;
        }

        $this->persistLegacyDigest($legacy, $digest);

        return $legacy;
    }

    private function persistLegacyDigest(LoginSession $session, string $digest): void
    {
        LoginSession::query()
            ->whereKey($session->getKey())
            ->whereNull('public_id_hash')
            ->update(['public_id_hash' => $digest]);

        $session->setAttribute('public_id_hash', $digest);
    }

    private function identifierDigest(string $identifier): ?string
    {
        if (! preg_match('/^'.self::PREFIX.'([a-f0-9]{64})$/D', $identifier, $matches)) {
            return null;
        }

        return $matches[1];
    }
}
