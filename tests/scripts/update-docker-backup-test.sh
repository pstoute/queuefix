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

target_commit='2222222222222222222222222222222222222222'
annotated_tag_object='3333333333333333333333333333333333333333'
if [[ "${FAKE_TAG_KIND:-annotated}" == "lightweight" ]]; then
  default_fetched_object="$target_commit"
else
  default_fetched_object="$annotated_tag_object"
fi
fetched_tag_object="${FAKE_FETCHED_TAG_OBJECT:-$default_fetched_object}"
printf 'git %s\n' "$*" >> "${TEST_TRACE_DIR}/git-commands"

if [[ "${1:-}" == "rev-parse" && "${2:-}" == "--verify" && "${3:-}" == refs/queuefix-update/v1.2.3-* ]]; then
  [[ -e "${TEST_TRACE_DIR}/tag-fetched" ]] || exit 45
  printf '%s\n' "$fetched_tag_object"
  exit 0
fi

if [[ "${1:-}" == "update-ref" && "${2:-}" == "-d" && "${3:-}" == refs/queuefix-update/v1.2.3-* ]]; then
  if [[ $# -eq 4 && "$4" != "$fetched_tag_object" ]]; then
    exit 48
  fi
  : > "${TEST_TRACE_DIR}/temporary-ref-deleted"
  exit 0
fi

case "$*" in
  "rev-parse --show-toplevel")
    pwd
    ;;
  "status --porcelain")
    ;;
  "fetch --no-tags --refmap= https://github.com/pstoute/queuefix.git +refs/tags/v1.2.3:refs/queuefix-update/v1.2.3-"*)
    if [[ "${FAKE_TAG_FETCH_FAIL:-false}" == "true" ]]; then
      exit 44
    fi
    fetch_refspec="${!#}"
    printf '%s\n' "${fetch_refspec#*:}" > "${TEST_TRACE_DIR}/fetched-ref"
    : > "${TEST_TRACE_DIR}/tag-fetched"
    ;;
  "rev-parse --verify ${fetched_tag_object}^{commit}")
    if [[ "${FAKE_TAG_RESOLVE_FAIL:-false}" == "true" ]]; then
      exit 45
    fi
    printf '%s\n' "$target_commit"
    ;;
  "checkout --detach ${target_commit}")
    : > "${TEST_TRACE_DIR}/checkout"
    printf '%s\n' "$target_commit" > "${TEST_TRACE_DIR}/checkout-commit"
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

printf 'docker %s\n' "$*" >> "${TEST_TRACE_DIR}/docker-commands"

if [[ "${1:-}" == "compose" && "${2:-}" == "config" && "${3:-}" == "--quiet" ]]; then
  exit 0
fi

if [[ "${1:-}" == "compose" && "${2:-}" == "exec" && "${3:-}" == "-T" && "${4:-}" == "app" && "${5:-}" == "php" && "${6:-}" == "-r" ]]; then
  if [[ "${FAKE_RELEASE_PARSER_FAIL:-false}" == "true" ]]; then
    exit 46
  fi

  exec php -r "$7" "$8"
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
set -euo pipefail

printf 'curl %s\n' "$*" >> "${TEST_TRACE_DIR}/curl-commands"

if [[ "$*" == *"/repos/pstoute/queuefix/git/ref/tags/v1.2.3"* ]]; then
  if [[ "${FAKE_TAG_KIND:-annotated}" == "lightweight" ]]; then
    default_tag_object='2222222222222222222222222222222222222222'
    default_tag_type='commit'
  else
    default_tag_object='3333333333333333333333333333333333333333'
    default_tag_type='tag'
  fi

  case "${FAKE_TAG_REFERENCE_RESPONSE:-valid}" in
    valid)
      printf '{"ref":"refs/tags/v1.2.3","object":{"type":"%s","sha":"%s"}}\n' \
        "${FAKE_EXPECTED_TAG_TYPE:-$default_tag_type}" \
        "${FAKE_EXPECTED_TAG_OBJECT:-$default_tag_object}"
      ;;
    wrong-ref)
      printf '%s\n' '{"ref":"refs/tags/v9.9.9","object":{"type":"tag","sha":"3333333333333333333333333333333333333333"}}'
      ;;
    invalid-sha)
      printf '%s\n' '{"ref":"refs/tags/v1.2.3","object":{"type":"tag","sha":"not-a-git-object"}}'
      ;;
    invalid-type)
      printf '%s\n' '{"ref":"refs/tags/v1.2.3","object":{"type":"blob","sha":"3333333333333333333333333333333333333333"}}'
      ;;
    malformed)
      printf '%s\n' '{not-json'
      ;;
    api-failure)
      exit 22
      ;;
    *)
      exit 49
      ;;
  esac
  exit 0
