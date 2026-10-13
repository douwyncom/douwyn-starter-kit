# Module activity logs

Administrator-managed module models use `Douwyn\StarterKit\Audit\HasModuleActivityLogs`.
They write created, updated, deleted and (for soft deletes) restored events to the
host Activity Log, under `module.<namespace>` with subject and actor UUIDs.
The Activity Log can be filtered by log name and event.

Updated values and previous values are recorded only when something changes.
Routine timestamps and Telegram polling cursors are excluded. Delivery attempts,
inbound payloads, metrics, outboxes and audit-event models do not use this trait.
Credentials, encrypted/hidden fields, nested secret keys and configuration values
are redacted before insertion, including credential-only changes. Core model
logs use the same sanitizer; redaction is applied at storage time, not just in UI.

Coverage includes Telegram, Discord, WhatsApp, Zalo OA, Domains, Licensing, AI,
Library, Telegram Library, Commerce, Hotel Booking, Infrastructure, Blog, HRM,
Backup, Alerts and Commerce Licensing configuration and editable records.

Telegram menu item deletion, bulk deletion, reordering, default-menu changes and
version parent assignment use Eloquent model mutations so audit events execute.
Successful remote Telegram command updates explicitly emit `commands_updated`;
failed remote calls do not emit a success event. Blog link deletion likewise uses
model events.

For new operations, query-builder update/delete/upsert and database cascades do
not emit Eloquent events. Use individual model mutations inside the operation's
existing transaction, or explicitly log the successful operation. Do not add the
trait to high-volume queue or telemetry tables. Audit writes use the configured
host activity-log connection; atomic rollback requires the same database
connection as the module mutation.

Deploy the host `src/StarterKit/Audit` support together with these module changes.
No data migration is needed when the host activity-log migrations already exist.
Missing historical events cannot be reconstructed from this change.

Isolated module tests use `Tests\Support\ModuleActivityLogSchema` to provision
the host audit table when they do not run the full host migrations.
