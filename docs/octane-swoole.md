# Laravel Octane and Swoole operations

[Vietnamese translation](octane-swoole.vi.md)

This English document is the canonical operations guide. The Vietnamese file
is a translation. Keep section numbers, commands, limits, and safety
requirements synchronized whenever either document changes.

> Octane changes the PHP execution model: the application is booted once and
> reused for many requests. Worker recycling limits the impact of accidental
> retention, but it does not make request-global state safe. Complete the soak
> test in this guide before enabling Octane in production.

## 1. Scope and operating model

This guide covers Laravel Octane with the Swoole application server for this
PHP 8.5 / Laravel 13 project. It covers installation, local use, production
processes, reverse proxying, deployment, capacity, observability, validation,
and rollback.

Use separate process groups for each responsibility:

| Responsibility | Process |
| --- | --- |
| HTTP and streamed responses | `php artisan octane:start --server=swoole` |
| Queued jobs | one or more `php artisan queue:work` groups |
| Scheduled tasks | cron invoking `php artisan schedule:run` |
| Telegram long polling, when enabled | `php artisan starter-kit-telegram:poll` in its own process |
| TLS and public static files | Nginx, a load balancer, or an ingress controller |

Do not run queue workers, `schedule:work`, or an infinite polling command
inside an Octane request worker. Swoole task workers are not a replacement for
durable Laravel queues.

Telegram, KYC, HRM, and other module examples apply only when those separate
commercial modules are installed. Their queues and doctor commands are not part
of the public core; verify them against the installed module's release guide.

## 2. Prerequisites

### 2.1 Supported runtime

Use all of the following in production:

- a Linux host or Linux container with a 64-bit build;
- PHP CLI 8.5 matching the version used by Composer and Supervisor;
- the latest organization-approved **stable** Swoole release that declares
  PHP 8.5 support; the Swoole 6.2 stable series is the baseline at the time of
  writing;
- Laravel Octane from the committed Composer lock file;
- `pcntl`, `posix`, `sodium`, `mbstring`, `gd`, the selected PDO driver, and
  the other extensions required by `composer.json`;
- a shared database, and preferably Redis for cache, locks, sessions, and
  high-throughput queues in multi-instance deployments;
- Supervisor, systemd, Kubernetes, or another process monitor; and
- Nginx or an equivalent trusted reverse proxy in front of Octane.

Pin the tested Swoole patch version in the image or server build. Do not allow
an untested PECL upgrade during an application deployment. Do not run Xdebug or
another profiling extension in the production worker pool.

### 2.2 Preflight verification

Run these checks with the exact PHP binary that Supervisor will execute:

```bash
php -v
php --ini
php --ri swoole
php -m | sort
composer check-platform-reqs
php artisan about
```

`php --ri swoole` must report a stable version compatible with PHP 8.5. If it
works in PHP-FPM but not in CLI, the extension was enabled in the wrong
`php.ini`; Octane uses CLI PHP.

Confirm that the host clock is synchronized, file descriptor and process
limits are adequate, the deployment user can write `storage/` and
`bootstrap/cache/`, and the proxy can reach the private Octane listen address.

## 3. Installation and initial configuration

### 3.1 Install Swoole

On a build host with the PHP 8.5 development headers, install the approved
stable release. The explicit version below is an example known to support PHP
8.5; replace it only after staging qualification:

```bash
pecl channel-update pecl.php.net
pecl install swoole-6.2.2
php --ri swoole
```

PECL normally creates the extension INI entry. If it does not, add
`extension=swoole` to the PHP 8.5 **CLI** configuration and run the verification
again. Build Swoole into an immutable production image when possible instead
of compiling it on every application host.

### 3.2 Install application dependencies

