# EvoSyndic

EvoSyndic is a French/Arabic Moroccan SaaS for volunteer and professional copropriété managers. Phase 01 provides authentication, tenant-aware organizations and residences, property structure, contacts, ownership/occupancy history, allocation keys, imports, onboarding, team roles, activity history, and responsive dashboards. It intentionally contains no accounting, charges, payments, expenses, incidents, or assemblies.

## Local installation

Requirements: PHP 8.3+, Composer 2, Node.js 20+, MySQL 8 (SQLite is supported for tests), and the PHP extensions required by Laravel plus `zip`, `xml`, `gd`, and `mbstring`.

```bash
composer install
cp .env.example .env
php artisan key:generate
# Set DB_* and MAIL_* in .env
php artisan migrate
php artisan storage:link
npm ci
npm run build
php artisan serve
php artisan queue:work --tries=3
```

For development, `composer run dev` starts the web server, queue worker, logs, and Vite. Run `php artisan db:seed --class=DemoSeeder` only in a local/demo database. Demo credentials all use password `password`:

- `owner@evosyndic.test` — owner
- `manager@evosyndic.test` — manager
- `auditor@evosyndic.test` — read-only auditor

## Verification

```bash
vendor/bin/pint --test
npm run format:check
npm run lint
php artisan test
npm run build
composer validate --strict
php artisan migrate:fresh --seed
php artisan migrate:rollback --step=1
php artisan migrate
php artisan migrate:status
```

See [Phase 01 architecture](docs/phase-01.md) for the schema, permission matrix, imports, isolation guarantees, queues, deployment, deviations, and Phase 02 integration notes.
# evo.syndic
# evo.syndic
