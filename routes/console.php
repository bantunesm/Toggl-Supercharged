<?php

use App\Models\TogglSyncSnapshot;
use App\Services\TogglService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('toggl:warmup {--history-years=} {--daily-days=}', function (TogglService $togglService): void {
    $historyYears = $this->option('history-years');
    $dailyDays = $this->option('daily-days');
    $timezone = (string) config('app.timezone');
    $today = CarbonImmutable::today($timezone);
    $resolvedHistoryYears = max(1, is_numeric($historyYears) ? (int) $historyYears : (int) config('toggl.history_years', 5));
    $resolvedDailyDays = max(0, is_numeric($dailyDays) ? (int) $dailyDays : (int) config('toggl.warmup_daily_days', 120));
    $workspaceId = (int) config('toggl.workspace_id', 0);
    $fromYear = (int) $today->year - ($resolvedHistoryYears - 1);
    $yearWindowStart = CarbonImmutable::create($fromYear, 1, 1, 0, 0, 0, $timezone)->startOfDay();
    $dailyWindowStart = $resolvedDailyDays > 0
        ? $today->subDays($resolvedDailyDays - 1)->startOfDay()
        : $today->startOfDay();
    $syncWindowStart = $yearWindowStart->lt($dailyWindowStart) ? $yearWindowStart : $dailyWindowStart;

    $dailyBefore = TogglSyncSnapshot::query()
        ->where('workspace_id', $workspaceId)
        ->whereColumn('window_start_date', 'window_end_date')
        ->whereBetween('window_start_date', [$syncWindowStart->toDateString(), $today->toDateString()])
        ->pluck('window_start_date')
        ->map(static fn ($date): string => CarbonImmutable::parse((string) $date)->toDateString())
        ->all();

    $result = $togglService->warmupDashboardSnapshots(
        $today,
        $resolvedHistoryYears,
        $resolvedDailyDays
    );

    $dailyAfter = TogglSyncSnapshot::query()
        ->where('workspace_id', $workspaceId)
        ->whereColumn('window_start_date', 'window_end_date')
        ->whereBetween('window_start_date', [$syncWindowStart->toDateString(), $today->toDateString()])
        ->pluck('window_start_date')
        ->map(static fn ($date): string => CarbonImmutable::parse((string) $date)->toDateString())
        ->all();

    $newDailyDays = array_values(array_diff($dailyAfter, $dailyBefore));
    sort($newDailyDays);

    $newDailyByYear = [];
    foreach ($newDailyDays as $date) {
        $year = (int) substr($date, 0, 4);
        $newDailyByYear[$year] = ($newDailyByYear[$year] ?? 0) + 1;
    }
    ksort($newDailyByYear);

    $dailyTotalsByYear = TogglSyncSnapshot::query()
        ->selectRaw('YEAR(window_start_date) as year, COUNT(DISTINCT window_start_date) as synced_days')
        ->where('workspace_id', $workspaceId)
        ->whereColumn('window_start_date', 'window_end_date')
        ->groupByRaw('YEAR(window_start_date)')
        ->orderByRaw('YEAR(window_start_date)')
        ->get();

    $this->info('Toggl warmup completed.');
    $this->line(sprintf(
        'Years: %d, Months: %d, Days: %d',
        $result['years_synced'],
        $result['months_synced'],
        $result['days_synced']
    ));
    $this->line(sprintf(
        'New daily days in this run: %d%s',
        count($newDailyDays),
        $newDailyDays === []
            ? ''
            : sprintf(' (%s -> %s)', $newDailyDays[0], $newDailyDays[count($newDailyDays) - 1])
    ));
    if (($result['quota_limited'] ?? false) === true) {
        $this->warn('Warmup stopped because Toggl quota limit was reached.');
    }

    $this->line('New daily days by year (this run):');
    if ($newDailyByYear === []) {
        $this->line('- none');
    } else {
        foreach ($newDailyByYear as $year => $count) {
            $this->line(sprintf('- %d: %d', $year, $count));
        }
    }

    $this->line('Total synced daily days by year:');
    foreach ($dailyTotalsByYear as $row) {
        $this->line(sprintf('- %d: %d', (int) $row->year, (int) $row->synced_days));
    }

    Log::info('Toggl warmup recap', [
        'workspace_id' => $workspaceId,
        'history_years' => $resolvedHistoryYears,
        'daily_days' => $resolvedDailyDays,
        'years_synced' => (int) $result['years_synced'],
        'months_synced' => (int) $result['months_synced'],
        'days_synced' => (int) $result['days_synced'],
        'quota_limited' => (bool) ($result['quota_limited'] ?? false),
        'new_daily_days' => count($newDailyDays),
        'new_daily_days_first' => $newDailyDays[0] ?? null,
        'new_daily_days_last' => $newDailyDays === [] ? null : $newDailyDays[count($newDailyDays) - 1],
        'new_daily_days_by_year' => $newDailyByYear,
        'daily_totals_by_year' => $dailyTotalsByYear->mapWithKeys(
            static fn ($row): array => [(int) $row->year => (int) $row->synced_days]
        )->all(),
    ]);
})->purpose('Warm up Toggl snapshots for dashboard periods and daily heatmap');

$warmupSchedule = strtolower((string) config('toggl.warmup_schedule', 'hourly'));
$warmupScheduleTime = (string) config('toggl.warmup_schedule_time', '03:10');
if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $warmupScheduleTime)) {
    $warmupScheduleTime = '03:10';
}
$warmupMinute = (int) substr($warmupScheduleTime, 3, 2);

$warmupEvent = Schedule::command(sprintf(
    'toggl:warmup --history-years=%d --daily-days=%d',
    (int) config('toggl.history_years', 5),
    (int) config('toggl.warmup_daily_days', 120)
))
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/toggl-warmup.log'));

if ($warmupSchedule === 'daily') {
    $warmupEvent->dailyAt($warmupScheduleTime);
} else {
    $warmupEvent->hourlyAt($warmupMinute);
}
