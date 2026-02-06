#!/bin/bash
# =============================================================================
# QueueFix Demo Server - Server Setup Script
# =============================================================================
# Run this ON the Lightsail instance after provisioning.
# It installs Docker, Caddy, clones the repo, and starts everything.
#
# Usage (from your Mac):
#   scp -i ~/.ssh/queuefix-demo-key.pem setup-server.sh ubuntu@<STATIC_IP>:~/
#   ssh -i ~/.ssh/queuefix-demo-key.pem ubuntu@<STATIC_IP> 'chmod +x setup-server.sh && ./setup-server.sh'
# =============================================================================

set -euo pipefail

DOMAIN="demo.queuefix.com"
APP_DIR="/opt/queuefix"
REPO_URL="https://github.com/pstoute/queuefix.git"
RESET_INTERVAL=120  # minutes

# --- Colors ---
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

echo -e "${GREEN}🔧 Setting up QueueFix demo server...${NC}\n"

# --- Step 1: System Updates ---
echo -e "${YELLOW}[1/7] Updating system packages...${NC}"
sudo apt-get update -qq
sudo apt-get upgrade -y -qq
echo "  ✅ System updated."

# --- Step 2: Install Docker ---
echo -e "${YELLOW}[2/7] Installing Docker...${NC}"
if command -v docker &>/dev/null; then
    echo "  Docker already installed, skipping."
else
    curl -fsSL https://get.docker.com | sudo sh
    sudo usermod -aG docker ubuntu
    echo "  ✅ Docker installed."
fi

# --- Step 3: Install Docker Compose ---
echo -e "${YELLOW}[3/7] Installing Docker Compose...${NC}"
if command -v docker compose &>/dev/null; then
    echo "  Docker Compose already available."
else
    sudo apt-get install -y -qq docker-compose-plugin
    echo "  ✅ Docker Compose installed."
fi

# --- Step 4: Install Caddy (reverse proxy + auto-SSL) ---
echo -e "${YELLOW}[4/7] Installing Caddy...${NC}"
if command -v caddy &>/dev/null; then
    echo "  Caddy already installed, skipping."
else
    sudo apt-get install -y -qq debian-keyring debian-archive-keyring apt-transport-https
    curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | sudo gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
    curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | sudo tee /etc/apt/sources.list.d/caddy-stable.list
    sudo apt-get update -qq
    sudo apt-get install -y -qq caddy
    echo "  ✅ Caddy installed."
fi

# --- Step 5: Clone Repo & Configure ---
echo -e "${YELLOW}[5/7] Setting up application...${NC}"
sudo mkdir -p "$APP_DIR"
sudo chown ubuntu:ubuntu "$APP_DIR"

if [ -d "$APP_DIR/.git" ]; then
    echo "  Repo already cloned, pulling latest..."
    cd "$APP_DIR" && git pull
else
    git clone "$REPO_URL" "$APP_DIR"
fi

cd "$APP_DIR"

# Create .env from example if it doesn't exist
if [ ! -f .env ]; then
    cp .env.example .env
fi

# Configure demo-specific environment variables
# (These override/append to whatever .env.example provides)
cat > .env.demo <<EOF
APP_NAME=QueueFix
APP_ENV=production
APP_DEBUG=false
APP_URL=https://${DOMAIN}

# Demo mode
QUEUEFIX_DEMO_MODE=true
QUEUEFIX_DEMO_RESET_INTERVAL_MINUTES=${RESET_INTERVAL}

# Database (matches docker-compose service)
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=queuefix
DB_USERNAME=queuefix
DB_PASSWORD=$(openssl rand -base64 24 | tr -dc 'a-zA-Z0-9' | head -c 32)

# Redis
REDIS_HOST=redis
REDIS_PORT=6379

# Mail (log driver in demo mode - no emails sent)
MAIL_MAILER=log

# Queue
QUEUE_CONNECTION=database

# Session
SESSION_DRIVER=database
SESSION_LIFETIME=120
EOF

# Merge demo env into .env (demo values override)
# Keep .env.example values as base, overlay with demo specifics
cp .env.example .env 2>/dev/null || true
while IFS= read -r line; do
    key=$(echo "$line" | cut -d= -f1)
    if [ -n "$key" ] && [[ ! "$line" =~ ^# ]]; then
        # Remove existing key from .env, then append new value
        sed -i "/^${key}=/d" .env 2>/dev/null || true
        echo "$line" >> .env
    fi
done < .env.demo

echo "  ✅ Application configured."

# --- Step 6: Configure Caddy ---
echo -e "${YELLOW}[6/7] Configuring Caddy reverse proxy...${NC}"
sudo tee /etc/caddy/Caddyfile > /dev/null <<EOF
${DOMAIN} {
    reverse_proxy localhost:8000

    header {
        # Security headers
        X-Content-Type-Options "nosniff"
        X-Frame-Options "SAMEORIGIN"
        Referrer-Policy "strict-origin-when-cross-origin"
        X-XSS-Protection "1; mode=block"
    }

    # Gzip
    encode gzip

    log {
        output file /var/log/caddy/queuefix-access.log
    }
}
EOF

sudo mkdir -p /var/log/caddy
sudo systemctl restart caddy
sudo systemctl enable caddy
echo "  ✅ Caddy configured with auto-SSL for ${DOMAIN}."

# --- Step 7: Start the Application ---
echo -e "${YELLOW}[7/7] Starting QueueFix via Docker Compose...${NC}"
cd "$APP_DIR"

# Build and start (detached)
docker compose -f docker-compose.yml up -d --build

# Wait for containers to be healthy
echo "  Waiting for containers to start..."
sleep 15

# Run migrations and seed demo data
docker compose exec -T app php artisan migrate --force
docker compose exec -T app php artisan db:seed --class=DemoSeeder --force
docker compose exec -T app php artisan key:generate --force

echo "  ✅ Application started."

# --- Step 8: Setup Demo Reset Cron ---
echo -e "${YELLOW}[Bonus] Setting up demo reset cron...${NC}"
CRON_CMD="cd ${APP_DIR} && docker compose exec -T app php artisan demo:reset >> /var/log/queuefix-demo-reset.log 2>&1"

# Add cron job (every 2 hours)
(crontab -l 2>/dev/null | grep -v "demo:reset"; echo "0 */${RESET_INTERVAL%?} * * * ${CRON_CMD}") | crontab -

# For 120 minutes = every 2 hours
(crontab -l 2>/dev/null | grep -v "demo:reset"; echo "0 */2 * * * ${CRON_CMD}") | crontab -

echo "  ✅ Demo reset scheduled every ${RESET_INTERVAL} minutes."

# --- Done ---
echo ""
echo -e "${GREEN}============================================${NC}"
echo -e "${GREEN}  QueueFix Demo Server is LIVE! 🎉${NC}"
echo -e "${GREEN}============================================${NC}"
echo ""
echo "  🌐 URL:      https://${DOMAIN}"
echo "  🔐 Admin:    admin@demo.queuefix.com / demo"
echo "  👤 Agent:    agent@demo.queuefix.com / demo"
echo "  🙋 Customer: customer@demo.queuefix.com / demo"
echo ""
echo "  📋 Useful commands:"
echo "    cd ${APP_DIR}"
echo "    docker compose logs -f          # View logs"
echo "    docker compose exec app bash    # Shell into app"
echo "    docker compose restart          # Restart services"
echo "    docker compose exec app php artisan demo:reset  # Manual reset"
echo ""
echo "  SSL certificate will auto-provision via Caddy on first request."
echo "  Make sure DNS for ${DOMAIN} points to this server's IP."
echo ""
