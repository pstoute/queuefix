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
original_umask="$(umask)"
umask 077

if [[ -L "$backup_dir" || ( -e "$backup_dir" && ! -d "$backup_dir" ) ]]; then
  echo "Refusing to use a backup path that is not a real directory: ${backup_dir}" >&2
  exit 1
fi

mkdir -p "$backup_dir"

if [[ ! -O "$backup_dir" ]]; then
  echo "Refusing to write backups to a directory owned by another account: ${backup_dir}" >&2
  exit 1
fi

chmod 0700 "$backup_dir"

backup_timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
backup_tmp="$(mktemp "${backup_dir}/.queuefix-pre-${target_tag}-${backup_timestamp}.sql.tmp.XXXXXX")"
backup_nonce="${backup_tmp##*.tmp.}"
backup_file="${backup_dir}/queuefix-pre-${target_tag}-${backup_timestamp}-${backup_nonce}.sql"

cleanup_incomplete_backup() {
  if [[ -n "${backup_tmp:-}" && -e "$backup_tmp" ]]; then
    rm -f -- "$backup_tmp"
  fi
}

handle_backup_signal() {
  local status="$1"
  trap - EXIT HUP INT TERM
  cleanup_incomplete_backup
  exit "$status"
}

trap cleanup_incomplete_backup EXIT
trap 'handle_backup_signal 129' HUP
trap 'handle_backup_signal 130' INT
trap 'handle_backup_signal 143' TERM

echo "Creating a private PostgreSQL backup"
docker compose exec -T postgres sh -c 'pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB"' > "$backup_tmp"

if [[ ! -s "$backup_tmp" ]]; then
  echo "PostgreSQL returned an empty backup; aborting the update." >&2
  exit 1
fi

chmod 0600 "$backup_tmp"

if [[ -e "$backup_file" || -L "$backup_file" ]]; then
  echo "Refusing to replace an existing backup: ${backup_file}" >&2
  exit 1
fi

mv -- "$backup_tmp" "$backup_file"
backup_tmp=""
trap - EXIT HUP INT TERM
umask "$original_umask"
echo "Created PostgreSQL backup at ${backup_file}"

docker compose exec -T app php artisan down --retry=60 || true
restore_maintenance_mode() {
  docker compose exec -T app php artisan up >/dev/null 2>&1 || true
}
trap restore_maintenance_mode EXIT

git checkout --detach "$target_tag"
docker compose config --quiet
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
