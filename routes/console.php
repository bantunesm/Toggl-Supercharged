<?php

use App\Jobs\WarmupTogglSnapshotsJob;
use App\Models\TogglSyncSnapshot;
use App\Services\TogglService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * @param array{
 *   years_synced: int,
 *   months_synced: int,
 *   days_synced: int,
 *   new_daily_days: int,
 *   new_daily_days_first: ?string,
 *   new_daily_days_last: ?string,
 *   new_daily_days_by_year: array<int, int>,
 *   daily_totals_by_year: array<int, int>,
 *   quota_limited: bool
 * } $recap
 */
$renderWarmupRecap = static function (Command $command, array $recap): void {
    $command->info('Toggl warmup completed.');
    $command->line(sprintf(
        'Years: %d, Months: %d, Days: %d',
        (int) $recap['years_synced'],
        (int) $recap['months_synced'],
        (int) $recap['days_synced']
    ));
    $command->line(sprintf(
        'New daily days in this run: %d%s',
        (int) $recap['new_daily_days'],
        $recap['new_daily_days'] <= 0
            ? ''
            : sprintf(' (%s -> %s)', (string) $recap['new_daily_days_first'], (string) $recap['new_daily_days_last'])
    ));
    if ((bool) $recap['quota_limited']) {
        $command->warn('Warmup stopped because Toggl quota limit was reached.');
    }

    $command->line('New daily days by year (this run):');
    if ($recap['new_daily_days_by_year'] === []) {
        $command->line('- none');
    } else {
        foreach ($recap['new_daily_days_by_year'] as $year => $count) {
            $command->line(sprintf('- %d: %d', (int) $year, (int) $count));
        }
    }

    $command->line('Total synced daily days by year:');
    foreach ($recap['daily_totals_by_year'] as $year => $count) {
        $command->line(sprintf('- %d: %d', (int) $year, (int) $count));
    }
};

Artisan::command('toggl:warmup {--history-years=} {--daily-days=} {--queued}', function (TogglService $togglService) use ($renderWarmupRecap): void {
    $historyYears = $this->option('history-years');
    $dailyDays = $this->option('daily-days');
    $resolvedHistoryYears = max(1, is_numeric($historyYears) ? (int) $historyYears : (int) config('toggl.history_years', 5));
    $resolvedDailyDays = max(0, is_numeric($dailyDays) ? (int) $dailyDays : (int) config('toggl.warmup_daily_days', 120));
    if ((bool) $this->option('queued')) {
        WarmupTogglSnapshotsJob::dispatch($resolvedHistoryYears, $resolvedDailyDays, 'console_queued');
        $this->info('Toggl warmup job dispatched on queue.');
        $this->line('Open /jobs to monitor execution and details.');
        return;
    }

    $recap = $togglService->warmupDashboardSnapshotsWithRecap(
        null,
        $resolvedHistoryYears,
        $resolvedDailyDays
    );

    $renderWarmupRecap($this, $recap);
    Log::info('Toggl warmup recap', array_merge($recap, [
        'trigger' => 'console_sync',
        'mode' => 'sync_command',
    ]));
})->purpose('Warm up Toggl snapshots for dashboard periods and daily heatmap');