fi

if [[ "$*" != *"/repos/pstoute/queuefix/releases/tags/v1.2.3"* ]]; then
  exit 0
fi

case "${FAKE_RELEASE_RESPONSE:-valid}" in
  valid)
    printf '%s\n' '{"tag_name":"v1.2.3","immutable":true,"draft":false,"published_at":"2026-09-05T12:00:00Z"}'
    ;;
  mutable)
    printf '%s\n' '{"tag_name":"v1.2.3","immutable":false,"draft":false,"published_at":"2026-09-05T12:00:00Z"}'
    ;;
  wrong-tag)
    printf '%s\n' '{"tag_name":"v9.9.9","immutable":true,"draft":false,"published_at":"2026-09-05T12:00:00Z"}'
    ;;
  draft)
    printf '%s\n' '{"tag_name":"v1.2.3","immutable":true,"draft":true,"published_at":null}'
    ;;
  unpublished)
    printf '%s\n' '{"tag_name":"v1.2.3","immutable":true,"draft":false,"published_at":null}'
    ;;
  malformed)
    printf '%s\n' '{not-json'
    ;;
  spoofed-body)
    printf '%s\n' '{"tag_name":"v1.2.3","immutable":false,"draft":false,"published_at":"2026-09-05T12:00:00Z","body":"\\\"immutable\\\":true"}'
    ;;
  oversized)
    head -c 1048577 /dev/zero | tr '\000' x
    ;;
  api-failure)
    exit 22
    ;;
  *)
    exit 47
    ;;
esac
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

expect_prebackup_failure() {
  local name="$1"
  shift
  local scenario="${test_root}/rejected-${name}"

  prepare_scenario "$scenario"
  if run_updater "$scenario" "$@"; then
    fail "${name} must abort before backup and deployment"
  fi

  [[ ! -e "$scenario/trace/dump" ]] || fail "${name} reached the database backup"
  [[ ! -e "$scenario/trace/checkout" ]] || fail "${name} reached checkout"
  [[ ! -d "$scenario/storage/backups" || -z "$(find "$scenario/storage/backups" -maxdepth 1 -type f -print -quit)" ]] || fail "${name} created a backup"
  if [[ -e "$scenario/trace/tag-fetched" ]]; then
    [[ -e "$scenario/trace/temporary-ref-deleted" ]] || fail "${name} left its temporary release reference"
  fi
}

prepare_real_git_scenario() {
  local scenario="$1"
  local mode="$2"
  local remote_repository="${scenario}-remote.git"

  prepare_scenario "$scenario"

  if [[ "$mode" == "race" ]]; then
    cat > "$scenario/fake-bin/git" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail

if [[ "${1:-}" == "fetch" ]]; then
  "$REAL_GIT" "$@"
  fetch_status=$?
  if [[ "$fetch_status" -eq 0 ]]; then
    git_directory="$("$REAL_GIT" rev-parse --git-dir)"
    printf '%s\t\tbranch competing of fixture\n' "$COMPETING_COMMIT" > "${git_directory}/FETCH_HEAD"
  fi
  exit "$fetch_status"
fi

exec "$REAL_GIT" "$@"
EOF
    chmod +x "$scenario/fake-bin/git"
  else
    rm -f -- "$scenario/fake-bin/git"
  fi

  git -C "$scenario" init -q
  git -C "$scenario" config user.name 'QueueFix updater test'
  git -C "$scenario" config user.email 'updater-test@example.invalid'
  git -C "$scenario" add .
  git -C "$scenario" commit -qm 'installed release'
  real_installed_commit="$(git -C "$scenario" rev-parse HEAD)"
  git -C "$scenario" branch installed

  git -C "$scenario" commit --allow-empty -qm 'target release'
  real_target_commit="$(git -C "$scenario" rev-parse HEAD)"
  git -C "$scenario" tag -a v1.2.3 -m 'target release'
  real_tag_object="$(git -C "$scenario" rev-parse refs/tags/v1.2.3)"

  git init --bare -q "$remote_repository"
  git -C "$scenario" remote add fixture "file://${remote_repository}"
  git -C "$scenario" push -q fixture HEAD:main refs/tags/v1.2.3
  git -C "$scenario" checkout -q installed
  git -C "$scenario" config url."file://${remote_repository}".insteadOf https://github.com/pstoute/queuefix.git
}

