# Upgrade a Docker Compose installation

QueueFix never downloads or replaces application code from the web UI. An administrator must review the release notes, create a database backup, and choose a release tag.

## Before upgrading

1. Confirm the release notes and any migration/configuration requirements.
2. Confirm the installation is a clean Git clone using this repository's `docker-compose.yml`.
3. Ensure the host has enough disk space for a PostgreSQL dump.
4. Save any uncommitted local changes before updating.

## Upgrade one release

From the QueueFix repository directory, run:

```bash
./deploy/update-docker.sh vX.Y.Z
```

The script refuses a dirty Git working tree, verifies that the specified tag exists on `origin`, creates a timestamped PostgreSQL dump in `storage/backups/`, activates Laravel maintenance mode, checks out the exact tag, rebuilds the Compose services, installs locked dependencies, builds frontend assets, runs migrations, and checks `/up`.

It never deletes a backup or runs a database restore automatically. Keep the backup until the deployment has been smoke-tested.

## PostgreSQL host access

The default Compose configuration does not publish PostgreSQL to the host. Application services and maintenance commands continue to reach it through the private Compose network. After updating, use `docker compose up -d postgres` rather than `docker compose restart postgres` so Docker recreates the service with the new network configuration.

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

## Rollback

Application-code rollback is manual and should only be attempted after reviewing migrations. From the same clone:

```bash
git checkout <previous-tag>
docker compose up -d --build
docker compose exec app composer install --no-interaction --prefer-dist --optimize-autoloader
docker compose exec app pnpm install --frozen-lockfile
docker compose exec app pnpm build
```

If the newer release ran migrations, restoring the pre-upgrade PostgreSQL dump may be necessary. Database restores can discard post-upgrade changes, so stop and take a fresh backup first:

```bash
docker compose exec -T postgres sh -c 'psql -U "$POSTGRES_USER" "$POSTGRES_DB"' < storage/backups/<backup>.sql
```

Then run application smoke tests: login, ticket list and reply, mailbox polling, queue worker, scheduler, and the customer portal.

## Version checks

- `GET /version` returns only the installed QueueFix version.
- **Settings → Updates** compares that version with the latest GitHub release. The check is cached for 12 hours and sends only a request for public release metadata; it does not send application, ticket, mailbox, or user data.
