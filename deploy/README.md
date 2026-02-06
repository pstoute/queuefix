# QueueFix Demo Deployment Guide

## Quick Overview

Two scripts handle everything:

1. **`provision-demo.sh`** — Run from your Mac. Creates the Lightsail instance, static IP, firewall rules, and DNS record.
2. **`setup-server.sh`** — Run on the server. Installs Docker, Caddy, clones the repo, configures everything, and starts the app.

## Prerequisites

- AWS CLI v2 installed and configured (`brew install awscli && aws configure`)
- Route 53 hosted zone for `queuefix.com`

## Step-by-Step

### 1. Get your Route 53 Hosted Zone ID

```bash
aws route53 list-hosted-zones --query 'HostedZones[?Name==`queuefix.com.`].Id' --output text
```

This returns something like `/hostedzone/Z1234567890`. Copy just the ID part: `Z1234567890`.

### 2. Edit the provisioning script

Open `deploy/provision-demo.sh` and set:
- `HOSTED_ZONE_ID="Z1234567890"` (your actual zone ID)
- `REGION` — change if you don't want us-east-1
- `REPO_URL` in `setup-server.sh` — your actual GitHub repo URL

### 3. Provision the Lightsail instance

```bash
cd /Users/paulstoute/Sites/queuefix/deploy
chmod +x provision-demo.sh
./provision-demo.sh
```

This takes ~2 minutes. At the end it prints the static IP and SSH command.

### 4. Setup the server

```bash
# Copy the setup script to the server
scp -i ~/.ssh/queuefix-demo-key.pem setup-server.sh ubuntu@<STATIC_IP>:~/

# SSH in and run it
ssh -i ~/.ssh/queuefix-demo-key.pem ubuntu@<STATIC_IP> 'chmod +x setup-server.sh && ./setup-server.sh'
```

This takes ~5 minutes. It installs Docker, Caddy, clones the repo, builds containers, runs migrations, seeds demo data, and sets up the cron.

### 5. Verify

- Visit `https://demo.queuefix.com` — should show the QueueFix login page with demo credentials
- SSL should auto-provision via Caddy (give it 30 seconds on first hit)
- Try logging in as admin, agent, and customer

## Architecture

```
Internet → Caddy (:443, auto-SSL) → Laravel App (:8000)
                                      ├── PostgreSQL (:5432)
                                      ├── Redis (:6379)
                                      └── Queue Worker
```

## Maintenance

```bash
# SSH into server
ssh -i ~/.ssh/queuefix-demo-key.pem ubuntu@<STATIC_IP>

# View app logs
cd /opt/queuefix && docker compose logs -f

# Manual demo reset
docker compose exec app php artisan demo:reset

# Pull latest code and redeploy
cd /opt/queuefix && git pull && docker compose up -d --build
docker compose exec app php artisan migrate --force

# View reset cron log
tail -f /var/log/queuefix-demo-reset.log

# Restart everything
cd /opt/queuefix && docker compose restart
```

## Cost

- Lightsail instance: **$10/month**
- Static IP: **Free** (while attached to running instance)
- Route 53: **$0.50/month** per hosted zone + negligible query costs
- SSL: **Free** via Let's Encrypt / Caddy
- **Total: ~$10.50/month**

## Teardown

```bash
aws lightsail delete-instance --instance-name queuefix-demo --region us-east-1
aws lightsail release-static-ip --static-ip-name queuefix-demo-ip --region us-east-1
```
