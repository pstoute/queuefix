#!/bin/bash
# =============================================================================
# QueueFix Demo Server - Lightsail Provisioning Script
# =============================================================================
# Run this from your local machine (Mac) with AWS CLI configured.
#
# Prerequisites:
#   - AWS CLI v2 installed: brew install awscli
#   - AWS CLI configured: aws configure (or SSO)
#
# Usage:
#   chmod +x provision-demo.sh
#   ./provision-demo.sh
# =============================================================================

set -euo pipefail

# --- Configuration ---
INSTANCE_NAME="queuefix-demo"
REGION="us-east-1"
AVAILABILITY_ZONE="${REGION}a"
BLUEPRINT_ID="ubuntu_24_04"
BUNDLE_ID="medium_3_0"          # $10/mo: 2 GB RAM, 2 vCPU, 60 GB SSD
KEY_PAIR_NAME="queuefix-demo-key"
STATIC_IP_NAME="queuefix-demo-ip"
DOMAIN="queuefix.com"
SUBDOMAIN="demo"
HOSTED_ZONE_ID="/hostedzone/Z0723586JTXM68AGI4C9"

# --- Colors ---
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

echo -e "${GREEN}🚀 Provisioning QueueFix demo server on Lightsail...${NC}\n"

# --- Step 1: Create Key Pair ---
echo -e "${YELLOW}[1/6] Creating SSH key pair...${NC}"
ssh_directory="${HOME}/.ssh"
private_key_path="${ssh_directory}/${KEY_PAIR_NAME}.pem"

if [[ -L "$ssh_directory" || ( -e "$ssh_directory" && ! -d "$ssh_directory" ) ]]; then
    echo -e "${RED}Refusing to use a non-directory SSH path: ${ssh_directory}${NC}" >&2
    exit 1
fi

if [[ ! -d "$ssh_directory" ]]; then
    mkdir -m 0700 "$ssh_directory"
fi

if [[ ! -O "$ssh_directory" ]]; then
    echo -e "${RED}Refusing to use an SSH directory not owned by the current user: ${ssh_directory}${NC}" >&2
    exit 1
fi

chmod 0700 "$ssh_directory"

if aws lightsail get-key-pair --key-pair-name "$KEY_PAIR_NAME" --region "$REGION" &>/dev/null; then
    if [[ ! -f "$private_key_path" || ! -s "$private_key_path" || -L "$private_key_path" ]]; then
        echo -e "${RED}The Lightsail key pair exists, but its local private key is unavailable at ${private_key_path}.${NC}" >&2
        exit 1
    fi

    chmod 0600 "$private_key_path"
    echo "  Key pair '$KEY_PAIR_NAME' already exists, skipping."
else
    if [[ -e "$private_key_path" || -L "$private_key_path" ]]; then
        echo -e "${RED}Refusing to replace existing SSH key path: ${private_key_path}${NC}" >&2
        exit 1
    fi

    if ! command -v ssh-keygen >/dev/null 2>&1; then
        echo -e "${RED}ssh-keygen is required to validate the local private key.${NC}" >&2
        exit 1
    fi

    (
        umask 077
        encoded_key_path=''
        private_key_temp=''
        remote_key_created=false
        private_key_complete=false

        cleanup_private_key() {
            if [[ -n "$encoded_key_path" ]]; then
                rm -f "$encoded_key_path" || true
            fi

            if [[ -n "$private_key_temp" ]]; then
                rm -f "$private_key_temp" || true
            fi

            if [[ "$remote_key_created" == true && "$private_key_complete" != true ]]; then
                if ! aws lightsail delete-key-pair \
                    --key-pair-name "$KEY_PAIR_NAME" \
                    --region "$REGION" >/dev/null 2>&1; then
                    echo -e "${RED}Failed to remove the incomplete Lightsail key pair '$KEY_PAIR_NAME'; delete it before retrying.${NC}" >&2
                fi
            fi
        }

        trap cleanup_private_key EXIT
        trap 'exit 1' HUP INT QUIT TERM USR1 USR2

        encoded_key_path="$(mktemp "${ssh_directory}/.${KEY_PAIR_NAME}.encoded.XXXXXX")"
        private_key_temp="$(mktemp "${ssh_directory}/.${KEY_PAIR_NAME}.pem.XXXXXX")"
        chmod 0600 "$encoded_key_path" "$private_key_temp"

        if ! aws lightsail create-key-pair \
            --key-pair-name "$KEY_PAIR_NAME" \
            --region "$REGION" \
            --query 'privateKeyBase64' \
            --output text > "$encoded_key_path"; then
            echo -e "${RED}Failed to create the Lightsail SSH key pair.${NC}" >&2
            exit 1
        fi
        remote_key_created=true

        if ! base64 -d < "$encoded_key_path" > "$private_key_temp"; then
            echo -e "${RED}Failed to decode a complete local SSH private key.${NC}" >&2
            exit 1
        fi

        rm -f "$encoded_key_path"
        encoded_key_path=''

        if [[ ! -s "$private_key_temp" ]]; then
            echo -e "${RED}Lightsail returned an empty SSH private key.${NC}" >&2
            exit 1
        fi

        if ! ssh-keygen -y -P '' -f "$private_key_temp" >/dev/null 2>&1; then
            echo -e "${RED}Lightsail returned an invalid SSH private key.${NC}" >&2
            exit 1
        fi

        if ! ln "$private_key_temp" "$private_key_path"; then
            echo -e "${RED}Refusing to replace existing SSH key path: ${private_key_path}${NC}" >&2
            exit 1
        fi

        private_key_complete=true
        cleanup_private_key
        trap - EXIT HUP INT QUIT TERM USR1 USR2
    )

    echo "  ✅ Key pair created. Private key saved to ${private_key_path}"
