#!/usr/bin/env bash

set -euo pipefail

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
provisioner="${repository_root}/deploy/provision-demo.sh"

fail() {
    echo "Provisioning private-key regression failed: $*" >&2
    exit 1
}

mode_of() {
    if stat -c '%a' "$1" >/dev/null 2>&1; then
        stat -c '%a' "$1"
    else
        stat -f '%Lp' "$1"
    fi
}

run_provisioner() {
    local scenario="$1"
    local requested_umask="$2"
    local remote_exists="$3"
    local create_failure="$4"
    local invalid_base64="$5"
    local kill_creator="${6:-false}"
    local invalid_private_key="${7:-false}"

    mkdir -p "$scenario/home"
    : > "$scenario/trace"

    set +e
    (
        umask "$requested_umask"
        export HOME="$scenario/home"
        export PATH="$test_root/fake-bin:$PATH"
        export PROVISION_TEST_TRACE="$scenario/trace"
        export PROVISION_TEST_KEY_BASE64="$encoded_private_key"
        export PROVISION_TEST_REMOTE_EXISTS="$remote_exists"
        export PROVISION_TEST_CREATE_FAILURE="$create_failure"
        export PROVISION_TEST_INVALID_BASE64="$invalid_base64"
        export PROVISION_TEST_KILL_CREATOR="$kill_creator"
        export PROVISION_TEST_INVALID_PRIVATE_KEY="$invalid_private_key"
        export PROVISION_TEST_REMOTE_STATE="$scenario/remote-state"

        bash "$provisioner"
    ) > "$scenario/stdout" 2> "$scenario/stderr"
    scenario_status=$?
    set -e
}

bash -n "$provisioner"

test_root="$(mktemp -d "${TMPDIR:-/tmp}/queuefix-provision-key-test.XXXXXX")"
trap 'rm -rf "$test_root"' EXIT
mkdir -p "$test_root/fake-bin"

cat > "$test_root/fake-bin/aws" <<'EOF'
#!/usr/bin/env bash

set -euo pipefail

printf 'aws %s\n' "$*" >> "${PROVISION_TEST_TRACE}"

if [[ "$*" == "lightsail get-key-pair --key-pair-name queuefix-demo-key --region us-east-1" ]]; then
    [[ "${PROVISION_TEST_REMOTE_EXISTS}" == true || -e "${PROVISION_TEST_REMOTE_STATE}" ]]
    exit
fi

if [[ "$*" == *"lightsail create-key-pair"* ]]; then
    if [[ "${PROVISION_TEST_CREATE_FAILURE}" == true ]]; then
        printf '%s' 'partial-key'
        exit 42
    fi

    if [[ "${PROVISION_TEST_KILL_CREATOR}" == true ]]; then
        : > "${PROVISION_TEST_REMOTE_STATE}"
        printf '%s' 'partial-encoded-key'
        kill -KILL "$PPID"
        sleep 1
        exit
    fi

    : > "${PROVISION_TEST_REMOTE_STATE}"

    if [[ "${PROVISION_TEST_INVALID_BASE64}" == true ]]; then
        printf '%s\n' 'not-valid-base64!'
        exit
    fi

    if [[ "${PROVISION_TEST_INVALID_PRIVATE_KEY}" == true ]]; then
        printf '%s\n' 'bm90LWFuLXNzaC1wcml2YXRlLWtleQo='
        exit
    fi

    printf '%s\n' "${PROVISION_TEST_KEY_BASE64}"
    exit
fi

if [[ "$*" == "lightsail delete-key-pair --key-pair-name queuefix-demo-key --region us-east-1" ]]; then
    rm -f "${PROVISION_TEST_REMOTE_STATE}"
    exit
fi

if [[ "$*" == *"staticIp.ipAddress"* ]]; then
    printf '%s\n' '192.0.2.10'
fi
EOF

chmod +x "$test_root/fake-bin/aws"

ssh-keygen -q -t ed25519 -N '' -f "$test_root/source-key"
private_key_contents="$(cat "$test_root/source-key")"$'\n'
encoded_private_key="$(printf '%s' "$private_key_contents" | base64 | tr -d '\n')"

success="$test_root/success"
mkdir -p "$success/home/.ssh"
chmod 0777 "$success/home/.ssh"
run_provisioner "$success" 000 false false false

