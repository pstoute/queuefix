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
docker-compose up -d
docker-compose exec app composer install
docker-compose exec app php artisan key:generate
docker-compose exec app php artisan migrate --seed
docker-compose exec app sh -c "pnpm install && pnpm build"
```

Then open http://localhost:8000.

**Demo login:** `admin@example.com` / `password`

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

# Run migrations and seed demo data
php artisan migrate --seed

# Build frontend assets
pnpm build

# Start the server
php artisan serve

# In a separate terminal, start the queue worker
php artisan queue:work

# In a separate terminal, start the scheduler
php artisan schedule:work
```

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

## OAuth Login Setup (for Agents)

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
| `MAIL_MAILER` | Mail driver | `smtp` |
| `GOOGLE_CLIENT_ID` | Google OAuth client ID | — |
| `GOOGLE_CLIENT_SECRET` | Google OAuth secret | — |
| `MICROSOFT_CLIENT_ID` | Microsoft OAuth client ID | — |
| `MICROSOFT_CLIENT_SECRET` | Microsoft OAuth secret | — |
| `GOOGLE_GMAIL_CLIENT_ID` | Gmail API client ID | — |
| `GOOGLE_GMAIL_CLIENT_SECRET` | Gmail API secret | — |
| `MICROSOFT_GRAPH_CLIENT_ID` | Graph API client ID | — |
| `MICROSOFT_GRAPH_CLIENT_SECRET` | Graph API secret | — |

See `.env.example` for the complete list.

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
