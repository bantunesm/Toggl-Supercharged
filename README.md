<img width="1304" height="800" alt="image" src="https://github.com/user-attachments/assets/090812ae-2054-41b4-b921-0257f18ead4f" />

# Cockpit Productivity Dashboard

Laravel productivity dashboard powered by Toggl Track.

This project computes and displays workload indicators (period performance, trends, heatmap, records, client/project breakdowns, and external benchmarks) using local snapshots to reduce API calls.

## Table of Contents

1. [Features](#features)
2. [Tech Stack](#tech-stack)
3. [Architecture](#architecture)
4. [Project Structure](#project-structure)
5. [Prerequisites](#prerequisites)
6. [Local Setup](#local-setup)
7. [Configuration](#configuration)
8. [Usage](#usage)
9. [Useful Commands](#useful-commands)
10. [Cache, Sync, and Warmup](#cache-sync-and-warmup)
11. [Tests](#tests)
12. [Production Deployment](#production-deployment)
13. [Checklist Before Publishing to GitHub](#checklist-before-publishing-to-github)
14. [Caveats](#caveats)
15. [License](#license)

## Features

- Main screen: `GET /cockpit/productivity`
- Year/month filtering (`year`, `month`)
- Yearly and monthly views with previous/next period navigation
- KPIs:
  - total tracked time
  - daily average
  - progress vs daily goal
  - best month
- Current period vs previous period comparison
- GitHub-like daily heatmap
- Time breakdown:
  - by client
  - by project
- External comparison:
  - entrepreneur benchmarks
  - country benchmarks
- All-time records:
  - best day
  - best month
  - best year
- Welcome modal with motivational message and daily/weekly recap
- API degradation handling (quota/unavailability) with snapshot fallback

## Tech Stack

- PHP `^8.2`
- Laravel `^12`
- Local database (sqlite by default)
- Laravel cache (database store in `.env.example`)
- Vite + Tailwind CSS v4 (tooling available)
- Chart.js (loaded via CDN in the Blade view)
- Tailwind CDN (loaded in `productivity.blade.php`)

## Architecture

Main flow:

1. Route `/cockpit/productivity` goes to `ProductivityDashboardController`
2. The controller resolves the selected period (month/year)
3. `TogglService`:
   - syncs or reuses snapshots (`toggl_sync_snapshots`)
   - computes metrics and trends
   - handles fallback when the API fails or is quota-limited
4. The Blade view renders KPI cards, tables, heatmap, and charts

Core files:

- `app/Http/Controllers/Cockpit/ProductivityDashboardController.php`
- `app/Services/TogglService.php`
- `app/Models/TogglSyncSnapshot.php`
- `database/migrations/2026_02_07_000100_create_toggl_sync_snapshots_table.php`
- `resources/views/cockpit/productivity.blade.php`

## Project Structure

```text
app/
  Http/Controllers/Cockpit/ProductivityDashboardController.php
  Services/TogglService.php
  Models/TogglSyncSnapshot.php
config/
  toggl.php
  benchmarks.php
database/
  migrations/2026_02_07_000100_create_toggl_sync_snapshots_table.php
resources/
  views/cockpit/productivity.blade.php
routes/
  web.php
  console.php
```

## Prerequisites

- PHP 8.2+
- Composer
- Node.js 20+ and npm
- SQLite (or another Laravel-supported database engine if you change config)
- Toggl Track API token with access to the target workspace

## Local Setup

Quick option:

```bash
composer run setup
```

Or step-by-step:

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install
npm run build
```

Run in development mode (server + queue + logs + vite):

```bash
composer dev
```

Default local URL: `http://127.0.0.1:8000`

## Configuration

Important `.env` variables:

### Application/Laravel

- `APP_NAME`
- `APP_ENV`
- `APP_DEBUG`
- `APP_URL`
- `APP_LOCALE` / `APP_FALLBACK_LOCALE`
- `DB_CONNECTION`, `DB_DATABASE`, `DB_HOST`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD`

### Toggl

- `TOGGL_BASE_URL` (default: `https://api.track.toggl.com`)
- `TOGGL_API_TOKEN` (required)
- `TOGGL_WORKSPACE_ID` (required)
- `TOGGL_SUMMARY_ENDPOINT`
- `TOGGL_SUMMARY_GROUPING`
- `TOGGL_DAILY_GOAL_HOURS`
- `TOGGL_CACHE_TTL_MINUTES`
- `TOGGL_SYNC_TTL_MINUTES`
- `TOGGL_HISTORY_YEARS`
- `TOGGL_WARMUP_DAILY_DAYS`
- `TOGGL_HEATMAP_ON_DEMAND_MAX_SYNC`
- `TOGGL_HEATMAP_MONTH_ON_DEMAND_MAX_SYNC`
- `TOGGL_TIMEOUT_SECONDS`

### Benchmarks

Benchmark values are configurable in:

- `config/benchmarks.php`

## Usage

- `/` redirects to `/cockpit/productivity`
- Query params:
  - `year` (int)
  - `month` (1..12, or empty for yearly view)

Examples:

- `GET /cockpit/productivity?year=2026`
- `GET /cockpit/productivity?year=2026&month=2`

## Useful Commands

```bash
# Manual snapshot warmup
php artisan toggl:warmup --history-years=5 --daily-days=120

# Run tests
php artisan test

# List routes
php artisan route:list

# Run scheduler locally
php artisan schedule:work
```

## Cache, Sync, and Warmup

Toggl service strategy:

- Single snapshot per window (`workspace_id + window_start_date + window_end_date`)
- Closed periods: snapshot is reused
- Open period (including today): refresh based on `TOGGL_SYNC_TTL_MINUTES`
- Aggregates are cached based on `TOGGL_CACHE_TTL_MINUTES`
- On API errors:
  - existing snapshot is reused
  - otherwise an in-memory fallback snapshot is built with error/quota metadata

Warmup:

- Artisan command `toggl:warmup` is defined in `routes/console.php`
- Current schedule: **hourly** (`->hourly()`)
- Goal: prefill yearly, monthly, and daily snapshots for the heatmap

## Tests

Current suite:

- `tests/Unit/ExampleTest.php`
- `tests/Feature/ExampleTest.php`

Note: the default feature test expects `200` on `/`, but `/` currently redirects to `/cockpit/productivity` (`302`). Update this test before enabling public CI.

## Production Deployment

Minimal checklist:

1. Configure a proper web server (Nginx/Apache) + PHP-FPM
2. Configure `.env` values (Toggl, DB, cache, queue)
3. Run:
   - `composer install --no-dev --optimize-autoloader`
   - `php artisan migrate --force`
   - `npm ci && npm run build` (or do this in CI/CD)
4. Enable scheduler:
   - cron `* * * * * php artisan schedule:run`
5. Enable queue worker if needed
6. Set up logs and monitoring

## Checklist Before Publishing to GitHub

1. Confirm `.env` is not committed (already ignored)
2. Confirm no token/API key appears in commits
3. Decide whether personal assets should remain public:
   - `public/images/1758276756115.jpeg`
   - `resources/images/1758276756115.jpeg`
4. Review hardcoded personal text in controller/view
5. Fix failing tests to keep CI green
6. Add a GitHub Actions workflow (tests + lint)
7. Initialize local git repo and push to GitHub

## Caveats

- The dashboard is currently not protected by authentication.
- The UI loads `Chart.js` and `Tailwind CDN` from the internet (not fully offline).
- `TOGGL_WARMUP_SCHEDULE_TIME` exists in config but is not used by the current scheduled task.
- Tests are minimal and provide limited business-logic coverage.

## License

This project is based on Laravel (MIT license). Define your final project license in this file (MIT recommended).
