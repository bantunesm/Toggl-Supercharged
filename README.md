# Cockpit Productivity Dashboard

Dashboard Laravel de suivi de productivite base sur Toggl Track.

Le projet calcule et affiche des indicateurs de charge de travail (periode, evolution, heatmap, records, repartitions clients/projets, comparatifs externes) en s'appuyant sur des snapshots locaux pour limiter les appels API.

## Sommaire

1. [Fonctionnalites](#fonctionnalites)
2. [Stack technique](#stack-technique)
3. [Architecture](#architecture)
4. [Structure du projet](#structure-du-projet)
5. [Prerequis](#prerequis)
6. [Installation locale](#installation-locale)
7. [Configuration](#configuration)
8. [Utilisation](#utilisation)
9. [Commandes utiles](#commandes-utiles)
10. [Cache, sync et warmup](#cache-sync-et-warmup)
11. [Tests](#tests)
12. [Mise en production](#mise-en-production)
13. [Checklist avant publication GitHub](#checklist-avant-publication-github)
14. [Points d'attention](#points-dattention)
15. [Licence](#licence)

## Fonctionnalites

- Vue principale: `GET /cockpit/productivity`
- Filtrage par annee et mois (`year`, `month`)
- Vue annuelle ou mensuelle avec navigation periode precedente/suivante
- KPIs:
  - temps total
  - moyenne journaliere
  - progression vs objectif journalier
  - meilleur mois
- Comparatif periode courante vs periode precedente
- Heatmap journaliere style GitHub
- Repartition du temps:
  - par client
  - par projet
- Comparaison externe:
  - benchmarks entrepreneurs
  - benchmarks pays
- Records "all time":
  - meilleur jour
  - meilleur mois
  - meilleure annee
- Modal d'accueil motivee + recap veille/7 jours
- Gestion degradation API (quota, indisponibilite) avec fallback sur snapshots existants

## Stack technique

- PHP `^8.2`
- Laravel `^12`
- Base de donnees locale (sqlite par defaut)
- Cache Laravel (database dans `.env.example`)
- Vite + Tailwind CSS v4 (tooling present)
- Chart.js (charge via CDN dans la vue)
- Tailwind CDN (charge dans la vue `productivity.blade.php`)

## Architecture

Flux principal:

1. Route `/cockpit/productivity` vers `ProductivityDashboardController`
2. Le controleur calcule la periode selectionnee (mois/annee)
3. `TogglService`:
   - synchronise ou reutilise des snapshots (`toggl_sync_snapshots`)
   - calcule les metriques et evolutions
   - gere fallback si l'API echoue ou limite le quota
4. La vue Blade rend les cartes KPI, tableaux, heatmap et graphiques

Fichiers centraux:

- `app/Http/Controllers/Cockpit/ProductivityDashboardController.php`
- `app/Services/TogglService.php`
- `app/Models/TogglSyncSnapshot.php`
- `database/migrations/2026_02_07_000100_create_toggl_sync_snapshots_table.php`
- `resources/views/cockpit/productivity.blade.php`

## Structure du projet

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

## Prerequis

- PHP 8.2+
- Composer
- Node.js 20+ et npm
- SQLite (ou autre moteur supporte Laravel si vous adaptez la config)
- Token API Toggl Track avec acces au workspace cible

## Installation locale

Option rapide:

```bash
composer run setup
```

Ou etapes detaillees:

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install
npm run build
```

Lancer en mode dev (serveur + queue + logs + vite):

```bash
composer dev
```

URL locale par defaut: `http://127.0.0.1:8000`

## Configuration

Variables importantes dans `.env`:

### Application/Laravel

- `APP_NAME`
- `APP_ENV`
- `APP_DEBUG`
- `APP_URL`
- `APP_LOCALE` / `APP_FALLBACK_LOCALE`
- `DB_CONNECTION`, `DB_DATABASE`, `DB_HOST`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD`

### Toggl

- `TOGGL_BASE_URL` (defaut: `https://api.track.toggl.com`)
- `TOGGL_API_TOKEN` (obligatoire)
- `TOGGL_WORKSPACE_ID` (obligatoire)
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

Les repères comparatifs sont configurables dans:

- `config/benchmarks.php`

## Utilisation

- `/` redirige vers `/cockpit/productivity`
- Params:
  - `year` (int)
  - `month` (1..12 ou vide pour vue annuelle)

Exemples:

- `GET /cockpit/productivity?year=2026`
- `GET /cockpit/productivity?year=2026&month=2`

## Commandes utiles

```bash
# Warmup manuel snapshots
php artisan toggl:warmup --history-years=5 --daily-days=120

# Lancer les tests
php artisan test

# Voir les routes
php artisan route:list

# Lancer le scheduler localement
php artisan schedule:work
```

## Cache, sync et warmup

Strategie du service Toggl:

- Snapshot unique par fenetre (`workspace_id + window_start_date + window_end_date`)
- Periodes fermees: reutilisation du snapshot
- Periode ouverte (incluant aujourd'hui): refresh selon `TOGGL_SYNC_TTL_MINUTES`
- Cache applicatif des aggregats selon `TOGGL_CACHE_TTL_MINUTES`
- En cas d'erreur API:
  - si snapshot existe, il est reutilise
  - sinon snapshot fallback en memoire avec metadonnees d'erreur/quota

Warmup:

- Commande artisan `toggl:warmup` definie dans `routes/console.php`
- Schedule actuel: execution **horaire** (`->hourly()`)
- Objectif: pre-remplir snapshots annuels, mensuels, et N jours pour la heatmap

## Tests

Suite actuelle:

- `tests/Unit/ExampleTest.php`
- `tests/Feature/ExampleTest.php`

Note: le test feature par defaut attend `200` sur `/`, alors que `/` redirige vers `/cockpit/productivity` (302). Il faut ajuster ce test avant CI publique.

## Mise en production

Checklist minimale:

1. Configurer un vrai serveur web (Nginx/Apache) + PHP-FPM
2. Configurer les variables `.env` (Toggl, DB, cache, queue)
3. Executer:
   - `composer install --no-dev --optimize-autoloader`
   - `php artisan migrate --force`
   - `npm ci && npm run build` (ou pipeline CI)
4. Activer scheduler:
   - cron `* * * * * php artisan schedule:run`
5. Activer worker queue si necessaire
6. Mettre en place logs + supervision

## Checklist avant publication GitHub

1. Verifier que `.env` n'est pas committe (deja ignore)
2. Verifier qu'aucun token/API key n'apparait dans les commits
3. Decider si les assets personnels doivent rester publics:
   - `public/images/1758276756115.jpeg`
   - `resources/images/1758276756115.jpeg`
4. Reviser les textes hardcodes personnels dans la vue/controleur
5. Corriger les tests casses pour avoir une CI verte
6. Ajouter un workflow CI GitHub Actions (tests + lint)
7. Initialiser le depot git local puis pousser sur GitHub

## Points d'attention

- Le dashboard n'est pas protege par authentification dans l'etat actuel.
- L'UI charge `Chart.js` et `Tailwind CDN` depuis internet (pas 100% offline).
- `TOGGL_WARMUP_SCHEDULE_TIME` est present en config mais non utilise par la tache schedulee actuelle.
- Les tests sont minimaux et couvrent peu la logique metier.

## Licence

Ce projet est base sur Laravel (licence MIT). Precisez la licence finale du projet dans ce fichier selon votre choix (MIT recommande).
