#!/bin/sh

set -eu

cache_directory="${QUEUEFIX_APPLICATION_CACHE_DIR:-/var/www/html/bootstrap/cache}"
cached_config="${cache_directory}/config.php"

if [ -L "$cache_directory" ] || [ ! -d "$cache_directory" ]; then
    echo 'The application cache path is not a real directory.' >&2
    exit 1
fi

if [ -L "$cached_config" ]; then
    rm -- "$cached_config"
elif [ -e "$cached_config" ]; then
    if [ ! -f "$cached_config" ]; then
        echo 'Refusing to replace a non-regular application configuration cache.' >&2
        exit 1
    fi

    rm -- "$cached_config"
fi

echo 'Stale application configuration cache cleared.'