Octane and its configuration are project dependencies and should be committed
before deployment. A production host installs the lock file; it must not run
`composer require` or regenerate Octane configuration:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan package:discover --ansi
php artisan octane:status
```

For a maintainer bootstrapping Octane in a branch where it is not yet present,
the one-time commands are:

```bash
composer require laravel/octane
php artisan octane:install --server=swoole
```

Review and commit the resulting Composer and `config/octane.php` changes. Do
not run `octane:install` during each deployment because it may overwrite
reviewed configuration.

### 3.3 Configuration ownership

Treat `config/octane.php` and the process monitor command as one reviewed unit:

- configuration provides defaults for the server, bind address, port, worker
  counts, recycling, HTTPS awareness, warm/flush lists, Swoole options, file
  watching, and request execution time;
- explicit process-command options override the matching configuration values;
- a server-level Swoole option or PHP INI change requires a full Octane process
  restart, not only an application-worker reload; and
- production must never use `--watch`.

## 4. Environment baseline

The following is a starting point, not a universal capacity prescription:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://example.com

OCTANE_SERVER=swoole
OCTANE_HTTPS=true
OCTANE_HOST=127.0.0.1
OCTANE_PORT=8000
OCTANE_WORKERS=auto
OCTANE_TASK_WORKERS=1
OCTANE_MAX_REQUESTS=500
OCTANE_MAX_EXECUTION_TIME=30
SWOOLE_PACKAGE_MAX_LENGTH=33554432

CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
SESSION_SECURE_COOKIE=true
TRUSTED_PROXIES=10.0.0.0/8

FILESYSTEM_DISK=s3
PRIVATE_DISK=s3
MEDIA_DISK=s3
```

The `OCTANE_HOST`, `OCTANE_PORT`, `OCTANE_WORKERS`,
`OCTANE_TASK_WORKERS`, and `OCTANE_MAX_REQUESTS` values are read by the
application's Octane configuration when a matching CLI option is omitted.
Explicit CLI flags take precedence. Keep one source of truth in the deployment
template and render both `.env` and the process command from it.

Use `OCTANE_HOST=127.0.0.1` on a bare host. A container may bind `0.0.0.0`, but
the port should remain private to the service network. Never expose the Octane
port directly to the internet.

`TRUSTED_PROXIES` must contain only proxy IP addresses or CIDR ranges controlled
by the deployment. Do not trust every source. Use database-backed cache,
session, and queues when Redis is unavailable; do not use `array`, local file,
or per-pod state for cross-worker coordination.

## 5. Local development

Start with one request worker so behavior is easy to inspect:

```bash
php artisan optimize:clear
php artisan octane:start \
  --server=swoole \
  --host=127.0.0.1 \
  --port=8000 \
  --workers=1 \
  --task-workers=1 \
  --max-requests=100
```

After a source or configuration change, reload the workers:

```bash
php artisan octane:reload
```

`--watch` is a development convenience only and requires Chokidar to be a
direct development dependency. Prefer an explicit reload when investigating
state retention, because it makes worker boundaries visible.

Run Vite and the queue worker in separate terminals:

```bash
bun run dev
php artisan queue:work --sleep=1 --tries=3 --max-jobs=100 --max-time=1800
```

Exercise at least two users and two locales alternately. A page that works for
one user is not sufficient evidence that a singleton has not retained request,
authentication, locale, or tenant state.

## 6. Production capacity and lifecycle

### 6.1 Worker sizing

Start from measurement, not CPU count alone:

```text
request_workers = min(
    available_vCPU,
    floor(memory_budget_for_octane / measured_p95_worker_RSS)
)
```

Reserve memory and CPU for the operating system, proxy, database client
buffers, queue workers, scheduler, and deployment overlap. Each Swoole task
worker is another PHP process with its own memory footprint. If the application
does not use Octane concurrent tasks, keep task workers low rather than using
`auto` blindly.

Recommended initial controls:

| Control | Initial value | Adjustment rule |
| --- | ---: | --- |
| Request workers | `auto` or explicit vCPU count | Lower when DB connections or memory are constrained |
| Task workers | `1` or a measured explicit count | Increase only for measured `Octane::concurrently` demand |
| Maximum requests | `500` | Use `100`-`250` during rollout if KYC/HRM peaks are enabled |
| Maximum request time | `30` seconds | Keep HTTP bounded; move long work to queues |
| PHP `memory_limit` | measured hard ceiling, commonly `512M` | Must cover the largest allowed request without allowing host exhaustion |

