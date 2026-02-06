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
if aws lightsail get-key-pair --key-pair-name "$KEY_PAIR_NAME" --region "$REGION" &>/dev/null; then
    echo "  Key pair '$KEY_PAIR_NAME' already exists, skipping."
else
    aws lightsail create-key-pair \
        --key-pair-name "$KEY_PAIR_NAME" \
        --region "$REGION" \
        --query 'privateKeyBase64' \
        --output text | base64 -d > ~/.ssh/${KEY_PAIR_NAME}.pem
    chmod 600 ~/.ssh/${KEY_PAIR_NAME}.pem
    echo "  ✅ Key pair created. Private key saved to ~/.ssh/${KEY_PAIR_NAME}.pem"
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