success_scenario="${test_root}/success"
prepare_scenario "$success_scenario"
mkdir -p "$success_scenario/storage/backups"
chmod 0777 "$success_scenario/storage/backups"
run_updater "$success_scenario"

grep -Eq '^git fetch --no-tags --refmap= https://github\.com/pstoute/queuefix\.git \+refs/tags/v1\.2\.3:refs/queuefix-update/v1\.2\.3-[0-9a-f]{32}$' "$success_scenario/trace/git-commands" || fail "updater did not fetch only the exact canonical release tag into a unique reference"
grep -Fxq '2222222222222222222222222222222222222222' "$success_scenario/trace/checkout-commit" || fail "checkout did not use the commit peeled from the verified immutable tag object"
[[ -e "$success_scenario/trace/temporary-ref-deleted" ]] || fail "successful update left its temporary release reference"
if grep -Eq 'git (fetch --tags|checkout --detach v1\.2\.3|rev-parse .*FETCH_HEAD|rev-parse .*refs/tags)' "$success_scenario/trace/git-commands"; then
  fail "updater trusted a mutable or local tag name"
fi
grep -Fq -- "--disable --fail --silent --show-error --proto =https --tlsv1.2 --connect-timeout 10 --max-time 30 --max-filesize 1048576" "$success_scenario/trace/curl-commands" || fail "release verification did not ignore user config and bound a TLS-protected API request"

backup_count="$(find "$success_scenario/storage/backups" -maxdepth 1 -type f -name '*.sql' | wc -l | tr -d ' ')"
[[ "$backup_count" == "1" ]] || fail "successful update must publish exactly one SQL backup"
backup_file="$(find "$success_scenario/storage/backups" -maxdepth 1 -type f -name '*.sql' -print)"
[[ "$(mode_of "$success_scenario/storage/backups")" == "700" ]] || fail "backup directory mode must be 0700"
[[ "$(mode_of "$backup_file")" == "600" ]] || fail "backup file mode must be 0600"
grep -Fxq 'sensitive database contents' "$backup_file" || fail "completed backup content was not retained"
grep -Fxq 'docker compose exec -T app php artisan route:cache' "$success_scenario/trace/docker-commands" || fail "updater did not rebuild the route cache"
grep -Fxq 'docker compose exec -T app php artisan view:cache' "$success_scenario/trace/docker-commands" || fail "updater did not rebuild the view cache"
if grep -Fq 'php artisan optimize' "$success_scenario/trace/docker-commands"; then
  fail "updater persisted database credentials in Laravel's configuration cache"
fi
[[ -z "$(find "$success_scenario/storage/backups" -maxdepth 1 -type f -name '.queuefix-*' -print -quit)" ]] || fail "successful update left a temporary backup"
checkout_umask="$(tr -d '[:space:]' < "$success_scenario/trace/checkout-umask")"
[[ "$checkout_umask" == "0022" || "$checkout_umask" == "022" ]] || fail "updater did not restore the caller's umask before checkout"

lightweight_scenario="${test_root}/lightweight"
prepare_scenario "$lightweight_scenario"
run_updater "$lightweight_scenario" FAKE_TAG_KIND=lightweight
grep -Fxq '2222222222222222222222222222222222222222' "$lightweight_scenario/trace/checkout-commit" || fail "an immutable lightweight tag did not resolve to its captured commit"
[[ -e "$lightweight_scenario/trace/temporary-ref-deleted" ]] || fail "lightweight update left its temporary release reference"

