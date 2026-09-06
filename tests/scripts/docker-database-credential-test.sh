#!/usr/bin/env bash

set -euo pipefail

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
test_suffix="${GITHUB_RUN_ID:-local}-$$"
test_prefix="queuefix-credential-test-${test_suffix//[^a-zA-Z0-9_.-]/-}"
network_name="${test_prefix}-network"
credential_volume="${test_prefix}-credentials"
legacy_data_volume="${test_prefix}-legacy-data"
unexpected_data_volume="${test_prefix}-unexpected-data"
legacy_container="${test_prefix}-legacy-postgres"
unexpected_container="${test_prefix}-unexpected-postgres"
cache_test_root="$(mktemp -d "${TMPDIR:-/tmp}/queuefix-config-cache-test.XXXXXX")"

cleanup() {
    docker rm -f "$legacy_container" "$unexpected_container" >/dev/null 2>&1 || true
    docker volume rm "$credential_volume" "$legacy_data_volume" "$unexpected_data_volume" >/dev/null 2>&1 || true
    docker network rm "$network_name" >/dev/null 2>&1 || true
    rm -rf -- "$cache_test_root"
}

fail() {
    echo "Docker database credential regression failed: $*" >&2
    exit 1
}

wait_for_database() {
    local container="$1"
    local password="$2"
    local description="$3"
    local attempt

    for attempt in $(seq 1 60); do
        if docker exec \
            --env "PGPASSWORD=$password" \
            "$container" \
            psql --host=127.0.0.1 --username=queuefix --dbname=queuefix --no-password \
            --command='SELECT 1' >/dev/null 2>&1; then
            return
        fi

        if [[ "$(docker inspect --format '{{.State.Running}}' "$container" 2>/dev/null || true)" != true ]]; then
            docker logs "$container" >&2 || true
            fail "$description PostgreSQL exited before becoming ready"
        fi

        sleep 1
    done

    docker logs "$container" >&2 || true
    fail "$description PostgreSQL did not become ready"
}

trap cleanup EXIT HUP INT TERM
cleanup

mkdir -p "$cache_test_root/cache"
printf '%s\n' '<?php return ['"'"'database'"'"' => ['"'"'password'"'"' => '"'"'secret'"'"']];' > "$cache_test_root/cache/config.php"
QUEUEFIX_APPLICATION_CACHE_DIR="$cache_test_root/cache" \
    /bin/sh "$repository_root/docker/clear-application-config-cache.sh" >/dev/null
[[ ! -e "$cache_test_root/cache/config.php" ]] || fail 'stale application configuration cache was retained'

printf '%s\n' 'preserved outside cache data' > "$cache_test_root/outside"
ln -s "$cache_test_root/outside" "$cache_test_root/cache/config.php"
QUEUEFIX_APPLICATION_CACHE_DIR="$cache_test_root/cache" \
    /bin/sh "$repository_root/docker/clear-application-config-cache.sh" >/dev/null
[[ ! -e "$cache_test_root/cache/config.php" ]] || fail 'cached configuration symlink was retained'
grep -Fxq 'preserved outside cache data' "$cache_test_root/outside" \
    || fail 'cache clearing modified a symlink target'

mkdir "$cache_test_root/cache/config.php"
if QUEUEFIX_APPLICATION_CACHE_DIR="$cache_test_root/cache" \
    /bin/sh "$repository_root/docker/clear-application-config-cache.sh" >/dev/null 2>&1; then
    fail 'cache clearing accepted a non-regular configuration cache'
fi
rmdir "$cache_test_root/cache/config.php"

docker network create "$network_name" >/dev/null
docker volume create "$credential_volume" >/dev/null
docker volume create "$legacy_data_volume" >/dev/null
docker volume create "$unexpected_data_volume" >/dev/null

docker run --rm \
    --network none \
    --volume "$repository_root/docker/initialize-database-secrets.sh:/usr/local/bin/initialize-database-secrets:ro" \
    --volume "$credential_volume:/run/queuefix-secrets" \
    postgres:16-alpine \
    /bin/sh /usr/local/bin/initialize-database-secrets