[[ "$scenario_status" == 0 ]] || fail "valid key creation failed"
[[ -d "$success/home/.ssh" && ! -L "$success/home/.ssh" ]] || fail "the SSH directory was not created as a real directory"
[[ "$(mode_of "$success/home/.ssh")" == 700 ]] || fail "the SSH directory was not owner-only"
[[ -f "$success/home/.ssh/queuefix-demo-key.pem" && ! -L "$success/home/.ssh/queuefix-demo-key.pem" ]] || fail "the private key was not created as a regular file"
[[ "$(mode_of "$success/home/.ssh/queuefix-demo-key.pem")" == 600 ]] || fail "the private key was not owner-only"
[[ "$(cat "$success/home/.ssh/queuefix-demo-key.pem")"$'\n' == "$private_key_contents" ]] || fail "the decoded private key contents changed"
[[ -z "$(find "$success/home/.ssh" -maxdepth 1 -type f ! -name 'queuefix-demo-key.pem' -print -quit)" ]] || fail "successful key creation left a temporary file"
grep -Fq 'aws lightsail create-key-pair --key-pair-name queuefix-demo-key' "$success/trace" || fail "the expected Lightsail key pair was not created"
grep -Fq 'ssh -i ~/.ssh/queuefix-demo-key.pem ubuntu@192.0.2.10' "$success/stdout" || fail "the SSH command path changed"
grep -Fq 'scp -i ~/.ssh/queuefix-demo-key.pem setup-server.sh ubuntu@192.0.2.10:~/' "$success/stdout" || fail "the SCP command path changed"

regular="$test_root/regular"
mkdir -p "$regular/home/.ssh"
printf '%s\n' 'preserve-existing-key' > "$regular/home/.ssh/queuefix-demo-key.pem"
chmod 0644 "$regular/home/.ssh/queuefix-demo-key.pem"
run_provisioner "$regular" 022 false false false

[[ "$scenario_status" != 0 ]] || fail "an existing regular key file was replaced"
grep -qx 'preserve-existing-key' "$regular/home/.ssh/queuefix-demo-key.pem" || fail "an existing regular key file was modified"
[[ "$(mode_of "$regular/home/.ssh/queuefix-demo-key.pem")" == 644 ]] || fail "an existing regular key file mode was modified"
if grep -Fq 'aws lightsail create-key-pair' "$regular/trace"; then
    fail "the remote key was created before detecting an existing local file"
fi

linked="$test_root/linked"
mkdir -p "$linked/home/.ssh"
printf '%s\n' 'outside-target' > "$linked/outside"
ln -s "$linked/outside" "$linked/home/.ssh/queuefix-demo-key.pem"
run_provisioner "$linked" 022 false false false

[[ "$scenario_status" != 0 ]] || fail "a private-key symlink was accepted"
grep -qx 'outside-target' "$linked/outside" || fail "a private-key symlink target was modified"
[[ -L "$linked/home/.ssh/queuefix-demo-key.pem" ]] || fail "a rejected private-key symlink was replaced"
if grep -Fq 'aws lightsail create-key-pair' "$linked/trace"; then
    fail "the remote key was created before detecting a symlink"
fi

dangling="$test_root/dangling"
mkdir -p "$dangling/home/.ssh"
ln -s "$dangling/missing-target" "$dangling/home/.ssh/queuefix-demo-key.pem"
run_provisioner "$dangling" 022 false false false

[[ "$scenario_status" != 0 ]] || fail "a dangling private-key symlink was accepted"
[[ -L "$dangling/home/.ssh/queuefix-demo-key.pem" ]] || fail "a rejected dangling symlink was replaced"
[[ ! -e "$dangling/missing-target" ]] || fail "a dangling private-key symlink target was created"
if grep -Fq 'aws lightsail create-key-pair' "$dangling/trace"; then
    fail "the remote key was created before detecting a dangling symlink"
fi

linked_ssh_directory="$test_root/linked-ssh-directory"
mkdir -p "$linked_ssh_directory/home" "$linked_ssh_directory/outside-ssh"
printf '%s\n' 'outside-directory-state' > "$linked_ssh_directory/outside-ssh/sentinel"
ln -s "$linked_ssh_directory/outside-ssh" "$linked_ssh_directory/home/.ssh"
run_provisioner "$linked_ssh_directory" 022 false false false

[[ "$scenario_status" != 0 ]] || fail "a symlinked SSH directory was accepted"
[[ -L "$linked_ssh_directory/home/.ssh" ]] || fail "a rejected SSH directory symlink was replaced"
grep -qx 'outside-directory-state' "$linked_ssh_directory/outside-ssh/sentinel" || fail "a symlinked SSH directory was modified"
if grep -Fq 'aws lightsail create-key-pair' "$linked_ssh_directory/trace"; then
    fail "the remote key was created before detecting a symlinked SSH directory"
fi

aws_failure="$test_root/aws-failure"
mkdir -p "$aws_failure"
run_provisioner "$aws_failure" 000 false true false

[[ "$scenario_status" != 0 ]] || fail "an AWS key creation failure was ignored"
[[ "$(mode_of "$aws_failure/home/.ssh")" == 700 ]] || fail "an AWS failure left the SSH directory permissive"
[[ ! -e "$aws_failure/home/.ssh/queuefix-demo-key.pem" && ! -L "$aws_failure/home/.ssh/queuefix-demo-key.pem" ]] || fail "an AWS failure left partial key material"
[[ -z "$(find "$aws_failure/home/.ssh" -maxdepth 1 -type f -print -quit)" ]] || fail "an AWS failure left a temporary private-key file"