expect_prebackup_failure "mutable-release" FAKE_RELEASE_RESPONSE=mutable
expect_prebackup_failure "wrong-release-tag" FAKE_RELEASE_RESPONSE=wrong-tag
expect_prebackup_failure "draft-release" FAKE_RELEASE_RESPONSE=draft
expect_prebackup_failure "unpublished-release" FAKE_RELEASE_RESPONSE=unpublished
expect_prebackup_failure "malformed-release" FAKE_RELEASE_RESPONSE=malformed
expect_prebackup_failure "spoofed-release-body" FAKE_RELEASE_RESPONSE=spoofed-body
expect_prebackup_failure "oversized-release" FAKE_RELEASE_RESPONSE=oversized
expect_prebackup_failure "release-api-failure" FAKE_RELEASE_RESPONSE=api-failure
expect_prebackup_failure "stopped-current-app" FAKE_RELEASE_PARSER_FAIL=true
expect_prebackup_failure "wrong-tag-reference" FAKE_TAG_REFERENCE_RESPONSE=wrong-ref
expect_prebackup_failure "invalid-tag-object" FAKE_TAG_REFERENCE_RESPONSE=invalid-sha
expect_prebackup_failure "invalid-tag-type" FAKE_TAG_REFERENCE_RESPONSE=invalid-type
expect_prebackup_failure "malformed-tag-reference" FAKE_TAG_REFERENCE_RESPONSE=malformed
expect_prebackup_failure "tag-reference-api-failure" FAKE_TAG_REFERENCE_RESPONSE=api-failure
expect_prebackup_failure "missing-canonical-tag" FAKE_TAG_FETCH_FAIL=true
expect_prebackup_failure "rewritten-canonical-fetch" FAKE_FETCHED_TAG_OBJECT=4444444444444444444444444444444444444444
expect_prebackup_failure "non-commit-tag" FAKE_TAG_RESOLVE_FAIL=true

rewrite_scenario="${test_root}/real-rewrite"
prepare_real_git_scenario "$rewrite_scenario" plain
rewrite_installed_commit="$real_installed_commit"
if run_updater "$rewrite_scenario" \
  FAKE_EXPECTED_TAG_OBJECT=3333333333333333333333333333333333333333 \
  FAKE_EXPECTED_TAG_TYPE=tag; then
  fail "a Git URL rewrite to an object not returned by the canonical API was accepted"
fi
[[ "$(git -C "$rewrite_scenario" rev-parse HEAD)" == "$rewrite_installed_commit" ]] || fail "a rejected Git URL rewrite changed the installed commit"
[[ ! -e "$rewrite_scenario/trace/dump" ]] || fail "a rejected Git URL rewrite reached the database backup"
[[ -z "$(git -C "$rewrite_scenario" for-each-ref --format='%(refname)' refs/queuefix-update)" ]] || fail "a rejected Git URL rewrite left its temporary reference"

race_scenario="${test_root}/real-fetch-head-race"
real_git="$(command -v git)"
prepare_real_git_scenario "$race_scenario" race
race_target_commit="$real_target_commit"
race_tag_object="$real_tag_object"
race_competing_commit="$real_installed_commit"
run_updater "$race_scenario" \
  REAL_GIT="$real_git" \
  COMPETING_COMMIT="$race_competing_commit" \
  FAKE_EXPECTED_TAG_OBJECT="$race_tag_object" \
  FAKE_EXPECTED_TAG_TYPE=tag
[[ "$(git -C "$race_scenario" rev-parse HEAD)" == "$race_target_commit" ]] || fail "a competing FETCH_HEAD update changed the checked-out release commit"
[[ "$(git -C "$race_scenario" rev-parse FETCH_HEAD)" == "$race_competing_commit" ]] || fail "the FETCH_HEAD race fixture did not overwrite FETCH_HEAD"
[[ -z "$(git -C "$race_scenario" for-each-ref --format='%(refname)' refs/queuefix-update)" ]] || fail "successful real-Git update left its temporary reference"

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
