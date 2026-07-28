# User profile data encryption

Personal data in `user_profiles` is protected with Laravel's authenticated
application encryption before it is written to the database. Application code,
authorized API resources, and Filament forms continue to work with plaintext
values after Eloquent decrypts them.

## Protected fields

The following fields are encrypted at rest:

- `first_name`, `last_name`, `birthdate`, and `gender`;
- `phone`, `country`, `city`, `address_line1`, `address_line2`, and
  `postal_code`;
- `avatar_url`, `bio`, `website`, `locale`, `timezone`, and `metadata`.

Only `uuid`, `user_uuid`, and timestamps remain plaintext because they are
technical relational keys and lifecycle fields. Database encryption does not
replace API authorization, Filament permissions, TLS, storage permissions, or
encrypted backups.

The encrypted profile attributes are hidden from generic model serialization.
Expose only an explicitly reviewed subset through an authorized API resource,
as `App\Http\Resources\Api\UserResource` does.

## Deploying the migration

The migration adds shadow text columns, encrypts and verifies existing values
in chunks, then replaces the original plaintext columns. It is intentionally
one-way: its `down()` method will not write personal data back to plaintext.

Before deploying:

1. Back up the database and store the backup separately from the application
   encryption key.
2. Confirm that the production `APP_KEY` is present on every web, CLI, queue,
   and scheduler process.
3. Put the application into maintenance/read-only mode, pause queue workers and
   schedulers that can update profiles, and stop external writers. Old code
   cannot read the encrypted schema, while new code cannot read legacy
   plaintext, so this release must not use a rolling deployment.
4. Run `php artisan migrate --force`. The migration is resumable by column
   after interrupted non-transactional DDL, but restoring the reviewed backup
   remains the recovery path for any unexplained failure.
5. Deploy the matching application code, restart long-running workers, and
   exercise profile reads and writes before leaving maintenance mode.

Do not run `php artisan key:generate --force` on an existing environment.
Losing the active key and all previous keys makes the protected data
unrecoverable. A code rollback must retain code that understands the encrypted
columns; a database rollback requires restoring a reviewed encrypted backup,
because the migration deliberately refuses to recreate plaintext columns. Old
database backups, replicas, transaction logs, and WAL/binlog archives may still
contain pre-migration plaintext; apply an appropriate retention and
secure-deletion policy to them.

## Key rotation

Laravel can decrypt data with old keys listed in `APP_PREVIOUS_KEYS`, while new
writes use `APP_KEY`. Use comma-separated keys without spaces.

1. Generate a new key outside the live environment.
2. Set the new key as `APP_KEY` and put the old key in
   `APP_PREVIOUS_KEYS` on every application node.
3. Deploy and restart long-running queue, scheduler, and PHP workers.
4. Re-encrypt and verify all protected profiles:

   ```bash
   php artisan app:user-profiles-reencrypt --force
   ```

   Use `--chunk=200` to tune the transaction size.
5. Verify profile reads, writes, backups, and all application nodes.
6. Remove the retired key only after every protected row and process has been
   verified with the current key.

Keep multiple retired keys in `APP_PREVIOUS_KEYS` during a staged rotation.
The re-encryption command validates each new ciphertext with the current key
before committing its chunk.

## Query limitations

Laravel encryption uses a random initialization vector. Equal plaintext values
therefore produce different ciphertext, which prevents database `LIKE`
searches, sorting, grouping, uniqueness checks, and equality filters on these
columns. The Filament user and activity-log tables deliberately do not search
or sort by encrypted names.

If a future feature requires exact lookup, add a purpose-specific blind index
using an independently managed HMAC key and document the data-leakage
trade-off. Do not use an unsalted or plain SHA-256 digest of low-entropy profile
values. Fuzzy name search should use a separately designed privacy-preserving
index rather than decrypting every row in a request.

## Operational checks

Run the dedicated regression coverage after changing the model, migration, API
resource, or key-management workflow:

```bash
php artisan test tests/Feature/UserProfilePiiEncryptionTest.php
```

The suite verifies ciphertext at rest, nullable values, randomized encryption,
legacy-data backfill, API output, typed casts, tamper detection, and
re-encryption.
