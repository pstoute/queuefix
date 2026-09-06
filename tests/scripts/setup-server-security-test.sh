#!/usr/bin/env bash
set -euo pipefail

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
setup_script="${repository_root}/deploy/setup-server.sh"

fail() {
  echo "Server setup security regression failed: $*" >&2
  exit 1
}

file_mode() {
  if stat -c '%a' "$1" 2>/dev/null; then
    return
  fi

  stat -f '%Lp' "$1"
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

fresh_environment="$test_root/fresh-environment"
mkdir -p "$fresh_environment"
cp "$repository_root/.env.example" "$fresh_environment/.env.example"

(
  cd "$fresh_environment"
  source "$setup_script"
  umask 022

  configure_demo_environment

  [[ "$(file_mode .env)" == "600" ]] || fail "a fresh .env was not owner-only"
  [[ "$(umask)" == "0022" || "$(umask)" == "022" ]] || fail "environment generation changed the caller's umask"
  [[ ! -e .env.demo && ! -L .env.demo ]] || fail "a redundant .env.demo secret copy remained"
  [[ -z "$(find . -maxdepth 1 -type f -name '.env.*' ! -name '.env.example' -print -quit)" ]] || fail "a temporary secret-bearing environment file remained"
  grep -qx 'APP_ENV=production' .env || fail "production environment override was not applied"
  grep -qx 'APP_URL=https://demo.queuefix.com' .env || fail "demo URL override was not applied"
  grep -qx 'DB_PASSWORD=' .env || fail "the managed Docker credential must not be duplicated in .env"
)

existing_environment="$test_root/existing-environment"
mkdir -p "$existing_environment"
cp "$repository_root/.env.example" "$existing_environment/.env.example"
: > "$existing_environment/.env"
: > "$existing_environment/.env.demo"
chmod 0666 "$existing_environment/.env" "$existing_environment/.env.demo"

(
  cd "$existing_environment"
  source "$setup_script"
  umask 022

  configure_demo_environment

  [[ "$(file_mode .env)" == "600" ]] || fail "an existing permissive .env was not tightened"
  [[ ! -e .env.demo && ! -L .env.demo ]] || fail "the legacy .env.demo file was not removed"
  grep -qx 'QUEUEFIX_DEMO_MODE=true' .env || fail "an existing installation lost its demo configuration"
)

failure_environment="$test_root/failure-environment"
mkdir -p "$failure_environment" "$test_root/failure-bin"
cp "$repository_root/.env.example" "$failure_environment/.env.example"
printf 'ORIGINAL_ENV_VALUE=preserved\n' > "$failure_environment/.env"
chmod 0666 "$failure_environment/.env"

cat > "$test_root/failure-bin/awk" <<'EOF'
#!/usr/bin/env bash
exit 42
EOF
chmod +x "$test_root/failure-bin/awk"

set +e
(
  cd "$failure_environment"
  export PATH="$test_root/failure-bin:$PATH"
  source "$setup_script"

  configure_demo_environment
)
failure_status=$?
set -e

[[ "$failure_status" == "42" ]] || fail "environment generation did not preserve the original failure status"
[[ "$(file_mode "$failure_environment/.env")" == "600" ]] || fail "a failed update left the existing .env permissive"
grep -qx 'ORIGINAL_ENV_VALUE=preserved' "$failure_environment/.env" || fail "a failed update replaced the existing .env"
[[ -z "$(find "$failure_environment" -maxdepth 1 -type f \( -name '.env.overrides.*' -o -name '.env.next.*' \) -print -quit)" ]] || fail "a failed update left temporary secret-bearing environment files"

legacy_link_environment="$test_root/legacy-link-environment"
mkdir -p "$legacy_link_environment"
cp "$repository_root/.env.example" "$legacy_link_environment/.env.example"
printf 'outside legacy value\n' > "$test_root/outside-legacy-env"
ln -s "$test_root/outside-legacy-env" "$legacy_link_environment/.env.demo"

(
  cd "$legacy_link_environment"
  source "$setup_script"

  configure_demo_environment

  [[ ! -e .env.demo && ! -L .env.demo ]] || fail "the legacy .env.demo symlink was not removed"
  grep -qx 'outside legacy value' "$test_root/outside-legacy-env" || fail "legacy symlink cleanup modified its target"
)

symlink_environment="$test_root/symlink-environment"
mkdir -p "$symlink_environment"
cp "$repository_root/.env.example" "$symlink_environment/.env.example"
printf 'outside env value\n' > "$test_root/outside-env"
ln -s "$test_root/outside-env" "$symlink_environment/.env"

(
  cd "$symlink_environment"
  source "$setup_script"

  if configure_demo_environment; then
    fail "a symlink .env target was accepted"
  fi

  grep -qx 'outside env value' "$test_root/outside-env" || fail "a rejected .env symlink modified its target"
)

directory_environment="$test_root/directory-environment"
mkdir -p "$directory_environment/.env"
cp "$repository_root/.env.example" "$directory_environment/.env.example"

(
  cd "$directory_environment"
  source "$setup_script"

  if configure_demo_environment; then
    fail "a directory .env target was accepted"
  fi

  [[ -d .env ]] || fail "a rejected .env directory was modified"
)

echo "Server setup security regression passed."
