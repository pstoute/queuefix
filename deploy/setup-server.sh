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
DOCKER_NETWORK_SUBNET="172.30.255.0/28"
DOCKER_NETWORK_GATEWAY="172.30.255.1"

# --- Colors ---
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

install_docker() {
    if docker --version &>/dev/null; then
        echo "  Docker already installed, skipping."
        return
    fi

    sudo apt-get install -y -qq docker.io
    sudo usermod -aG docker ubuntu
    docker --version >/dev/null
    echo "  ✅ Docker installed."
}

install_docker_compose() {
    if docker compose version &>/dev/null; then
        echo "  Docker Compose already available."
        return
    fi

    sudo apt-get install -y -qq docker-compose-v2
    docker compose version >/dev/null
    echo "  ✅ Docker Compose installed."
}

install_caddy() {
    if caddy version &>/dev/null; then
        echo "  Caddy already installed, skipping."
        return
    fi

    sudo apt-get install -y -qq caddy
    caddy version >/dev/null
    echo "  ✅ Caddy installed."
}

configure_demo_environment() (
    # Environment files contain the application key and provider credentials.
    # Keep every creation and replacement private from the first write onward.
    umask 077

    if [ -L .env ] || { [ -e .env ] && [ ! -f .env ]; }; then
        echo "Refusing to write secrets through a non-regular .env target." >&2
        return 1
    fi

    if [ -e .env.demo ] && [ ! -f .env.demo ] && [ ! -L .env.demo ]; then
        echo "Refusing to remove a non-regular legacy .env.demo target." >&2
        return 1
    fi

    # Older setup runs left this unignored secret-bearing overlay behind.
    # It has no runtime consumer once its values are merged into .env.
    rm -f -- .env.demo

    if [ -f .env ]; then
        chmod 0600 .env
    fi

    local cleanup_command
    local environment_overrides
    local final_environment
    environment_overrides="$(mktemp .env.overrides.XXXXXX)"
    printf -v cleanup_command 'rm -f -- %q' "$environment_overrides"
    trap "$cleanup_command" EXIT
    final_environment="$(mktemp .env.next.XXXXXX)"
    printf -v cleanup_command 'rm -f -- %q %q' "$environment_overrides" "$final_environment"
    trap "$cleanup_command" EXIT

    # Keep .env.example as the base and apply demo values directly so no
    # second secret-bearing file is persisted in the checkout.
    cat > "$environment_overrides" <<EOF
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
DB_PASSWORD=

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
SESSION_SECURE_COOKIE=true

# Reverse proxy boundary (Caddy reaches the container through this gateway)
QUEUEFIX_NETWORK_SUBNET=${DOCKER_NETWORK_SUBNET}
QUEUEFIX_NETWORK_GATEWAY=${DOCKER_NETWORK_GATEWAY}
TRUSTED_PROXY_REQUIRED=true
TRUSTED_PROXIES=${DOCKER_NETWORK_GATEWAY}
EOF

    awk '
        FNR == NR {
            separator = index($0, "=")
            if ($0 !~ /^#/ && separator > 1) {
                key = substr($0, 1, separator - 1)
                override[key] = $0
                order[++count] = key
            }
            next
        }
        {
            separator = index($0, "=")
            if (separator > 1) {
                key = substr($0, 1, separator - 1)
                if (key in override) {
                    next
                }
            }
            print
        }
        END {
            for (i = 1; i <= count; i++) {
                print override[order[i]]
            }
        }
    ' "$environment_overrides" .env.example > "$final_environment"

    chmod 0600 "$final_environment"
    mv -f "$final_environment" .env
    chmod 0600 .env
    rm -f -- "$environment_overrides"
    trap - EXIT
)

main() {
echo -e "${GREEN}🔧 Setting up QueueFix demo server...${NC}\n"

# --- Step 1: System Updates ---
echo -e "${YELLOW}[1/7] Updating system packages...${NC}"
sudo apt-get update -qq
sudo apt-get upgrade -y -qq
echo "  ✅ System updated."

# --- Step 2: Install Docker ---
echo -e "${YELLOW}[2/7] Installing Docker...${NC}"
install_docker

# --- Step 3: Install Docker Compose ---
echo -e "${YELLOW}[3/7] Installing Docker Compose...${NC}"
install_docker_compose

# --- Step 4: Install Caddy (reverse proxy + auto-SSL) ---
echo -e "${YELLOW}[4/7] Installing Caddy...${NC}"
install_caddy

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

configure_demo_environment

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
        Strict-Transport-Security "max-age=31536000"
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
echo "  🔐 Admin:    admin@example.com / password"
echo "  👤 Agent:    sarah@example.com / password"
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
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
    main "$@"
fi
