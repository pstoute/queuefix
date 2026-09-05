# QueueFix

A modern, open-source support ticketing system with a powerful email importer. Built with Laravel 12, React 19, and PostgreSQL (MySQL also supported).

QueueFix does **one thing well: support tickets.** No bloat, no unnecessary features — just a clean, fast ticketing system that your team will actually enjoy using.

[![Laravel Forge Site Deployment Status](https://img.shields.io/endpoint?url=https%3A%2F%2Fforge.laravel.com%2Fsite-badges%2Fa8c339b1-c9d3-4f1d-81c7-78851f7b4408&style=plastic)](https://forge.laravel.com/paul-stoute/limitless-brook-aas/3033773)

## Features

- **Email Import** — Connect via IMAP, Gmail API, or Microsoft Graph. Automatically creates tickets from incoming emails with smart threading.
- **Modern UI** — Clean, responsive interface built with React and shadcn/ui. Dark mode included.
- **SLA Tracking** — Configurable SLA policies with real-time breach indicators.
- **Multi-Mailbox** — Connect multiple mailboxes (support@, billing@, sales@) each mapped to departments.
- **Customer Portal** — Optional self-service portal where customers can view and reply to tickets.
- **Canned Responses** — Save reply templates with variable substitution.
- **Tags & Labels** — Organize tickets with color-coded tags.
- **OAuth Login** — Sign in with Google or Microsoft, plus magic link (passwordless) authentication.

## Requirements

- PHP 8.3+
- PostgreSQL 16+ **or** MySQL 8.0+
- Node.js 22+
- Composer
- pnpm

## Quick Start with Docker

```bash
git clone https://github.com/yourusername/queuefix.git
cd queuefix
cp .env.example .env
docker compose build
docker compose run --rm --no-deps app php artisan key:generate
docker compose run --rm migrate
docker compose run --rm --no-deps app php artisan queuefix:bootstrap-admin
docker compose run --rm --no-deps app pnpm build
docker compose up -d
docker compose ps
```

Then open http://localhost:8000. Mailpit's development inbox is available only from the Docker host at http://127.0.0.1:8025.

The one-off setup commands generate the application key, create the database, prompt for a unique administrator credential, and compile the frontend assets. On every startup, Compose safely runs any pending database migrations to completion before the web, queue, and scheduler services start. The background services restart automatically after transient failures and after the queue worker's hourly recycle. The web and Vite ports are bound to the Docker host's loopback interface by default.

For a disposable demo instead of a normal installation, set `QUEUEFIX_DEMO_MODE=true` in `.env`, start from an empty database, and replace the administrator bootstrap command above with:

```bash
docker compose run --rm --no-deps app php artisan db:seed --class=DemoSeeder --force
```

Demo mode intentionally creates the credentials displayed on the login screen. The demo seeder refuses to run unless demo mode is explicit or when staff users already exist.

## Manual Installation

```bash
# Clone the repository
git clone https://github.com/yourusername/queuefix.git
cd queuefix

# Install PHP dependencies
composer install

# Install JavaScript dependencies
pnpm install

# Configure environment
cp .env.example .env
php artisan key:generate

# Edit .env with your database credentials
# PostgreSQL (default):
# DB_CONNECTION=pgsql
# DB_HOST=127.0.0.1
# DB_PORT=5432
# DB_DATABASE=queuefix
# DB_USERNAME=your_user
# DB_PASSWORD=your_password
#
# MySQL (alternative):
# DB_CONNECTION=mysql
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_DATABASE=queuefix
# DB_USERNAME=your_user
# DB_PASSWORD=your_password

# Run migrations and create the first administrator
php artisan migrate
php artisan queuefix:bootstrap-admin

# Build frontend assets
pnpm build

# Start the server
php artisan serve

# In a separate terminal, start the queue worker
php artisan queue:work

# In a separate terminal, start the scheduler
php artisan schedule:work
```

To use sample data for a disposable local demo instead, set `QUEUEFIX_DEMO_MODE=true`, start from an empty database, and run `php artisan migrate --seed --seeder=DemoSeeder`. Do not run the demo seeder on an existing installation.

## Email Provider Setup

### Generic IMAP/SMTP

1. Go to **Settings > Mailboxes > Add Mailbox**
2. Select type: **IMAP**
3. Enter your IMAP server details (host, port, encryption)
4. Enter SMTP server details for outbound replies
5. Provide your email credentials
6. Test the connection

### Google Workspace (Gmail API)

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project or select existing
3. Enable the **Gmail API**
4. Go to **Credentials > Create Credentials > OAuth 2.0 Client ID**
5. Set the authorized redirect URI to: `https://your-domain.com/settings/mailboxes/gmail/callback`
6. Copy the Client ID and Secret
7. Add them to your `.env`:
   ```
   GOOGLE_GMAIL_CLIENT_ID=your_client_id
   GOOGLE_GMAIL_CLIENT_SECRET=your_client_secret
   ```
8. In QueueFix, go to **Settings > Mailboxes > Add Mailbox** and select **Gmail**

### Microsoft Office 365 (Graph API)

1. Go to [Azure Portal](https://portal.azure.com/)
2. Navigate to **Azure Active Directory > App registrations > New registration**
3. Set the redirect URI to: `https://your-domain.com/settings/mailboxes/microsoft/callback`
4. Under **API permissions**, add:
   - `Mail.Read`
   - `Mail.Send`
   - `Mail.ReadWrite`
5. Create a client secret under **Certificates & secrets**
6. Add credentials to your `.env`:
   ```
   MICROSOFT_GRAPH_CLIENT_ID=your_client_id
   MICROSOFT_GRAPH_CLIENT_SECRET=your_client_secret
   MICROSOFT_GRAPH_TENANT_ID=your_tenant_id
   ```
7. In QueueFix, go to **Settings > Mailboxes > Add Mailbox** and select **Microsoft**

### AWS WorkMail

AWS WorkMail supports standard IMAP/SMTP. Use the **Generic IMAP** option with:
- IMAP Host: `imap.mail.us-east-1.awsapps.com` (adjust region)
- IMAP Port: `993`
- SMTP Host: `smtp.mail.us-east-1.awsapps.com`
- SMTP Port: `465`

### Secure email reply threading

For each mailbox, configure a **Secure Reply Address Template** containing exactly one
`{token}` placeholder, for example `support+{token}@example.com`. The resulting
addresses must be accepted by your mail provider and routed back to that mailbox
without rewriting the recipient address.

QueueFix places the generated address in the provider-native `Reply-To` field for
outbound ticket replies. A later inbound message joins an existing ticket only when
it carries that unrevoked capability and the sender address matches the ticket's
customer. Visible sender names, `From` addresses, subject ticket numbers, and message
thread headers do not authorize ticket mutation by themselves. If the template is
missing or a capability is invalid, the message safely starts a new ticket.

Each ticket's random capability is stable for normal replies, encrypted at rest, and
rotated if it is revoked or moved to a different mailbox. Treat reply addresses as
secrets and avoid publishing them outside the intended email conversation.

### Inbound retry safety

Mailbox polls use bounded batches and keep a database-backed lease for every
provider message while its processing job is pending. A message that reaches
`INBOUND_EMAIL_MAX_FAILURE_COUNT` final job failures is left unread but is no
longer dispatched automatically. After correcting the underlying provider or
message problem, an operator can explicitly allow that identity to be polled
again:

```bash
php artisan queuefix:retry-inbound-email support@example.com 'gmail:provider-message-id'
```

Use the stable provider identity recorded in the failed job or application log.

## OAuth Login Setup (for Agents)

OAuth login is available only to active staff accounts that an administrator has already created in **Settings > Users**.

### Google OAuth

1. In Google Cloud Console, create OAuth 2.0 credentials
2. Set redirect URI to: `https://your-domain.com/auth/google/callback`
3. Add to `.env`:
   ```
   GOOGLE_CLIENT_ID=your_client_id
   GOOGLE_CLIENT_SECRET=your_client_secret
   ```

### Microsoft OAuth

1. In Azure Portal, register an app
2. Set redirect URI to: `https://your-domain.com/auth/microsoft/callback`
3. Add to `.env`:
   ```
   MICROSOFT_CLIENT_ID=your_client_id
   MICROSOFT_CLIENT_SECRET=your_client_secret
   ```

## Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `DB_CONNECTION` | Database driver (`pgsql` or `mysql`) | `pgsql` |
| `DB_HOST` | Database host | `127.0.0.1` |
| `DB_PORT` | Database port (`5432` for PG, `3306` for MySQL) | `5432` |
| `DB_DATABASE` | Database name | `queuefix` |
| `QUEUE_CONNECTION` | Queue driver | `database` |
| `INBOUND_EMAIL_POLL_BATCH_SIZE` | Maximum processing jobs dispatched per mailbox poll | `50` |
| `INBOUND_EMAIL_CLAIM_LEASE_SECONDS` | Pending-message lease; clamped above the processing timeout | `900` |
| `INBOUND_EMAIL_RETRY_BASE_SECONDS` | Initial cooldown after a final processing failure | `300` |
| `INBOUND_EMAIL_RETRY_MAX_SECONDS` | Maximum automatic retry cooldown | `3600` |
| `INBOUND_EMAIL_MAX_FAILURE_COUNT` | Final job failures before explicit recovery is required | `5` |
| `MAIL_MAILER` | Mail driver | `smtp` |
| `RATE_LIMITER_STORE` | Shared cache store for authentication rate limits | `database` |
| `TRUSTED_PROXIES` | Comma-separated exact proxy IPs/CIDRs; leave empty for direct access | empty |
| `GOOGLE_CLIENT_ID` | Google OAuth client ID | — |
| `GOOGLE_CLIENT_SECRET` | Google OAuth secret | — |
| `MICROSOFT_CLIENT_ID` | Microsoft OAuth client ID | — |
| `MICROSOFT_CLIENT_SECRET` | Microsoft OAuth secret | — |
| `GOOGLE_GMAIL_CLIENT_ID` | Gmail API client ID | — |
| `GOOGLE_GMAIL_CLIENT_SECRET` | Gmail API secret | — |
| `MICROSOFT_GRAPH_CLIENT_ID` | Graph API client ID | — |
| `MICROSOFT_GRAPH_CLIENT_SECRET` | Graph API secret | — |

See `.env.example` for the complete list.

## Upgrades

QueueFix does not auto-install releases. Administrators can review the installed version and the latest release under **Settings → Updates**. For supported Docker Compose upgrades, follow [the backup-first upgrade guide](docs/upgrading-docker.md).

## Testing

```bash
# Backend tests (Pest)
php artisan test

# Frontend tests (Vitest)
pnpm test

# Code style check
vendor/bin/pint --test

# Static analysis
vendor/bin/phpstan analyse
```

## Tech Stack

- **Backend:** Laravel 12, PHP 8.3+
- **Frontend:** React 19, TypeScript, Inertia.js
- **UI:** Tailwind CSS 4, shadcn/ui, Lucide icons
- **Database:** PostgreSQL 16+ or MySQL 8.0+
- **Queue:** Laravel Queue (database driver)
- **Search:** Laravel Scout (database driver)
- **Testing:** Pest PHP, Vitest

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for development setup and guidelines.

## License

QueueFix is open-source software licensed under the [MIT License](LICENSE).
