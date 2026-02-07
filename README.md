<img width="1304" height="800" alt="image" src="https://github.com/user-attachments/assets/090812ae-2054-41b4-b921-0257f18ead4f" />

# Cockpit

Dashboard Laravel pour suivre la productivite (Toggl), avec vue mensuelle/annuelle, heatmap, comparatifs et records.

## Stack

- PHP 8.2+
- Laravel 12
- SQLite (par defaut) ou MySQL
- Tailwind/Blade

## Installation rapide

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install
npm run build
php artisan serve
```

## Variables importantes

Configurer au minimum dans `.env`:

```dotenv
TOGGL_API_TOKEN=...
TOGGL_WORKSPACE_ID=...
```

Options utiles:

```dotenv
TOGGL_DAILY_GOAL_HOURS=8
TOGGL_CACHE_TTL_MINUTES=10
TOGGL_SYNC_TTL_MINUTES=240
TOGGL_HISTORY_YEARS=5
TOGGL_WARMUP_DAILY_DAYS=120

# Scheduler warmup:
# - hourly: lance toutes les heures (minute issue de TOGGL_WARMUP_SCHEDULE_TIME)
# - daily: lance une fois par jour a l'heure de TOGGL_WARMUP_SCHEDULE_TIME
TOGGL_WARMUP_SCHEDULE=hourly
TOGGL_WARMUP_SCHEDULE_TIME=03:10
```

## Routes

- `/` redirige vers `/cockpit/productivity`
- `/cockpit/productivity` dashboard principal

## Warmup / scheduler

Commande:

```bash
php artisan toggl:warmup
```

Scheduler Laravel:

```bash
php artisan schedule:work
```

## Tests

```bash
php artisan test
```

Les tests feature mockent `TogglService` pour eviter la dependance reseau.

## Structure cle

- `app/Http/Controllers/Cockpit/ProductivityDashboardController.php`
- `app/Services/TogglService.php`
- `resources/views/cockpit/productivity.blade.php`
- `resources/views/cockpit/partials/*`

