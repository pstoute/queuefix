#!/usr/bin/env bash

set -euo pipefail

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
probe_root="$(mktemp -d "${TMPDIR:-/tmp}/queuefix-docker-context.XXXXXX")"
probe_image="queuefix-docker-context-probe-${GITHUB_RUN_ID:-local}-$$"

cleanup() {
    docker image rm --force "${probe_image}" >/dev/null 2>&1 || true
    rm -rf "${probe_root}"
}
trap cleanup EXIT

if grep -Eq '^[[:space:]]*COPY[[:space:]]+\.[[:space:]]+\.' "${repository_root}/Dockerfile"; then
    printf '%s\n' 'Dockerfile must copy explicit application paths, not the repository root.' >&2
    exit 1
fi

git -C "${repository_root}" archive --format=tar HEAD | tar -xf - -C "${probe_root}"

mkdir -p \
    "${probe_root}/.git" \
    "${probe_root}/app" \
    "${probe_root}/config" \
    "${probe_root}/database" \
    "${probe_root}/public" \
    "${probe_root}/resources" \
    "${probe_root}/routes" \
    "${probe_root}/storage/app/private" \
    "${probe_root}/storage/backups"

printf '%s\n' 'DOCKER_CONTEXT_SECRET_SENTINEL=env' > "${probe_root}/.env"
printf '%s\n' 'DOCKER_CONTEXT_SECRET_SENTINEL=production' > "${probe_root}/.env.production"
printf '%s\n' 'DOCKER_CONTEXT_SECRET_SENTINEL=demo' > "${probe_root}/.env.demo"
printf '%s\n' '{"docker-context-secret-sentinel":"composer"}' > "${probe_root}/auth.json"
printf '%s\n' 'DOCKER_CONTEXT_SECRET_SENTINEL=git' > "${probe_root}/.git/config"
printf '%s\n' '{"docker-context-secret-sentinel":"nested-auth"}' > "${probe_root}/app/auth.json"
printf '%s\n' 'DOCKER_CONTEXT_SECRET_SENTINEL=direnv' > "${probe_root}/app/.envrc"
printf '%s\n' 'DOCKER_CONTEXT_SECRET_SENTINEL=deploy-key' > "${probe_root}/app/Deploy_Key"
printf '%s\n' '{"docker-context-secret-sentinel":"service-account"}' > "${probe_root}/config/service-account-credentials.json"
printf '%s\n' '{"docker-context-secret-sentinel":"uppercase-credentials"}' > "${probe_root}/config/Credentials.JSON"
printf '%s\n' '{"docker-context-secret-sentinel":"uppercase-service-account"}' > "${probe_root}/config/Service-Account.JSON"
printf '%s\n' '{"private_key":"DOCKER_CONTEXT_SECRET_SENTINEL=firebase"}' > "${probe_root}/config/project-firebase-adminsdk-abcd.json"
printf '%s\n' 'api_key: DOCKER_CONTEXT_SECRET_SENTINEL=yaml' > "${probe_root}/config/secrets.yaml"
printf '%s\n' 'DOCKER_CONTEXT_SECRET_SENTINEL=database' > "${probe_root}/database/database.sqlite"
printf '%s\n' 'DOCKER_CONTEXT_SECRET_SENTINEL=nested-env' > "${probe_root}/public/.env.backup"
printf '%s\n' 'DOCKER_CONTEXT_SECRET_SENTINEL=suffix-env' > "${probe_root}/public/production.env"
printf '%s\n' 'DOCKER_CONTEXT_SECRET_SENTINEL=uppercase-env' > "${probe_root}/public/PRODUCTION.ENV"
printf '%s\n' 'DOCKER_CONTEXT_SECRET_SENTINEL=nested-key' > "${probe_root}/resources/private.key"
printf '%s\n' 'DOCKER_CONTEXT_SECRET_SENTINEL=uppercase-pem' > "${probe_root}/resources/PRIVATE.PEM"
printf '%s\n' 'DOCKER_CONTEXT_SECRET_SENTINEL=uppercase-ssh-key' > "${probe_root}/resources/ID_RSA"
printf '%s\n' 'DOCKER_CONTEXT_SECRET_SENTINEL=apple-key' > "${probe_root}/resources/AuthKey_ABC123.p8"
printf '%s\n' 'DOCKER_CONTEXT_SECRET_SENTINEL=nested-database' > "${probe_root}/routes/database.sqlite"
printf '%s\n' 'DOCKER_CONTEXT_SECRET_SENTINEL=sqlite3' > "${probe_root}/routes/database.sqlite3"
printf '%s\n' 'DOCKER_CONTEXT_SECRET_SENTINEL=uppercase-sqlite3' > "${probe_root}/routes/DATABASE.SQLITE3"
printf '%s\n' 'DOCKER_CONTEXT_SECRET_SENTINEL=compressed-backup' > "${probe_root}/database/migrations/backup.sql.gz"
printf '%s\n' 'DOCKER_CONTEXT_SECRET_SENTINEL=backup' > "${probe_root}/storage/backups/database.sql"
printf '%s\n' 'DOCKER_CONTEXT_SECRET_SENTINEL=attachment' > "${probe_root}/storage/app/private/attachment.txt"
printf '%s\n' 'DOCKER_CONTEXT_SECRET_SENTINEL=key' > "${probe_root}/storage/private.key"

docker build \
    --file "${probe_root}/tests/docker/Dockerfile.context" \
    --tag "${probe_image}" \
    "${probe_root}"

docker run --rm "${probe_image}" sh -eu -c '
    for forbidden in \
        /context/.env \
        /context/.env.production \
        /context/.env.demo \
        /context/auth.json \
        /context/.git \
        /context/app/auth.json \
        /context/app/.envrc \
        /context/app/Deploy_Key \
        /context/config/service-account-credentials.json \
        /context/config/Credentials.JSON \
        /context/config/Service-Account.JSON \
        /context/config/project-firebase-adminsdk-abcd.json \
        /context/config/secrets.yaml \
        /context/database/database.sqlite \
        /context/database/migrations/backup.sql.gz \
        /context/public/.env.backup \
        /context/public/production.env \
        /context/public/PRODUCTION.ENV \
        /context/resources/private.key \
        /context/resources/PRIVATE.PEM \
        /context/resources/ID_RSA \
        /context/resources/AuthKey_ABC123.p8 \
        /context/routes/database.sqlite \
        /context/routes/database.sqlite3 \
        /context/routes/DATABASE.SQLITE3 \
        /context/storage/backups/database.sql \
        /context/storage/app/private/attachment.txt \
        /context/storage/private.key
    do
        test ! -e "${forbidden}"
    done

    for required in \
        /context/app \
        /context/bootstrap \
        /context/config \
        /context/database/migrations \
        /context/public \
        /context/resources \
        /context/routes \
        /context/artisan \
        /context/composer.json \
        /context/composer.lock \
        /context/package.json \
        /context/pnpm-lock.yaml \
        /context/pnpm-workspace.yaml \
        /context/vite.config.js
    do
        test -e "${required}"
    done

    ! grep -R "DOCKER_CONTEXT_SECRET_SENTINEL" /context
'

printf '%s\n' 'Docker build context excludes synthetic secrets and preserves required inputs.'
