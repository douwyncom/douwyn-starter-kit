# Security policy

## Supported versions

Security fixes are made on the current `main` branch and, when practical, the
latest tagged stable release. Older releases may require upgrading before a
fix can be applied.

## Report a vulnerability

Please report vulnerabilities privately by emailing
**[contact@douwyn.com](mailto:contact@douwyn.com)**. You can also contact
Douwyn through **[https://douwyn.com](https://douwyn.com)**.

Include:

- the affected version or commit;
- a clear description of the impact;
- reproduction steps or a minimal proof of concept; and
- any known mitigations.

Do not open a public issue for an unpatched vulnerability and do not include
real credentials, tokens, customer data, or destructive payloads. We will
coordinate disclosure after a fix or mitigation is available. Community
reports do not carry a guaranteed response SLA.

Commercial modules are covered by the security and support terms supplied
with the purchased module. Include the module name and licensed version when
reporting an issue in commercial code.

## Personal data and encryption keys

Identity, contact, address, biography, preference, and metadata fields in
`user_profiles` are encrypted at rest with Laravel's application encryption.
Relational keys and timestamps remain plaintext. This control limits exposure
from a database-only disclosure; it does not replace authorization, TLS,
access control, or encrypted backups.

Treat `APP_KEY` and every value in `APP_PREVIOUS_KEYS` as production secrets.
Never commit them, log them, or run `php artisan key:generate --force` against
an environment containing protected data. Losing all applicable keys makes the
encrypted values unrecoverable. Follow the documented
[profile-data migration and key-rotation procedure](docs/profile-data-encryption.md).
