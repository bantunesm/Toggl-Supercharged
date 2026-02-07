<?php

use App\Services\TogglService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('toggl:warmup {--history-years=} {--daily-days=}', function (TogglService $togglService): void {
    $historyYears = $this->option('history-years');
    $dailyDays = $this->option('daily-days');

    $result = $togglService->warmupDashboardSnapshots(
        CarbonImmutable::today(config('app.timezone')),
        is_numeric($historyYears) ? (int) $historyYears : null,
        is_numeric($dailyDays) ? (int) $dailyDays : null
    );

    $this->info('Toggl warmup completed.');
    $this->line(sprintf(
        'Years: %d, Months: %d, Days: %d',
        $result['years_synced'],
        $result['months_synced'],
        $result['days_synced']
    ));
})->purpose('Warm up Toggl snapshots for dashboard periods and daily heatmap');

Schedule::command(sprintf(
    'toggl:warmup --history-years=%d --daily-days=%d',
    (int) config('toggl.history_years', 5),
    (int) config('toggl.warmup_daily_days', 120)
))
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();