fi

# --- Step 2: Create Instance ---
echo -e "${YELLOW}[2/6] Creating Lightsail instance...${NC}"
if aws lightsail get-instance --instance-name "$INSTANCE_NAME" --region "$REGION" &>/dev/null; then
    echo "  Instance '$INSTANCE_NAME' already exists, skipping."
else
    aws lightsail create-instances \
        --instance-names "$INSTANCE_NAME" \
        --availability-zone "$AVAILABILITY_ZONE" \
        --blueprint-id "$BLUEPRINT_ID" \
        --bundle-id "$BUNDLE_ID" \
        --key-pair-name "$KEY_PAIR_NAME" \
        --region "$REGION" \
        --tags key=project,value=queuefix key=environment,value=demo
    echo "  ✅ Instance created. Waiting for it to be running..."
    
    # Wait for instance to be running
    while true; do
        STATE=$(aws lightsail get-instance \
            --instance-name "$INSTANCE_NAME" \
            --region "$REGION" \
            --query 'instance.state.name' \
            --output text)
        if [ "$STATE" = "running" ]; then
            break
        fi
        echo "  Current state: $STATE. Waiting 10s..."
        sleep 10
    done
    echo "  ✅ Instance is running."
fi

# --- Step 3: Open Firewall Ports ---
echo -e "${YELLOW}[3/6] Configuring firewall (ports 22, 80, 443)...${NC}"
for PORT in 22 80 443; do
    aws lightsail open-instance-public-ports \
        --instance-name "$INSTANCE_NAME" \
        --port-info fromPort=$PORT,toPort=$PORT,protocol=tcp \
        --region "$REGION" 2>/dev/null || true
done
echo "  ✅ Firewall configured."

# --- Step 4: Allocate & Attach Static IP ---
echo -e "${YELLOW}[4/6] Setting up static IP...${NC}"
if aws lightsail get-static-ip --static-ip-name "$STATIC_IP_NAME" --region "$REGION" &>/dev/null; then
    echo "  Static IP '$STATIC_IP_NAME' already exists."
else
    aws lightsail allocate-static-ip \
        --static-ip-name "$STATIC_IP_NAME" \
        --region "$REGION"
    echo "  ✅ Static IP allocated."
fi

# Attach to instance (idempotent)
aws lightsail attach-static-ip \
    --static-ip-name "$STATIC_IP_NAME" \
    --instance-name "$INSTANCE_NAME" \
    --region "$REGION" 2>/dev/null || true

STATIC_IP=$(aws lightsail get-static-ip \
    --static-ip-name "$STATIC_IP_NAME" \
    --region "$REGION" \
    --query 'staticIp.ipAddress' \
    --output text)
echo "  ✅ Static IP: $STATIC_IP"

# --- Step 5: Create DNS Record in Route 53 ---
echo -e "${YELLOW}[5/6] Creating DNS record: ${SUBDOMAIN}.${DOMAIN} -> ${STATIC_IP}${NC}"
if [ -z "$HOSTED_ZONE_ID" ]; then
    echo -e "  ${RED}⚠️  HOSTED_ZONE_ID is not set!${NC}"
    echo "  Run this to find it:"
    echo "    aws route53 list-hosted-zones --query 'HostedZones[?Name==\`${DOMAIN}.\`].Id' --output text"
    echo "  Then set HOSTED_ZONE_ID at the top of this script and re-run."
    echo "  Skipping DNS setup for now..."
else
    aws route53 change-resource-record-sets \
        --hosted-zone-id "$HOSTED_ZONE_ID" \
        --change-batch '{
            "Changes": [{
                "Action": "UPSERT",
                "ResourceRecordSet": {
                    "Name": "'"${SUBDOMAIN}.${DOMAIN}"'",
                    "Type": "A",
                    "TTL": 300,
                    "ResourceRecords": [{"Value": "'"${STATIC_IP}"'"}]
                }
            }]
        }' \
        --query 'ChangeInfo.Id' \
        --output text
    echo "  ✅ DNS record created/updated."
fi

# --- Step 6: Summary ---
echo ""
echo -e "${GREEN}============================================${NC}"
echo -e "${GREEN}  QueueFix Demo Server Provisioned! 🎉${NC}"
echo -e "${GREEN}============================================${NC}"
echo ""
echo "  Instance:   $INSTANCE_NAME"
echo "  Static IP:  $STATIC_IP"
echo "  DNS:        ${SUBDOMAIN}.${DOMAIN} (may take a few minutes to propagate)"
echo ""
echo "  SSH into the server:"
echo "    ssh -i ~/.ssh/${KEY_PAIR_NAME}.pem ubuntu@${STATIC_IP}"
echo ""
echo "  Next step: Run the server setup script on the instance:"
echo "    scp -i ~/.ssh/${KEY_PAIR_NAME}.pem setup-server.sh ubuntu@${STATIC_IP}:~/"
echo "    ssh -i ~/.ssh/${KEY_PAIR_NAME}.pem ubuntu@${STATIC_IP} 'chmod +x setup-server.sh && ./setup-server.sh'"
echo ""