docker run --rm \
    --network none \
    --volume "$credential_volume:/run/queuefix-secrets:ro" \
    postgres:16-alpine \
    /bin/sh -ec '
        test "$(stat -c %a /run/queuefix-secrets/database-password)" = 600
        test "$(wc -c < /run/queuefix-secrets/database-password | tr -d " ")" = 64
        grep -Eq "^[0-9a-f]{64}$" /run/queuefix-secrets/database-password
    '

docker run --rm \
    --network none \
    --volume "$repository_root/docker/initialize-database-secrets.sh:/usr/local/bin/initialize-database-secrets:ro" \
    --volume "$credential_volume:/run/queuefix-secrets" \
    postgres:16-alpine \
    /bin/sh -ec '
        previous_password="$(cat /run/queuefix-secrets/database-password)"
        /bin/sh /usr/local/bin/initialize-database-secrets
        test "$previous_password" = "$(cat /run/queuefix-secrets/database-password)"
    '

docker run --detach \
    --name "$legacy_container" \
    --network "$network_name" \
    --env POSTGRES_DB=queuefix \
    --env POSTGRES_USER=queuefix \
    --env POSTGRES_PASSWORD=secret \
    --volume "$legacy_data_volume:/var/lib/postgresql/data" \
    postgres:16-alpine >/dev/null

wait_for_database "$legacy_container" secret legacy

docker exec \
    --env PGPASSWORD=secret \
    "$legacy_container" \
    psql --host=127.0.0.1 --username=queuefix --dbname=queuefix --no-password \
    --command='CREATE TABLE credential_sentinel (value text NOT NULL); INSERT INTO credential_sentinel VALUES ('"'"'preserved'"'"');' \
    >/dev/null

run_transition() {
    docker run --rm \
        --network "$network_name" \
        --env PGCONNECT_TIMEOUT=5 \
        --env "PGHOST=$legacy_container" \
        --env PGUSER=queuefix \
        --env PGDATABASE=queuefix \
        --volume "$repository_root/docker/secure-postgres-credential.sh:/usr/local/bin/secure-postgres-credential:ro" \
        --volume "$credential_volume:/run/queuefix-secrets:ro" \
        postgres:16-alpine \
        /bin/sh /usr/local/bin/secure-postgres-credential
}

run_transition >/dev/null

docker run --rm \
    --network "$network_name" \
    --env "PGHOST=$legacy_container" \
    --env PGUSER=queuefix \
    --env PGDATABASE=queuefix \
    --volume "$credential_volume:/run/queuefix-secrets:ro" \
    postgres:16-alpine \
    /bin/sh -ec '
        PGPASSWORD="$(cat /run/queuefix-secrets/database-password)" \
            psql --no-password --tuples-only --no-align \
            --command="SELECT value FROM credential_sentinel" \
            | grep -qx preserved

        if PGPASSWORD=secret psql --no-password --command="SELECT 1" >/dev/null 2>&1; then
            exit 1
        fi
    '

# A second transition must recognize the managed credential without changing it.
run_transition >/dev/null

docker run --detach \
    --name "$unexpected_container" \
    --network "$network_name" \
    --env POSTGRES_DB=queuefix \
    --env POSTGRES_USER=queuefix \
    --env POSTGRES_PASSWORD=unexpected-private-password \
    --volume "$unexpected_data_volume:/var/lib/postgresql/data" \
    postgres:16-alpine >/dev/null

wait_for_database "$unexpected_container" unexpected-private-password unexpected-credential

if docker run --rm \
    --network "$network_name" \
    --env PGCONNECT_TIMEOUT=5 \
    --env "PGHOST=$unexpected_container" \
    --env PGUSER=queuefix \
    --env PGDATABASE=queuefix \
    --volume "$repository_root/docker/secure-postgres-credential.sh:/usr/local/bin/secure-postgres-credential:ro" \
    --volume "$credential_volume:/run/queuefix-secrets:ro" \
    postgres:16-alpine \
    /bin/sh /usr/local/bin/secure-postgres-credential >/dev/null 2>&1; then
    fail 'transition accepted a database with an unknown credential'
fi

echo 'Docker database credential regression passed.'