Recycling is a safety net. A steadily increasing RSS curve within every
worker generation still requires investigation even if `--max-requests`
prevents an outage.

### 6.2 Supervisor: Octane

Use one Supervisor-managed Octane master. Octane creates its own request and
task worker processes, so `numprocs` must remain `1` unless intentionally
running multiple instances on different ports.

The repository includes a copy-ready
[Supervisor template](../deploy/supervisor/octane.conf.example). Adjust its
directory, user, worker counts, and log destination for the target host.

```ini
[program:douwyn-octane]
process_name=%(program_name)s
directory=/var/www/douwyn/current
command=/usr/bin/php artisan octane:start --server=swoole --host=127.0.0.1 --port=8000 --workers=4 --task-workers=1 --max-requests=500
user=www-data
numprocs=1
autostart=true
autorestart=true
startsecs=5
startretries=3
stopsignal=TERM
stopasgroup=true
killasgroup=true
stopwaitsecs=70
redirect_stderr=true
stdout_logfile=/var/log/supervisor/douwyn-octane.log
stdout_logfile_maxbytes=50MB
stdout_logfile_backups=10
environment=APP_ENV="production"
```

`stopwaitsecs` must exceed the maximum accepted HTTP request duration plus a
drain margin. Align the command with the environment baseline and measured
capacity. Use an absolute PHP path to prevent Supervisor from selecting a
different PHP installation.

After creating or changing the file:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status douwyn-octane
```

### 6.3 Lifecycle commands

```bash
php artisan octane:status
php artisan octane:reload
php artisan octane:stop
```

Use `octane:reload` after compatible application code changes. Use a full
Supervisor restart after changing PHP extensions or INI, bind address, port,
worker counts, Swoole server options, or the process command:

```bash
sudo supervisorctl restart douwyn-octane
```

## 7. Reverse proxy and request limits

### 7.1 Nginx example

Terminate TLS and serve public assets at the proxy. Adapt certificate paths,
domain, release path, and proxy CIDRs to the environment:

```nginx
map $http_upgrade $connection_upgrade {
    default upgrade;
    ''      close;
}

upstream douwyn_octane {
    server 127.0.0.1:8000;
    keepalive 32;
}

server {
    listen 443 ssl;
    server_name example.com;
    root /var/www/douwyn/current/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/example.com/privkey.pem;

    client_max_body_size 24m;
    client_body_timeout 30s;

    location / {
        try_files $uri $uri/ @octane;
    }

    location = /index.php {
        try_files /__octane_front_controller__ @octane;
    }

    location ~* \.php(?:/|$) { return 404; }
    location ~ /\.(?!well-known(?:/|$)) { deny all; }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location @octane {
        proxy_http_version 1.1;
        proxy_set_header Host $http_host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Port $server_port;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $connection_upgrade;
        proxy_read_timeout 40s;
        proxy_send_timeout 40s;
        proxy_buffering off;
        proxy_pass http://douwyn_octane;
    }
}
```

Keep `public/__octane_front_controller__` absent: it is a sentinel used to
forward the front controller to Octane. Other PHP paths and dotfiles are denied
so Nginx cannot serve their source. Check `/`, `/up`, `/index.php`, another PHP
path, and a dotfile through the proxy before deployment.

Redirect HTTP to HTTPS in a separate server block. Set `APP_URL` to the public
HTTPS origin, `OCTANE_HTTPS=true`, `SESSION_SECURE_COOKIE=true`, and explicitly
configure `TRUSTED_PROXIES`. Verify generated URLs, redirects, secure cookies,
Sanctum stateful domains, and client IP rate limits through the real proxy.

### 7.2 Upload and body limits

An upload succeeds only when every layer permits it:

```text
module validation limit
    <= PHP upload_max_filesize
    <  PHP post_max_size
    <= Nginx client_max_body_size
    <  Swoole package_max_length
