#!/usr/bin/env bash

set -euo pipefail

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
project_name="queuefix-quick-start-${GITHUB_RUN_ID:-local}-$$"
project_name="${project_name//[^a-zA-Z0-9_.-]/-}"
created_environment=false
vendor_was_absent=false
node_modules_was_absent=false

cleanup() {
    docker compose --project-name "$project_name" down --volumes --remove-orphans >/dev/null 2>&1 || true

    if [[ "$created_environment" == true ]]; then
        rm -f -- "$repository_root/.env"
    fi

    if [[ "$vendor_was_absent" == true ]]; then
        rmdir -- "$repository_root/vendor" >/dev/null 2>&1 || true
    fi

    if [[ "$node_modules_was_absent" == true ]]; then
        rmdir -- "$repository_root/node_modules" >/dev/null 2>&1 || true
    fi
}

fail() {
    echo "Docker Quick Start regression failed: $*" >&2
    exit 1
}

trap cleanup EXIT HUP INT TERM
cd "$repository_root"

if [[ -e .env || -L .env ]]; then
    fail 'the test refuses to replace an existing .env'
fi

if [[ ! -e vendor && ! -L vendor ]]; then
    vendor_was_absent=true
fi

if [[ ! -e node_modules && ! -L node_modules ]]; then
    node_modules_was_absent=true
fi

install -m 0600 .env.example .env
created_environment=true

docker compose --project-name "$project_name" build
docker compose --project-name "$project_name" run --rm database-secret
docker compose --project-name "$project_name" run --rm --no-deps app php artisan key:generate

grep -Eq '^APP_KEY=base64:[A-Za-z0-9+/=]+$' .env \
    || fail 'the no-dependency application bootstrap did not generate an application key'

docker compose --project-name "$project_name" run --rm migrate

docker compose --project-name "$project_name" run --rm --no-deps app php -r '
    require "vendor/autoload.php";
    $app = require "bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $password = file_get_contents((string) getenv("DB_PASSWORD_FILE"));
    exit(is_string($password) && config("database.connections.pgsql.password") === $password ? 0 : 1);
'

cleanup

if [[ "$vendor_was_absent" == true && ( -e vendor || -L vendor ) ]]; then
    fail 'the clean install left a Docker-created vendor mountpoint in the caller workspace'
fi

if [[ "$node_modules_was_absent" == true && ( -e node_modules || -L node_modules ) ]]; then
    fail 'the clean install left a Docker-created node_modules mountpoint in the caller workspace'
fi

created_environment=false
trap - EXIT HUP INT TERM
echo 'Docker Quick Start regression passed.'
