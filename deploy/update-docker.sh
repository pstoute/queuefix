#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 1 || ! "$1" =~ ^v[0-9]+\.[0-9]+\.[0-9]+([-.][0-9A-Za-z.-]+)?$ ]]; then
  echo "Usage: $0 vX.Y.Z" >&2
  exit 64
fi

target_tag="$1"
canonical_repository_url="https://github.com/pstoute/queuefix.git"
release_api_url="https://api.github.com/repos/pstoute/queuefix/releases/tags/${target_tag}"
tag_reference_api_url="https://api.github.com/repos/pstoute/queuefix/git/ref/tags/${target_tag}"
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

release_metadata_validator='
$maximumBytes = 1048576;
$payload = stream_get_contents(STDIN, $maximumBytes + 1);
if ($payload === false || strlen($payload) > $maximumBytes) {
    exit(1);
}
try {
    $release = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
} catch (Throwable) {
    exit(1);
}
$publishedAt = is_array($release) ? ($release["published_at"] ?? null) : null;
$valid = is_array($release)
    && ($release["tag_name"] ?? null) === $argv[1]
    && ($release["immutable"] ?? null) === true
    && ($release["draft"] ?? null) === false
    && is_string($publishedAt)
    && $publishedAt !== "";
exit($valid ? 0 : 1);'

if ! curl \
  --disable \
  --fail \
  --silent \
  --show-error \
  --proto '=https' \
  --tlsv1.2 \
  --connect-timeout 10 \
  --max-time 30 \
  --max-filesize 1048576 \
  --header 'Accept: application/vnd.github+json' \
  --header 'X-GitHub-Api-Version: 2026-03-10' \
  "$release_api_url" \
  | docker compose exec -T app php -r "$release_metadata_validator" "$target_tag"; then
  echo "Release ${target_tag} could not be verified as a published immutable QueueFix release." >&2
  echo "Ensure the current app service is running and retry after reviewing the release on GitHub." >&2
  exit 1
fi

tag_reference_validator='
$maximumBytes = 1048576;
$payload = stream_get_contents(STDIN, $maximumBytes + 1);
if ($payload === false || strlen($payload) > $maximumBytes) {
    exit(1);
}
try {
    $reference = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
} catch (Throwable) {
    exit(1);
}
$object = is_array($reference) ? ($reference["object"] ?? null) : null;
$sha = is_array($object) ? ($object["sha"] ?? null) : null;
$type = is_array($object) ? ($object["type"] ?? null) : null;
$valid = is_array($reference)
    && ($reference["ref"] ?? null) === "refs/tags/".$argv[1]
    && is_string($sha)
    && preg_match("/\\A[0-9a-f]{40}\\z/D", $sha) === 1
    && ($type === "commit" || $type === "tag");
if (! $valid) {
    exit(1);
}
fwrite(STDOUT, $sha);'

if ! expected_tag_object="$(
  curl \
    --disable \
    --fail \
    --silent \
    --show-error \
    --proto '=https' \
    --tlsv1.2 \
    --connect-timeout 10 \
    --max-time 30 \
    --max-filesize 1048576 \
    --header 'Accept: application/vnd.github+json' \
    --header 'X-GitHub-Api-Version: 2026-03-10' \
    "$tag_reference_api_url" \
    | docker compose exec -T app php -r "$tag_reference_validator" "$target_tag"
)"; then
  echo "The canonical tag reference for ${target_tag} could not be verified." >&2
  exit 1
fi

update_ref_nonce="$(openssl rand -hex 16)"
fetched_tag_ref="refs/queuefix-update/${target_tag}-${update_ref_nonce}"
cleanup_fetched_tag_ref() {
  git update-ref -d "$fetched_tag_ref" >/dev/null 2>&1 || true
}
trap cleanup_fetched_tag_ref EXIT

if ! git fetch --no-tags --refmap= "$canonical_repository_url" "+refs/tags/${target_tag}:${fetched_tag_ref}"; then
  echo "Tag ${target_tag} was not found in the canonical QueueFix repository." >&2
  exit 1
fi

if ! fetched_tag_object="$(git rev-parse --verify "$fetched_tag_ref")"; then
  echo "The fetched tag object for ${target_tag} could not be resolved." >&2
  exit 1
fi

if [[ "$fetched_tag_object" != "$expected_tag_object" ]]; then
  echo "The fetched tag object for ${target_tag} does not match GitHub's immutable reference." >&2
  exit 1
fi

if ! target_commit="$(git rev-parse --verify "${fetched_tag_object}^{commit}")"; then
  echo "Tag ${target_tag} does not resolve to a commit." >&2
  exit 1
fi

if ! git update-ref -d "$fetched_tag_ref" "$fetched_tag_object"; then
  echo "The temporary release reference changed unexpectedly; refusing to continue." >&2
  exit 1
fi
trap - EXIT

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

git checkout --detach "$target_commit"
docker compose config --quiet
docker compose up -d --build --remove-orphans
docker compose exec -T app composer install --no-interaction --prefer-dist --optimize-autoloader
docker compose exec -T app pnpm install --frozen-lockfile
docker compose exec -T app pnpm build
docker compose exec -T app php artisan migrate --force
docker compose exec -T app php artisan route:cache
docker compose exec -T app php artisan view:cache

docker compose exec -T app php artisan up
trap - EXIT

curl --fail --silent --show-error http://localhost:8000/up >/dev/null
echo "Updated to ${target_tag} (${target_commit}). Backup retained at ${backup_file}."