```

Allow room for multipart boundaries and headers. For an application that
accepts files up to 20 MiB, the following is a coherent example. Verify actual
commercial-module limits against each installed release:

```ini
; php.ini
upload_max_filesize=20M
post_max_size=24M
max_file_uploads=20
```

```dotenv
SWOOLE_PACKAGE_MAX_LENGTH=33554432
```

```nginx
client_max_body_size 24m;
```

Recheck the maximum of every enabled module whenever an upload setting changes.
Do not raise every HTTP worker to a 1 GiB body limit for Licensing artifacts;
use direct-to-object-storage uploads or a separately isolated upload path for
very large files. A larger request-body limit increases concurrent memory and
temporary-disk risk and must be load tested.

## 8. Shared state and Octane-safe application rules

Workers do not share PHP arrays or object instances, while every worker reuses
its own instances across requests. Apply these rules:

- never store `Request`, the authenticated user, session objects, Eloquent
  models, locale, tenant, credentials, or response data in static properties or
  worker-lifetime singletons;
- use container `scoped` bindings for mutable request/job state, or resolve the
  current request at call time;
- do not mutate process globals such as `$_SERVER`, `$_ENV`, locale, timezone,
  error handlers, or library-global parser settings during a request;
- register listeners and extension-registry callbacks once during boot, never
  once per request; do not register closures that capture a request or model;
- close streams, temporary files, HTTP response bodies, locks, and native
  handles in `finally` blocks;
- bound every in-memory cache by key count and lifetime, and invalidate schema
  or configuration caches during deployment;
- paginate or cursor through high-cardinality data; a streamed response does
  not save memory if its source collection was eager loaded first; and
- never rely on worker-local memory for rate limits, idempotency, distributed
  locks, sessions, queue uniqueness, or scheduler overlap prevention.

For more than one worker or host, use a shared, atomic cache backend. Redis is
preferred. Database cache and sessions are valid shared fallbacks but add
database load. Local file sessions and locks are not cross-host. The `array`
cache driver is process-local and must not be used for production coordination.

Public media and private documents must live on storage visible to every
instance. Use S3-compatible storage for multi-host deployments or a genuinely
shared filesystem with equivalent durability and private-object controls.

## 9. Queue and scheduler isolation

### 9.1 Queue workers

Run queue workers under their own process groups and recycle them independently
of Octane. Set `--timeout` shorter than the queue connection's `retry_after`,
but longer than the job's legitimate maximum. Always use `--memory`,
`--max-jobs`, or `--max-time` for a long-lived worker.

The general worker below uses `--timeout=60`, below the Redis connection's
configured default `retry_after=90`. For longer module jobs, configure a
separate connection whose `retry_after` exceeds both job and worker timeouts
with a margin. Raising only `--timeout` can allow the same job to run twice.

Example general worker:

```ini
[program:douwyn-queue-default]
process_name=%(program_name)s_%(process_num)02d
directory=/var/www/douwyn/current
command=/usr/bin/php artisan queue:work redis --queue=default --sleep=1 --tries=3 --timeout=60 --memory=384 --max-jobs=500 --max-time=3600
user=www-data
numprocs=2
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=360
redirect_stderr=true
stdout_logfile=/var/log/supervisor/douwyn-queue-default.log
```

Use dedicated queues for workloads with different memory or timeout profiles:

```dotenv
KYC_QUEUE=kyc
KYC_NOTIFICATION_QUEUE=kyc-notifications
DOMAINS_QUEUE=domains
TELEGRAM_INBOUND_QUEUE=telegram-inbound
TELEGRAM_OUTBOUND_QUEUE=telegram-outbound
TELEGRAM_BROADCAST_QUEUE=telegram-broadcast
TELEGRAM_CAMPAIGN_QUEUE=telegram-campaigns
```

Suggested isolation:

- KYC inspection/export: low concurrency, higher memory ceiling, low
  `--max-jobs` such as `25`-`50`, and timeout above the permitted export duration;
- Domains sync: timeout above the installed module's operation limit and
  `stopwaitsecs` long enough to drain it;
- Telegram inbound: latency-oriented workers separate from outbound and
  broadcast rate-limited workers; and
- large reporting or payroll work: a dedicated queue once those operations are
  asynchronous.

Restart queue workers after every deployment:

```bash
php artisan queue:restart
```

### 9.2 Scheduler

Prefer a stateless cron entry:

```cron
* * * * * cd /var/www/douwyn/current && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

