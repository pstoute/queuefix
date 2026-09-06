#!/bin/sh

set -eu

secret_directory="${QUEUEFIX_DATABASE_SECRET_DIR:-/run/queuefix-secrets}"
temporary_secret=''

cleanup() {
    if [ -n "$temporary_secret" ] && [ -e "$temporary_secret" ]; then
        rm -f -- "$temporary_secret"
    fi
}

trap cleanup EXIT HUP INT TERM
umask 077

create_secret() {
    secret_name="$1"
    secret_path="${secret_directory}/${secret_name}"

    if [ -L "$secret_path" ] || { [ -e "$secret_path" ] && [ ! -f "$secret_path" ]; }; then
        echo "Refusing non-regular database credential target: ${secret_path}" >&2
        exit 1
    fi

    if [ -f "$secret_path" ]; then
        if [ "$(wc -c < "$secret_path" | tr -d ' ')" -ne 64 ] \
            || ! grep -Eq '^[0-9a-f]{64}$' "$secret_path"; then
            echo "Refusing invalid existing database credential: ${secret_path}" >&2
            exit 1
        fi

        chmod 0600 "$secret_path"
        return
    fi

    temporary_secret="$(mktemp "${secret_path}.next.XXXXXX")"
    od -An -N32 -tx1 /dev/urandom | tr -d ' \n' > "$temporary_secret"

    if [ "$(wc -c < "$temporary_secret" | tr -d ' ')" -ne 64 ] \
        || ! grep -Eq '^[0-9a-f]{64}$' "$temporary_secret"; then
        echo "Database credential generation returned an invalid value." >&2
        exit 1
    fi

    chmod 0600 "$temporary_secret"
    mv -- "$temporary_secret" "$secret_path"
    temporary_secret=''
}

create_secret database-password

trap - EXIT HUP INT TERM