invalid_base64="$test_root/invalid-base64"
mkdir -p "$invalid_base64"
run_provisioner "$invalid_base64" 000 false false true

[[ "$scenario_status" != 0 ]] || fail "invalid Base64 key material was accepted"
[[ ! -e "$invalid_base64/home/.ssh/queuefix-demo-key.pem" && ! -L "$invalid_base64/home/.ssh/queuefix-demo-key.pem" ]] || fail "invalid Base64 left partial key material"
[[ -z "$(find "$invalid_base64/home/.ssh" -maxdepth 1 -type f -print -quit)" ]] || fail "invalid Base64 left a temporary private-key file"
[[ ! -e "$invalid_base64/remote-state" ]] || fail "invalid Base64 left an unusable remote key pair"
grep -Fq 'aws lightsail delete-key-pair --key-pair-name queuefix-demo-key --region us-east-1' "$invalid_base64/trace" || fail "invalid Base64 did not remove the unusable remote key pair"

run_provisioner "$invalid_base64" 000 false false false

[[ "$scenario_status" == 0 ]] || fail "a handled local key failure could not be retried"
[[ -s "$invalid_base64/home/.ssh/queuefix-demo-key.pem" ]] || fail "a retry did not create the recovered local key"
[[ "$(mode_of "$invalid_base64/home/.ssh/queuefix-demo-key.pem")" == 600 ]] || fail "a retry created a permissive local key"

invalid_private_key="$test_root/invalid-private-key"
mkdir -p "$invalid_private_key"
run_provisioner "$invalid_private_key" 000 false false false false true

[[ "$scenario_status" != 0 ]] || fail "invalid SSH private-key material was accepted"
[[ ! -e "$invalid_private_key/home/.ssh/queuefix-demo-key.pem" && ! -L "$invalid_private_key/home/.ssh/queuefix-demo-key.pem" ]] || fail "invalid SSH private-key material reached the final path"
[[ ! -e "$invalid_private_key/remote-state" ]] || fail "invalid SSH private-key material left an unusable remote key pair"
grep -Fq 'aws lightsail delete-key-pair --key-pair-name queuefix-demo-key --region us-east-1' "$invalid_private_key/trace" || fail "invalid SSH private-key material did not remove the unusable remote key pair"

killed_creation="$test_root/killed-creation"
mkdir -p "$killed_creation"
run_provisioner "$killed_creation" 000 false false false true

[[ "$scenario_status" != 0 ]] || fail "an untrappable creation interruption was ignored"
[[ ! -e "$killed_creation/home/.ssh/queuefix-demo-key.pem" && ! -L "$killed_creation/home/.ssh/queuefix-demo-key.pem" ]] || fail "an untrappable interruption exposed partial data at the final key path"
[[ -e "$killed_creation/remote-state" ]] || fail "the interruption scenario did not model a created remote key"
while IFS= read -r leftover_key_file; do
    [[ "$(mode_of "$leftover_key_file")" == 600 ]] || fail "an untrappable interruption left permissive temporary key material"
done < <(find "$killed_creation/home/.ssh" -maxdepth 1 -type f -print)

run_provisioner "$killed_creation" 000 false false false

[[ "$scenario_status" != 0 ]] || fail "a remote key left by an untrappable interruption was silently accepted"
if grep -Fq 'aws lightsail get-instance' "$killed_creation/trace"; then
    fail "instance provisioning continued after an interrupted key creation"
fi

remote_exists="$test_root/remote-exists"
mkdir -p "$remote_exists/home/.ssh"
cp "$test_root/source-key" "$remote_exists/home/.ssh/queuefix-demo-key.pem"
chmod 0644 "$remote_exists/home/.ssh/queuefix-demo-key.pem"
run_provisioner "$remote_exists" 022 true false false

[[ "$scenario_status" == 0 ]] || fail "an existing remote key no longer skips local creation"
cmp -s "$test_root/source-key" "$remote_exists/home/.ssh/queuefix-demo-key.pem" || fail "the remote-key skip path modified local contents"
[[ "$(mode_of "$remote_exists/home/.ssh/queuefix-demo-key.pem")" == 600 ]] || fail "the remote-key skip path left permissive local permissions"
if grep -Fq 'aws lightsail create-key-pair' "$remote_exists/trace"; then
    fail "the remote-key skip path created a replacement"
fi

missing_local_key="$test_root/missing-local-key"
mkdir -p "$missing_local_key"
run_provisioner "$missing_local_key" 022 true false false

[[ "$scenario_status" != 0 ]] || fail "an existing remote key without a local private key was accepted"
if grep -Fq 'aws lightsail get-instance' "$missing_local_key/trace"; then
    fail "instance provisioning continued without a usable local private key"
fi

echo "Provisioning private-key regression passed."