Artisan::command(
    'timeflip:import
    {csv : Path to a TimeFlip CSV export}
    {--workspace-id= : Override TOGGL_WORKSPACE_ID}
    {--conflict=skip : skip|replace|merge existing daily snapshots}
    {--dry-run : Parse and show what would be imported without writing}',
    function (): int {
        $csvPath = (string) $this->argument('csv');
        if (!is_file($csvPath) || !is_readable($csvPath)) {
            $this->error(sprintf('CSV file is not readable: %s', $csvPath));

            return Command::FAILURE;
        }

        $workspaceOption = $this->option('workspace-id');
        $workspaceId = is_numeric($workspaceOption)
            ? (int) $workspaceOption
            : (int) config('toggl.workspace_id', 0);

        if ($workspaceId <= 0) {
            $this->error('A valid workspace id is required (--workspace-id or TOGGL_WORKSPACE_ID).');

            return Command::FAILURE;
        }

        $conflictMode = strtolower(trim((string) $this->option('conflict')));
        if (!in_array($conflictMode, ['skip', 'replace', 'merge'], true)) {
            $this->error('Invalid --conflict value. Allowed values: skip, replace, merge.');

            return Command::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $timezone = config('app.timezone');
        $parseDate = static function (?string $raw) use ($timezone): ?string {
            if (!is_string($raw)) {
                return null;
            }

            $value = trim($raw);
            if ($value === '') {
                return null;
            }

            $value = str_replace('/', '.', $value);
            if (!preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $value)) {
                return null;
            }

            $date = CarbonImmutable::createFromFormat('d.m.Y', $value, $timezone);
            if ($date === false) {
                return null;
            }

            return $date->toDateString();
        };
        $parseHours = static function (?string $raw): ?float {
            if (!is_string($raw)) {
                return null;
            }

            $value = trim($raw);
            if ($value === '') {
                return null;
            }

            $value = str_replace(' ', '', $value);
            $value = str_replace(',', '.', $value);
            if (!is_numeric($value)) {
                return null;
            }

            $hours = (float) $value;

            return $hours >= 0 ? $hours : null;
        };

        $file = new SplFileObject($csvPath, 'r');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::DROP_NEW_LINE);
        $file->setCsvControl(';');

        $weekDates = [];
        $dailySecondsByDate = [];
        $fromDate = null;
        $toDate = null;
        $weekCount = 0;
        $dayTotalRows = 0;

        foreach ($file as $row) {
            if (!is_array($row)) {
                continue;
            }

            $cells = array_map(
                static fn ($cell): string => is_string($cell) ? trim($cell) : '',
                $row
            );

            if ($cells === [] || implode('', $cells) === '') {
                continue;
            }

            $firstCell = $cells[0] ?? '';
            $lowerFirstCell = strtolower($firstCell);
            if ($lowerFirstCell === 'from:') {
                $parsedFrom = $parseDate($cells[1] ?? null);
                if ($parsedFrom !== null) {
                    $fromDate = $parsedFrom;
                }

                foreach ($cells as $index => $value) {
                    if (strtolower(str_replace(' ', '', $value)) !== 'to:') {
                        continue;
                    }

                    $parsedTo = $parseDate($cells[$index + 1] ?? null);
                    if ($parsedTo !== null) {
                        $toDate = $parsedTo;
                    }
                }

                continue;
            }

            if (str_starts_with($firstCell, 'Week #')) {
                $weekDates = [];
                foreach ($cells as $index => $value) {
                    if ($index < 4) {
                        continue;
                    }

                    $parsedDate = $parseDate($value);
                    if ($parsedDate !== null) {
                        $weekDates[$index] = $parsedDate;
                    }
                }

                if ($weekDates !== []) {
                    $weekCount++;
                }

                continue;
            }

            if ($lowerFirstCell !== 'day total, h' || $weekDates === []) {
                continue;
            }

            foreach ($weekDates as $index => $date) {
                $hours = $parseHours($cells[$index] ?? null);
                if ($hours === null) {
                    continue;
                }

                $dailySecondsByDate[$date] = ($dailySecondsByDate[$date] ?? 0) + (int) round($hours * 3600);
            }

            $dayTotalRows++;
        }

        if ($dailySecondsByDate === []) {
            $this->error('No daily totals were detected in this CSV.');

            return Command::FAILURE;
        }

        ksort($dailySecondsByDate);
        $minDetectedDate = array_key_first($dailySecondsByDate);
        $maxDetectedDate = array_key_last($dailySecondsByDate);
        $rangeStart = $fromDate ?? $minDetectedDate;
        $rangeEnd = $toDate ?? $maxDetectedDate;
        if ($rangeStart === null || $rangeEnd === null || $rangeEnd < $rangeStart) {
            $this->error('Unable to resolve a valid import range from the CSV.');

            return Command::FAILURE;
        }

        $preparedSecondsByDate = [];
        foreach ($dailySecondsByDate as $date => $seconds) {
            if ($date < $rangeStart || $date > $rangeEnd) {
                continue;
            }

            $preparedSecondsByDate[$date] = $seconds;
        }

        $existingByDate = TogglSyncSnapshot::query()
            ->where('workspace_id', $workspaceId)
            ->whereColumn('window_start_date', 'window_end_date')
            ->whereBetween('window_start_date', [$rangeStart, $rangeEnd])
            ->get()
            ->keyBy(static fn (TogglSyncSnapshot $snapshot): string => $snapshot->window_start_date->toDateString());

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $unchanged = 0;
        $replacePayload = [
            'source' => 'timeflip_csv',
            'file' => basename($csvPath),
            'range_start' => $rangeStart,
            'range_end' => $rangeEnd,
            'conflict_mode' => $conflictMode,
        ];

        $processImport = function () use (
            $preparedSecondsByDate,
            $existingByDate,
            $conflictMode,
            $dryRun,
            $workspaceId,
            $replacePayload,
            $csvPath,
            $rangeStart,
            $rangeEnd,
            &$created,
            &$updated,
            &$skipped,
            &$unchanged
        ): void {
            foreach ($preparedSecondsByDate as $date => $seconds) {
                /** @var TogglSyncSnapshot|null $existing */
                $existing = $existingByDate->get($date);
                $hasExisting = $existing !== null;
                $existingSeconds = $hasExisting ? max(0, (int) $existing->total_tracked_seconds) : 0;
                $targetSeconds = $seconds;
                $targetPayload = $replacePayload;

                if ($hasExisting && $conflictMode === 'skip') {
                    $skipped++;
                    continue;
                }

                if ($conflictMode === 'merge') {
                    $existingPayload = $hasExisting && is_array($existing?->raw_payload)
                        ? $existing->raw_payload
                        : [];
                    $existingManualSecondsRaw = $existingPayload['manual_imports']['timeflip_csv']['seconds'] ?? 0;
                    $existingManualSeconds = is_numeric($existingManualSecondsRaw)
                        ? max(0, (int) $existingManualSecondsRaw)
                        : 0;
                    $baseSeconds = max(0, $existingSeconds - $existingManualSeconds);
                    $targetSeconds = $baseSeconds + $seconds;

                    $targetPayload = $existingPayload;
                    $manualImports = $targetPayload['manual_imports'] ?? [];
                    if (!is_array($manualImports)) {
                        $manualImports = [];
                    }
                    $manualImports['timeflip_csv'] = [
                        'seconds' => $seconds,
                        'file' => basename($csvPath),
                        'date' => $date,
                        'range_start' => $rangeStart,
                        'range_end' => $rangeEnd,
                        'imported_at' => now()->toIso8601String(),
                    ];
                    $targetPayload['manual_imports'] = $manualImports;
                }

                $isUnchanged = false;
                if ($hasExisting && $conflictMode === 'merge') {
                    $existingPayload = is_array($existing->raw_payload) ? $existing->raw_payload : [];
                    $existingManualSecondsRaw = $existingPayload['manual_imports']['timeflip_csv']['seconds'] ?? null;
                    $existingManualSeconds = is_numeric($existingManualSecondsRaw)
                        ? max(0, (int) $existingManualSecondsRaw)
                        : null;
                    $isUnchanged = $existingSeconds === $targetSeconds && $existingManualSeconds === $seconds;
                } elseif ($hasExisting) {
                    $isUnchanged = $existingSeconds === $targetSeconds;
                }

                if ($isUnchanged) {
                    $unchanged++;
                    continue;
                }

                if (!$dryRun) {
                    TogglSyncSnapshot::query()->updateOrCreate(
                        [
                            'workspace_id' => $workspaceId,
                            'window_start_date' => $date,
                            'window_end_date' => $date,
                        ],
                        [
                            'total_tracked_seconds' => max(0, $targetSeconds),
                            'raw_payload' => $targetPayload,
                            'synced_at' => now(),
                        ]
                    );
                }

                if ($hasExisting) {
                    $updated++;
                } else {
                    $created++;
                }
            }
        };

        if ($dryRun) {
            $processImport();
        } else {
            DB::transaction(static function () use ($processImport): void {
                $processImport();
            });
        }

        $this->info($dryRun ? 'TimeFlip dry-run completed.' : 'TimeFlip import completed.');
        $this->line(sprintf('CSV: %s', $csvPath));
        $this->line(sprintf('Workspace: %d', $workspaceId));
        $this->line(sprintf('Detected week blocks: %d', $weekCount));
        $this->line(sprintf('Detected "Day total, h" rows: %d', $dayTotalRows));
        $this->line(sprintf('Detected daily entries: %d', count($dailySecondsByDate)));
        $this->line(sprintf('Prepared import rows: %d (CSV range %s -> %s)', count($preparedSecondsByDate), $rangeStart, $rangeEnd));
        $this->line(sprintf('Created: %d, Updated: %d, Unchanged: %d, Skipped(existing): %d', $created, $updated, $unchanged, $skipped));
        $this->warn('Run `php artisan cache:clear` to refresh dashboard caches after import.');

        return Command::SUCCESS;
    }
)->purpose('Import daily snapshots from a TimeFlip CSV export into toggl_sync_snapshots');

$warmupSchedule = strtolower((string) config('toggl.warmup_schedule', 'hourly'));
$warmupScheduleTime = (string) config('toggl.warmup_schedule_time', '03:10');
if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $warmupScheduleTime)) {
    $warmupScheduleTime = '03:10';
}
$warmupMinute = (int) substr($warmupScheduleTime, 3, 2);

$warmupEvent = Schedule::command(sprintf(
    'toggl:warmup --queued --history-years=%d --daily-days=%d',
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

if ((string) config('queue.default', 'database') !== 'sync') {
    Schedule::command('queue:work --queue=default --stop-when-empty --tries=1 --max-time=50')
        ->everyMinute()
        ->withoutOverlapping()
        ->runInBackground()
        ->appendOutputTo(storage_path('logs/queue-worker.log'));
}