Run it on one designated scheduler host, or keep `onOneServer` tasks on a shared
atomic cache. If `schedule:work` is used instead, supervise it as a separate
process and restart it on deployment. Never execute it inside Octane.

Monitor scheduled task duration and overlap locks. Before clearing a scheduler
lock, prove that no prior task is still running; blindly clearing locks can
start duplicate financial, notification, or reconciliation work.

## 10. Deployment, reload, and rollback

### 10.1 Deployment sequence

Use atomic release directories and backwards-compatible migrations. A typical
sequence is:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
bun install --frozen-lockfile
bun run build
php artisan migrate --force
php artisan optimize
php artisan about
```

Then switch the `current` symlink atomically and reload all long-lived
processes:

```bash
php artisan octane:reload
php artisan queue:restart
```

Laravel 13 also provides `php artisan reload` for reloadable services. Whether
using the aggregate command or explicit commands, verify that Supervisor starts
replacement processes and that `/up` succeeds through the proxy.

Use a full Supervisor restart instead of `octane:reload` when the release
changes the PHP binary or extensions, PHP INI, environment values used by the
master, listen address or port, worker counts, or Swoole server options:

```bash
sudo supervisorctl restart douwyn-octane
sudo supervisorctl restart douwyn-queue-default:*
```

Do not delete the previous release while old workers may still reference it.
Do not combine a breaking schema migration with a rolling worker reload.

### 10.2 Rollback

Keep at least one verified prior release and its dependency tree. To roll back:

1. stop traffic or enter maintenance mode if old and new schemas are not
   mutually compatible;
2. atomically point `current` to the prior release;
3. restore the prior environment and cached configuration;
4. run `php artisan optimize:clear` in the selected release, then rebuild only
   its known-good caches;
5. fully restart Octane, queue workers, and any `schedule:work` or Telegram poll
   processes;
6. verify `/up`, login/session behavior, a read-only database request, cache and
   lock operations, queue processing, and enabled module doctor commands; and
7. reopen traffic gradually while monitoring errors, latency, RSS, and queue
   lag.

Do not automatically reverse migrations during an incident. Restore or migrate
the database only from a reviewed, tested rollback plan; data written by the
new release may not be understood by the old release.

## 11. Health checks and observability

The built-in `/up` route is configured in `bootstrap/app.php`. It is a liveness
and framework-boot check, not proof that the database, cache, queue, storage, or
external providers are healthy.

Use three layers:

1. **Liveness:** request `/up` through the proxy; keep it cheap and unauthenticated.
2. **Readiness/synthetic checks:** exercise a safe database read, shared cache,
   distributed lock, storage metadata operation, and a representative
   authenticated API flow from a private monitor.
3. **Deep operational checks:** run enabled module doctor commands from a
   protected deployment or monitoring job, for example
   `starter-kit-blog:doctor`, `starter-kit-domains:doctor`,
   `starter-kit-hrm:doctor`, `starter-kit-infrastructure:doctor`,
   `starter-kit-kyc:doctor`, `starter-kit-ledger:doctor`, and
   `starter-kit-licensing:doctor`.

Monitor and retain at least:

- request rate, concurrency, p50/p95/p99 latency, 4xx/5xx, timeouts, client
  aborts, and response sizes by route family;
- master and per-worker PID, RSS, CPU, file descriptors, restart reason,
  generation age, and requests served;
- database connection count, slow queries, transaction duration, deadlocks,
  and pool saturation;
- cache latency, hit rate, errors, evictions, and lock acquisition failures;
- queue depth, oldest-job age, run time, retries, failures, and worker recycling;
- upload rejection reason and size without logging confidential content;
- Swoole and Supervisor logs, PHP fatal errors, and OOM or container kills; and
- deploy version, PHP version, Swoole version, and configuration fingerprint.

Alert on RSS slope as well as absolute RSS. A worker that repeatedly grows from
150 MiB to 450 MiB before each planned recycle has a retention or workload
problem even when the host remains available. Correlate growth with route,
status, payload size, user/tenant cardinality, and worker PID. Never place
tokens, credentials, KYC/HRM plaintext, or request bodies in telemetry.

## 12. Soak and load-test checklist

Run this checklist in a production-like staging environment using the same PHP,
Swoole, proxy, cache, database engine, storage driver, worker counts, and body
limits as production.

### 12.1 Functional isolation

- alternate requests between at least two users, tenants/owners, locales, and
  permission sets on the same keep-alive connection;
- verify there is no cross-request authentication, authorization, locale,
  session, validation, header, or response-data bleed;
- exercise successful, validation-failure, authorization-failure, exception,
  redirect, streamed-response, and client-disconnect paths; and
- verify locks, transactions, streams, and temporary files are released after
  every path.

### 12.2 Memory and capacity

- record each worker PID and baseline RSS after warm-up;
- run at least 1,000 requests per critical route, then a mixed test long enough
  to cross `OCTANE_MAX_REQUESTS` several times;
- test concurrency `1`, expected steady state, and a controlled burst;
- sample per-worker RSS, CPU, file descriptors, database connections, and
  latency throughout each worker generation;
- confirm replacement workers become ready before old workers exit; and
- define a pass threshold for RSS plateau, error rate, p95/p99 latency, queue
  lag, and recovery time before the test begins.

### 12.3 Failure and deployment behavior

- interrupt Redis, database, object storage, DNS, and one external provider in
  controlled tests;
- abort large uploads and streamed downloads midway;
- force queue job timeout, retry, and worker recycle paths;
- reload during sustained traffic and verify no avoidable 502 spike;
- perform one forward deployment and one rollback; and
- verify the proxy never routes traffic to an unready replacement worker.

Keep raw time series by PID. A single before/after process-memory value cannot
distinguish a leak from a legitimate high-water allocation.

## 13. Module-specific capacity caveats

### 13.1 Hotel Booking

If an installed booking module calculates availability by stay day, room type,
or room line, work can grow with the product of those values. Verify that the
service bounds date ranges and booking horizons before exposing public
availability; a small successful request does not establish a safe upper bound.

Before exposing it through Octane:

- enforce a conservative range at the API gateway/WAF or keep the public route
  disabled until the service itself rejects oversized ranges;
- rate-limit and concurrency-limit by trusted client identity and IP;
- soak test the maximum permitted range times the maximum active room types and
  room lines; and
- monitor inventory-row growth, query count, transaction time, and worker RSS.

Rate limiting alone is not a work-per-request limit. A 30-second execution
timeout limits damage but does not undo rows already created by an interrupted
request.

### 13.2 KYC

Inspect the installed KYC module for inspection, export, or authorized-download
paths that buffer decrypted plaintext in memory. Configurable limits can make a
single operation materially larger than the normal worker baseline, and image
inspection may temporarily hold more than one plaintext representation.

Operational controls:

- keep `KYC_MAX_UPLOAD_SIZE_KB` and export byte/row limits at the smallest
  business-acceptable values;
- place inspection/export on dedicated `KYC_QUEUE` workers with low concurrency,
  a measured memory ceiling, and frequent recycling;
- route high-memory KYC HTTP endpoints to an isolated Octane pool if normal API
  workers cannot absorb their peak safely;
- start with `--max-requests=100`-`250` for that pool and validate RSS recovery;
- align proxy, PHP, and Swoole body limits without exposing an unnecessarily
  large global limit; and
- never capture decrypted bytes, filenames containing PII, access reasons, or
  document contents in logs or traces.

Worker recycling reduces allocator high-water persistence but is not a
substitute for incremental streaming.

### 13.3 HRM

Check whether the installed HRM release builds payroll data synchronously or
preloads the complete item graph before export. If it does, large organizations
can hold a worker and database transaction much longer than normal requests.
Confirm upload limits and streaming behavior against that module version.

Until large payroll operations are chunked or queued:

- restrict payroll calculation/export concurrency and run them off peak;
- use a dedicated internal Octane pool when organization cardinality is large;
- test the largest organization, component count, and export row count, not
  only an average payroll;
- alert on transaction duration, row locks, query count, response time, and RSS;
- avoid increasing the global request timeout to accommodate payroll; and
- size the upload path for the configured document limit plus multipart overhead.

## 14. Troubleshooting

| Symptom | Checks and response |
| --- | --- |
| `octane:start` is missing | Run `composer install`, verify `laravel/octane` in the lock file, then run package discovery. |
| Swoole is not found | Compare `which php`, `php --ini`, and Supervisor's absolute PHP path; enable the extension for CLI PHP 8.5. |
| Source or routes appear stale | Run `php artisan octane:reload`; for server/INI/env changes, fully restart Supervisor. |
| Immediate 502 or connection refused | Check `octane:status`, Supervisor and Swoole logs, bind address/port, firewall, and whether another process owns the port. |
| Intermittent 419, wrong scheme, or insecure cookies | Verify `APP_URL`, `OCTANE_HTTPS`, secure-cookie settings, explicit trusted proxies, forwarded headers, and Sanctum domains. |
| HTTP 413 or missing upload | Align module, PHP, Nginx, and `package_max_length` limits; allow multipart overhead and reload the master. |
| Request ends at 30 seconds | Keep HTTP bounded; move work to a queue. Change max execution time only with an explicit SLO and full restart. |
| RSS grows across requests | Identify PID and route, reproduce with concurrency 1, inspect singleton/static/global state and eager collections, lower max requests temporarily, then fix the retaining code. |
| Database connections are exhausted | Reduce total HTTP/task/queue workers, inspect slow transactions, and budget connections across rolling deployments. |
| Sessions, rate limits, or locks disagree | Remove process-local/file drivers and verify every instance uses the same cache/session backend and prefix. |
| Queue job runs twice or times out | Ensure worker timeout is below `retry_after`, operation idempotency is intact, and dedicated long-job workers have sufficient drain time. |
| Streamed download truncates | Compare application, proxy, load-balancer, and client idle timeouts; inspect client-abort and storage errors. |
| Reload causes a traffic spike | Verify graceful `TERM`, readiness gating, spare CPU/memory for overlapping generations, and proxy retry policy. |

When memory behavior is unclear, do not repeatedly raise memory limits. Reduce
concurrency, shorten worker lifetime, isolate the suspected route or queue, and
capture per-PID evidence first.

## 15. Production go-live checklist

- [ ] PHP CLI is 8.5 and the pinned stable Swoole build is verified.
- [ ] Composer platform requirements and the complete automated test suite pass.
- [ ] Octane is private behind a trusted TLS proxy; `/up` works through it.
- [ ] Worker, task-worker, max-request, execution-time, and memory budgets are measured.
- [ ] Cache, sessions, locks, rate limits, queues, and scheduler overlap use shared stores.
- [ ] Public and private storage are accessible from every instance.
- [ ] Nginx, Swoole, PHP, and module upload limits are coherent.
- [ ] Queue families and long polling run outside Octane and recycle safely.
- [ ] Supervisor gracefully drains and automatically replaces every process.
- [ ] Deploy reload and rollback have both been rehearsed under traffic.
- [ ] Soak tests cross several worker generations without state bleed or unbounded RSS.
- [ ] Installed Hotel, KYC, or HRM modules, if any, meet explicit worst-case capacity limits.
- [ ] Dashboards and alerts include worker PID/RSS, latency, errors, DB, cache, and queue lag.
- [ ] Logs and traces have been checked for credentials and confidential module data.

## 16. References

- [Laravel 13 Octane](https://laravel.com/docs/13.x/octane)
- [Laravel 13 deployment](https://laravel.com/docs/13.x/deployment)
- [Laravel 13 queues](https://laravel.com/docs/13.x/queues)
- [Laravel 13 task scheduling](https://laravel.com/docs/13.x/scheduling)
- [Swoole PECL package and stable releases](https://pecl.php.net/package/swoole)
