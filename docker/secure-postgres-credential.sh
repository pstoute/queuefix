#!/bin/sh

set -eu

password_file="${QUEUEFIX_DATABASE_PASSWORD_FILE:-/run/queuefix-secrets/database-password}"

if [ -L "$password_file" ] || [ ! -f "$password_file" ]; then
    echo "The managed PostgreSQL credential is not a regular file." >&2
    exit 1
fi

desired_password="$(cat "$password_file")"

if [ "${#desired_password}" -ne 64 ] \
    || ! printf '%s' "$desired_password" | grep -Eq '^[0-9a-f]{64}$'; then
    echo "The managed PostgreSQL credential is invalid." >&2
    exit 1
fi

can_connect() {
    PGPASSWORD="$1" psql \
        --no-password \
        --no-psqlrc \
        --quiet \
        --tuples-only \
        --no-align \
        --command 'SELECT 1' >/dev/null 2>&1
}

if can_connect "$desired_password"; then
    unset desired_password
    echo 'PostgreSQL already uses the managed credential.'
    exit 0
fi

# Compatibility bridge for installations created before managed credentials.
# It is used only to replace the former public default and cannot authenticate
# after a successful rotation.
legacy_password='secret'

if ! can_connect "$legacy_password"; then
    unset desired_password legacy_password
    echo 'PostgreSQL accepts neither the managed credential nor the legacy migration credential.' >&2
    exit 1
fi

PGPASSWORD="$legacy_password" psql \
    --no-password \
    --no-psqlrc \
    --quiet \
    --set=ON_ERROR_STOP=1 \
    --set="new_password=${desired_password}" <<'SQL'
ALTER ROLE queuefix WITH PASSWORD :'new_password';
SQL

unset legacy_password

if ! can_connect "$desired_password"; then
    unset desired_password
    echo 'PostgreSQL rejected the managed credential after rotation.' >&2
    exit 1
fi

unset desired_password
echo 'PostgreSQL credential rotation completed.'
