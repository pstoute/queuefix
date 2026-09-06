# Upgrade a Docker Compose installation

QueueFix never downloads or replaces application code from the web UI. An administrator must review the release notes, create a database backup, and choose a release tag.

## Before upgrading

1. Confirm the release is marked **Immutable** on GitHub, then review its notes and any migration/configuration requirements.
2. Confirm the installation is a clean Git clone using this repository's `docker-compose.yml`.
3. Ensure the current `app` service is running so it can validate the release metadata before any local state changes.
4. Ensure the host has enough disk space for a PostgreSQL dump.
5. Save any uncommitted local changes before updating.

## Upgrade one release

From the QueueFix repository directory, run:

```bash
./deploy/update-docker.sh vX.Y.Z
```

The script refuses a dirty Git working tree and verifies that the requested version is a published immutable release in the canonical QueueFix repository. It fetches only that exact canonical tag, captures the commit it resolves to, and later checks out the captured commit rather than trusting a local or mutable tag name. These checks fail before the database backup or maintenance mode if GitHub, the current app service, the release metadata, or the canonical tag is unavailable.

After verifying the release, the script creates a timestamped PostgreSQL dump in `storage/backups/`, activates Laravel maintenance mode, checks out the captured release commit, rebuilds the Compose services, installs locked dependencies, builds frontend assets, runs migrations, and checks `/up`. The backup directory is restricted to the invoking account, completed dumps use mode `0600`, and an incomplete dump is removed before the updater exits.

It never deletes a completed backup or runs a database restore automatically. Each dump contains application-wide sensitive data. Keep it only as long as your retention policy requires, restrict access to the deployment administrators, and use encryption in transit and at rest for any off-host copy. Keep the pre-upgrade backup until the deployment has been smoke-tested.

## Docker network configuration

Compose uses a fixed private bridge gateway as the only trusted HTTP proxy peer. This lets the host reverse proxy pass each client's address to authentication rate limiters without trusting forwarding headers from arbitrary peers. If `172.30.255.0/28` conflicts with another Docker network on the host, set both values in `.env` before upgrading:

```dotenv
QUEUEFIX_NETWORK_SUBNET=172.30.254.0/28
QUEUEFIX_NETWORK_GATEWAY=172.30.254.1
```

Compose enforces `TRUSTED_PROXY_REQUIRED=true` and derives `TRUSTED_PROXIES` from `QUEUEFIX_NETWORK_GATEWAY`; generic trusted-proxy values in `.env` cannot override that topology. Outside Compose, the application refuses to start when required-proxy mode has an empty trusted-proxy allowlist.

## HTTPS session cookies

For any installation whose `APP_URL` starts with `https://`, set:

```dotenv
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
```

QueueFix promotes a legacy nullable Secure-cookie setting when `APP_URL` uses HTTPS, including when an older cached configuration is still present during an upgrade. It refuses an explicit `SESSION_SECURE_COOKIE=false` mismatch. Direct local HTTP remains supported with the example configuration's explicit `false` value.

The supplied Caddy configuration and HTTPS application responses set `Strict-Transport-Security: max-age=31536000`. The policy deliberately omits `includeSubDomains` and `preload`; enable those only after verifying HTTPS coverage for every affected subdomain. Browsers learn HSTS only after a successful HTTPS response, so review your normal session-revocation procedure if the installation previously issued non-Secure authentication cookies.

## PostgreSQL host access

The default Compose configuration generates a unique PostgreSQL credential in the private `database_credentials` Docker volume and does not publish PostgreSQL to the host. Application services and maintenance commands read that credential from the volume and continue to reach PostgreSQL through the private Compose network.

The first startup after upgrading from a release that used the former fixed development credential rotates the existing `queuefix` role before migrations or application processes start. The transition preserves the database contents, is safe to rerun, and fails closed when the database accepts neither the managed credential nor the one-time legacy migration credential. Keep the `database_credentials` volume with `postgres_data`; deleting only the credential volume from an upgraded installation makes the retained database inaccessible. Rollbacks to a release that predates managed credentials require an explicit database-credential recovery plan.

After updating, use `docker compose up -d --build` rather than `docker compose restart` so Docker runs credential initialization and transition before recreating every dependent service.

Run PostgreSQL tools through the container when possible:

```bash
docker compose exec postgres psql -U queuefix queuefix
```

If a host-side database client is required for local development, create an untracked `docker-compose.override.yml` that binds PostgreSQL only to loopback:

```yaml
services:
  postgres:
    ports:
      - "127.0.0.1:5432:5432"
```

Never publish the database with an unqualified `5432:5432` mapping or a wildcard host address.

## Redis host access

The default Compose configuration does not publish Redis to the host. The app, queue worker, and scheduler continue to reach it through the private Compose network. When upgrading an installation that previously exposed host port 6379, run `docker compose up -d redis app queue scheduler` rather than `docker compose restart`; recreation removes the old published-port configuration.

Run Redis tools through the container when possible:

```bash
docker compose exec redis redis-cli
```

If a host-side Redis client is genuinely required for local development, create an untracked `docker-compose.override.yml` that binds Redis only to loopback:

```yaml
services:
  redis:
    ports:
      - "127.0.0.1:6379:6379"
```

This local override exposes an unauthenticated service to other processes on the Docker host, and the Compose isolation verifier intentionally rejects it. Remove the override before validating or deploying QueueFix. Never publish Redis with an unqualified `6379:6379` mapping or a wildcard host address.

## Mailpit host access

Mailpit's development inbox is available only from the Docker host at `http://127.0.0.1:8025`; its SMTP port is private to the Compose network. The app and queue worker honor `MAIL_MAILER` from `.env`, so production installations can disable Mailpit delivery or select another configured mailer. The standard upgrade workflow recreates the affected services; otherwise run `docker compose up -d app queue mailpit` rather than restarting them.

## Rollback

Application-code rollback is manual and should only be attempted after reviewing migrations. From the same clone:

```bash
git checkout <previous-tag>
docker compose up -d --build
docker compose exec app composer install --no-interaction --prefer-dist --optimize-autoloader
docker compose exec app pnpm install --frozen-lockfile
docker compose exec app pnpm build
```

Releases older than the managed database-credential topology cannot authenticate after the one-time rotation without a deliberate credential recovery. Prefer restoring application code forward; do not restore the former public default.

If the newer release ran migrations, restoring the pre-upgrade PostgreSQL dump may be necessary. Database restores can discard post-upgrade changes, so stop and take a fresh backup first:

```bash
docker compose exec -T postgres sh -c 'psql -U "$POSTGRES_USER" "$POSTGRES_DB"' < storage/backups/<backup>.sql
```

Then run application smoke tests: login, ticket list and reply, mailbox polling, queue worker, scheduler, and the customer portal.

## Version checks

- `GET /version` returns only the installed QueueFix version.
- **Settings → Updates** compares that version with the latest GitHub release. The check is cached for 12 hours and sends only a request for public release metadata; it does not send application, ticket, mailbox, or user data.
