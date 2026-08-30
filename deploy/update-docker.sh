#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 1 || ! "$1" =~ ^v[0-9]+\.[0-9]+\.[0-9]+([-.][0-9A-Za-z.-]+)?$ ]]; then
  echo "Usage: $0 vX.Y.Z" >&2
  exit 64
fi

target_tag="$1"
project_dir="$(git rev-parse --show-toplevel)"
cd "$project_dir"

if [[ -n "$(git status --porcelain)" ]]; then
  echo "Refusing to update a working tree with local changes." >&2
  exit 1
fi

if ! command -v docker >/dev/null; then
  echo "Docker is required." >&2
  exit 1
fi

if ! docker compose config --quiet; then
  echo "The Docker Compose configuration is invalid." >&2
  exit 1
fi

git fetch --tags origin
if ! git rev-parse --verify --quiet "refs/tags/${target_tag}" >/dev/null; then
  echo "Tag ${target_tag} was not found on origin." >&2
  exit 1
fi

backup_dir="storage/backups"
mkdir -p "$backup_dir"
backup_file="${backup_dir}/queuefix-pre-${target_tag}-$(date +%Y%m%d%H%M%S).sql"

echo "Creating PostgreSQL backup at ${backup_file}"
docker compose exec -T postgres sh -c 'pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB"' > "$backup_file"

docker compose exec -T app php artisan down --retry=60 || true
restore_maintenance_mode() {
  docker compose exec -T app php artisan up >/dev/null 2>&1 || true
}
trap restore_maintenance_mode EXIT

git checkout --detach "$target_tag"
docker compose up -d --build --remove-orphans
docker compose exec -T app composer install --no-interaction --prefer-dist --optimize-autoloader
docker compose exec -T app pnpm install --frozen-lockfile
docker compose exec -T app pnpm build
docker compose exec -T app php artisan migrate --force
docker compose exec -T app php artisan optimize

docker compose exec -T app php artisan up
trap - EXIT

curl --fail --silent --show-error http://localhost:8000/up >/dev/null
echo "Updated to ${target_tag}. Backup retained at ${backup_file}."
