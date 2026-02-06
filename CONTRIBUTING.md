# Contributing to Simple Tickets

Thank you for your interest in contributing to Simple Tickets! This document provides guidelines and instructions for contributing.

## Development Setup

### Prerequisites

- PHP 8.3+
- Composer
- Node.js 22+
- pnpm
- PostgreSQL 16+
- Docker (optional, recommended)

### Getting Started

1. Fork the repository
2. Clone your fork:
   ```bash
   git clone https://github.com/YOUR_USERNAME/simpletickets.git
   cd simpletickets
   ```

3. Install dependencies:
   ```bash
   composer install
   pnpm install
   ```

4. Copy environment file and configure:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

5. Set up the database:
   ```bash
   php artisan migrate
   php artisan db:seed
   ```

6. Start the development servers:
   ```bash
   # Terminal 1: PHP server
   php artisan serve

   # Terminal 2: Vite dev server
   pnpm dev

   # Terminal 3: Queue worker
   php artisan queue:work
   ```

### Using Docker

```bash
docker-compose up -d
```

This starts PostgreSQL, Redis, and Mailpit automatically.

## Code Standards

### PHP

- **Style:** PSR-12, enforced by Laravel Pint
- **Static Analysis:** Larastan level 6+
- **Testing:** Pest PHP

Run code checks:
```bash
# Code style
vendor/bin/pint

# Static analysis
vendor/bin/phpstan analyse

# Tests
php artisan test
```

### TypeScript/React

- TypeScript strict mode
- React 19 with functional components
- shadcn/ui component library
- Tailwind CSS 4 for styling

Run frontend checks:
```bash
# Type check
npx tsc --noEmit

# Tests
pnpm test
```

## Pull Request Process

1. Create a feature branch from `main`:
   ```bash
   git checkout -b feature/your-feature-name
   ```

2. Make your changes following the code standards above

3. Write tests for any new functionality

4. Ensure all tests pass:
   ```bash
   php artisan test
   pnpm test -- --run
   vendor/bin/pint --test
   vendor/bin/phpstan analyse
   ```

5. Submit a pull request with:
   - Clear description of the changes
   - Screenshots for UI changes
   - Reference to any related issues

## Architecture Guidelines

- Keep business logic in Service classes, not controllers
- Controllers should be thin — validate, call service, return response
- Use Laravel Policies for authorization
- Use Enums for fixed value sets
- All database changes require migrations
- All mailbox credentials must be encrypted

## Testing Requirements

- Minimum 80% backend test coverage
- Feature tests for every new endpoint
- Unit tests for business logic (services)
- Frontend component tests for critical UI

## Reporting Issues

Use GitHub Issues to report bugs or request features. Include:
- Steps to reproduce
- Expected behavior
- Actual behavior
- Environment details (PHP version, OS, etc.)
