#!/usr/bin/env bash
set -euo pipefail

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
updater="${repository_root}/deploy/update-docker.sh"

fail() {
  echo "Updater backup regression failed: $*" >&2
  exit 1
}

mode_of() {
  if stat -c '%a' "$1" >/dev/null 2>&1; then
    stat -c '%a' "$1"
  else
    stat -f '%Lp' "$1"
  fi
}

if [[ ! -x "$updater" ]]; then
  fail "deploy/update-docker.sh must be executable"
fi

if ! git -C "$repository_root" check-ignore --quiet storage/backups/example.sql; then
  fail "generated backups must be ignored by Git"
fi

test_root="$(mktemp -d "${TMPDIR:-/tmp}/queuefix-updater-test.XXXXXX")"
trap 'rm -rf -- "$test_root"' EXIT

prepare_scenario() {
  local scenario="$1"

  mkdir -p "$scenario/deploy" "$scenario/fake-bin" "$scenario/trace"
  cp "$updater" "$scenario/deploy/update-docker.sh"

  cat > "$scenario/fake-bin/git" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail

case "$*" in
  "rev-parse --show-toplevel")
    pwd
    ;;
  "status --porcelain"|"fetch --tags origin"|"rev-parse --verify --quiet refs/tags/v1.2.3")
    ;;
  "checkout --detach v1.2.3")
    : > "${TEST_TRACE_DIR}/checkout"
    umask > "${TEST_TRACE_DIR}/checkout-umask"
    ;;
  *)
    echo "Unexpected git invocation: $*" >&2
    exit 90
    ;;
esac
EOF

  cat > "$scenario/fake-bin/docker" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail

if [[ "${1:-}" == "compose" && "${2:-}" == "config" && "${3:-}" == "--quiet" ]]; then
  exit 0
fi

if [[ "${1:-}" == "compose" && "${2:-}" == "exec" && "${3:-}" == "-T" && "${4:-}" == "postgres" ]]; then
  : > "${TEST_TRACE_DIR}/dump"
  in_progress_backup="$(find storage/backups -maxdepth 1 -type f -name '.queuefix-*' -print -quit)"
  if [[ -z "$in_progress_backup" ]]; then
    echo "The updater did not create its private temporary backup before pg_dump." >&2
    exit 92
  fi
  if stat -c '%a' "$in_progress_backup" >/dev/null 2>&1; then
    in_progress_mode="$(stat -c '%a' "$in_progress_backup")"
  else
    in_progress_mode="$(stat -f '%Lp' "$in_progress_backup")"
  fi
  if [[ "$in_progress_mode" != "600" ]]; then
    echo "The in-progress backup mode was ${in_progress_mode}, not 600." >&2
    exit 93
  fi
  if [[ "${FAKE_DUMP_EMPTY:-false}" != "true" ]]; then
    printf '%s\n' 'sensitive database contents'
  fi
  if [[ "${FAKE_DUMP_FAIL:-false}" == "true" ]]; then
    exit 42
  fi
  exit 0
fi

if [[ "${1:-}" == "compose" ]]; then
  exit 0
fi

echo "Unexpected docker invocation: $*" >&2
exit 91
EOF

  cat > "$scenario/fake-bin/date" <<'EOF'
#!/usr/bin/env bash
printf '%s\n' '20260905T120000Z'
EOF

  cat > "$scenario/fake-bin/curl" <<'EOF'
#!/usr/bin/env bash
exit 0
EOF

  chmod +x "$scenario/deploy/update-docker.sh" "$scenario/fake-bin/"*
}

run_updater() {
  local scenario="$1"
  shift

  (
    umask 022
    cd "$scenario"
    env \
      PATH="${scenario}/fake-bin:${PATH}" \
      TEST_TRACE_DIR="${scenario}/trace" \
      "$@" \
      ./deploy/update-docker.sh v1.2.3
  )
}

success_scenario="${test_root}/success"
prepare_scenario "$success_scenario"
mkdir -p "$success_scenario/storage/backups"
chmod 0777 "$success_scenario/storage/backups"
run_updater "$success_scenario"

backup_count="$(find "$success_scenario/storage/backups" -maxdepth 1 -type f -name '*.sql' | wc -l | tr -d ' ')"
[[ "$backup_count" == "1" ]] || fail "successful update must publish exactly one SQL backup"
backup_file="$(find "$success_scenario/storage/backups" -maxdepth 1 -type f -name '*.sql' -print)"
[[ "$(mode_of "$success_scenario/storage/backups")" == "700" ]] || fail "backup directory mode must be 0700"
[[ "$(mode_of "$backup_file")" == "600" ]] || fail "backup file mode must be 0600"
grep -Fxq 'sensitive database contents' "$backup_file" || fail "completed backup content was not retained"
[[ -z "$(find "$success_scenario/storage/backups" -maxdepth 1 -type f -name '.queuefix-*' -print -quit)" ]] || fail "successful update left a temporary backup"
checkout_umask="$(tr -d '[:space:]' < "$success_scenario/trace/checkout-umask")"
[[ "$checkout_umask" == "0022" || "$checkout_umask" == "022" ]] || fail "updater did not restore the caller's umask before checkout"

failure_scenario="${test_root}/failure"
prepare_scenario "$failure_scenario"
if run_updater "$failure_scenario" FAKE_DUMP_FAIL=true; then
  fail "a failed PostgreSQL dump must abort the update"
fi
[[ -z "$(find "$failure_scenario/storage/backups" -maxdepth 1 -type f -print -quit)" ]] || fail "failed dump left partial backup data"
[[ ! -e "$failure_scenario/trace/checkout" ]] || fail "failed dump reached the deployment phase"

empty_scenario="${test_root}/empty"
prepare_scenario "$empty_scenario"
if run_updater "$empty_scenario" FAKE_DUMP_EMPTY=true; then
  fail "an empty PostgreSQL dump must abort the update"
fi
[[ -z "$(find "$empty_scenario/storage/backups" -maxdepth 1 -type f -print -quit)" ]] || fail "empty dump left a backup file"
[[ ! -e "$empty_scenario/trace/checkout" ]] || fail "empty dump reached the deployment phase"

symlink_scenario="${test_root}/symlink"
prepare_scenario "$symlink_scenario"
mkdir -p "$symlink_scenario/storage" "$symlink_scenario/outside"
ln -s "$symlink_scenario/outside" "$symlink_scenario/storage/backups"
if run_updater "$symlink_scenario"; then
  fail "a symlinked backup directory must be rejected"
fi
[[ ! -e "$symlink_scenario/trace/dump" ]] || fail "symlink rejection occurred after the database dump"
[[ -z "$(find "$symlink_scenario/outside" -mindepth 1 -print -quit)" ]] || fail "symlink rejection modified the target directory"

echo "Updater backup regression passed."
