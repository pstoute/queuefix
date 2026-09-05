#!/usr/bin/env bash
set -euo pipefail

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
setup_script="${repository_root}/deploy/setup-server.sh"

fail() {
  echo "Server setup security regression failed: $*" >&2
  exit 1
}

bash -n "$setup_script"

network_to_shell_pattern='curl[^|]*\|[[:space:]]*(sudo[[:space:]]+)?(ba)?sh([[:space:]]|$)'
if grep -R -nE "$network_to_shell_pattern" \
  "$repository_root/deploy" \
  "$repository_root/Dockerfile"; then
  fail "downloaded network content must not be piped to a shell"
fi

test_root="$(mktemp -d "${TMPDIR:-/tmp}/queuefix-setup-test.XXXXXX")"
trap 'rm -rf -- "$test_root"' EXIT
mkdir -p "$test_root/fake-bin" "$test_root/state"

cat > "$test_root/fake-bin/sudo" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
printf 'sudo %s\n' "$*" >> "${SETUP_TEST_TRACE}"
exec "$@"
EOF

cat > "$test_root/fake-bin/apt-get" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
printf 'apt-get %s\n' "$*" >> "${SETUP_TEST_TRACE}"

if [[ "$*" == *" install "* || "${1:-}" == "install" ]]; then
  for argument in "$@"; do
    case "$argument" in
      docker.io)
        : > "${SETUP_TEST_STATE}/docker"
        ;;
      docker-compose-v2)
        : > "${SETUP_TEST_STATE}/compose"
        ;;
      caddy)
        : > "${SETUP_TEST_STATE}/caddy"
        ;;
    esac
  done
fi
EOF

cat > "$test_root/fake-bin/usermod" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
printf 'usermod %s\n' "$*" >> "${SETUP_TEST_TRACE}"
EOF

cat > "$test_root/fake-bin/docker" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail

if [[ "${1:-}" == "--version" && -f "${SETUP_TEST_STATE}/docker" ]]; then
  echo "Docker version test"
  exit 0
fi

if [[ "${1:-}" == "compose" && "${2:-}" == "version" && -f "${SETUP_TEST_STATE}/compose" ]]; then
  echo "Docker Compose version test"
  exit 0
fi

exit 127
EOF

cat > "$test_root/fake-bin/caddy" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail

if [[ "${1:-}" == "version" && -f "${SETUP_TEST_STATE}/caddy" ]]; then
  echo "v-test"
  exit 0
fi

exit 127
EOF

chmod +x "$test_root/fake-bin/"*
: > "$test_root/trace"

(
  export PATH="$test_root/fake-bin:$PATH"
  export SETUP_TEST_STATE="$test_root/state"
  export SETUP_TEST_TRACE="$test_root/trace"

  # Sourcing exposes the package-install functions without running provisioning.
  source "$setup_script"

  install_docker
  install_docker_compose
  install_caddy

  # A second pass must use the installed-version checks and make no root changes.
  install_docker
  install_docker_compose
  install_caddy
)

[[ "$(grep -c '^apt-get install -y -qq docker.io$' "$test_root/trace")" == "1" ]] || fail "docker.io must be installed exactly once"
[[ "$(grep -c '^apt-get install -y -qq docker-compose-v2$' "$test_root/trace")" == "1" ]] || fail "docker-compose-v2 must be installed exactly once"
[[ "$(grep -c '^apt-get install -y -qq caddy$' "$test_root/trace")" == "1" ]] || fail "caddy must be installed exactly once"
[[ "$(grep -c '^usermod -aG docker ubuntu$' "$test_root/trace")" == "1" ]] || fail "Docker group membership must be configured exactly once"
[[ "$(grep -c '^sudo ' "$test_root/trace")" == "4" ]] || fail "idempotent rerun made unexpected privileged changes"

echo "Server setup security regression passed."
