<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\TogglSyncSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class TogglService
{
    /**
     * @return array{
     *   workspace_id: int,
     *   date: string,
     *   seconds: int,
     *   hours: string,
     *   synced_at: string,
     *   has_api_fallback: bool,
     *   quota_limited: bool
     * }
     */
    public function syncTodaySnapshot(?CarbonImmutable $today = null): array
    {
        $today = $today ?? CarbonImmutable::today(config('app.timezone'));
        $workspaceId = $this->workspaceId();
        $snapshot = $this->syncPeriodSnapshot($workspaceId, $today, $today);
        $seconds = max(0, (int) $snapshot->total_tracked_seconds);

        return [
            'workspace_id' => $workspaceId,
            'date' => $today->toDateString(),
            'seconds' => $seconds,
            'hours' => sprintf('%d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60)),
            'synced_at' => $snapshot->synced_at?->toIso8601String() ?? now()->toIso8601String(),
            'has_api_fallback' => $this->isFallbackSnapshot($snapshot),
            'quota_limited' => $this->isQuotaLimitedSnapshot($snapshot),
        ];
    }

    /**
     * @return array{
     *   workspace_id: int,
     *   history_years: int,
     *   daily_days: int,
     *   years_synced: int,
     *   months_synced: int,
     *   days_synced: int,
     *   quota_limited: bool,
     *   new_daily_days: int,
     *   new_daily_days_first: ?string,
     *   new_daily_days_last: ?string,
     *   new_daily_days_by_year: array<int, int>,
     *   daily_totals_by_year: array<int, int>
     * }
     */
    public function warmupDashboardSnapshotsWithRecap(
        ?CarbonImmutable $today = null,
        ?int $historyYears = null,
        ?int $dailyDays = null
    ): array {
        $today = $today ?? CarbonImmutable::today(config('app.timezone'));
        $historyYears = max(1, $historyYears ?? (int) config('toggl.history_years', 5));
        $dailyDays = max(0, $dailyDays ?? (int) config('toggl.warmup_daily_days', 120));
        $workspaceId = $this->workspaceId();
        $fromYear = (int) $today->year - ($historyYears - 1);
        $yearWindowStart = CarbonImmutable::create($fromYear, 1, 1, 0, 0, 0, config('app.timezone'))->startOfDay();
        $dailyWindowStart = $dailyDays > 0 ? $today->subDays($dailyDays - 1)->startOfDay() : $today->startOfDay();
        $syncWindowStart = $yearWindowStart->lt($dailyWindowStart) ? $yearWindowStart : $dailyWindowStart;

        $dailyBefore = $this->fetchDailySnapshotDates($workspaceId, $syncWindowStart, $today);
        $result = $this->warmupDashboardSnapshots($today, $historyYears, $dailyDays);
        $dailyAfter = $this->fetchDailySnapshotDates($workspaceId, $syncWindowStart, $today);

        $newDailyDays = array_values(array_diff($dailyAfter, $dailyBefore));
        sort($newDailyDays);

        $newDailyByYear = [];
        foreach ($newDailyDays as $date) {
            $year = (int) substr($date, 0, 4);
            $newDailyByYear[$year] = ($newDailyByYear[$year] ?? 0) + 1;
        }
        ksort($newDailyByYear);

        return [
            'workspace_id' => $workspaceId,
            'history_years' => $historyYears,
            'daily_days' => $dailyDays,
            'years_synced' => (int) $result['years_synced'],
            'months_synced' => (int) $result['months_synced'],
            'days_synced' => (int) $result['days_synced'],
            'quota_limited' => (bool) ($result['quota_limited'] ?? false),
            'new_daily_days' => count($newDailyDays),
            'new_daily_days_first' => $newDailyDays[0] ?? null,
            'new_daily_days_last' => $newDailyDays === [] ? null : $newDailyDays[count($newDailyDays) - 1],
            'new_daily_days_by_year' => $newDailyByYear,
            'daily_totals_by_year' => $this->fetchDailyTotalsByYear($workspaceId),
        ];
    }

    /**
     * @return array{
     *   tracking_since: ?string,
     *   day: ?array{seconds: int, date: string},
     *   month: ?array{seconds: int, start_date: string, end_date: string},
     *   year: ?array{seconds: int, start_date: string, end_date: string}
     * }
     */
    public function getAllTimeRecords(): array
    {
        $workspaceId = $this->workspaceId();
        $cacheKey = sprintf('toggl.records.all_time.v5.%d', $workspaceId);

        return Cache::remember(
            $cacheKey,
            now()->addMinutes((int) config('toggl.cache_ttl_minutes', 10)),
            function () use ($workspaceId): array {
                $trackingSince = TogglSyncSnapshot::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('total_tracked_seconds', '>', 0)
                    ->orderBy('window_start_date')
                    ->value('window_start_date');

                /** @var array<int, TogglSyncSnapshot> $snapshots */
                $snapshots = TogglSyncSnapshot::query()
                    ->where('workspace_id', $workspaceId)
                    ->orderByDesc('total_tracked_seconds')
                    ->get()
                    ->all();

                $dayRecord = null;
                $monthRecord = null;
                $yearRecord = null;
                $manualTimeflipTotals = $this->fetchManualTimeflipTotalsByMonthAndYear($workspaceId);

                foreach ($snapshots as $snapshot) {
                    if ($this->isFallbackSnapshot($snapshot)) {
                        continue;
                    }

                    $start = CarbonImmutable::parse((string) $snapshot->window_start_date, config('app.timezone'))->startOfDay();
                    $end = CarbonImmutable::parse((string) $snapshot->window_end_date, config('app.timezone'))->startOfDay();
                    $seconds = max(0, (int) $snapshot->total_tracked_seconds);

                    if ($seconds <= 0) {
                        continue;
                    }

                    if ($start->isSameDay($end) && ($dayRecord === null || $seconds > (int) $dayRecord['seconds'])) {
                        $dayRecord = [
                            'seconds' => $seconds,
                            'date' => $start->toDateString(),
                        ];
                    }

                    if ($this->isCompleteMonthSnapshot($start, $end)) {
                        $monthSeconds = $seconds;
                        if (!$this->isDailyRollupSnapshot($snapshot)) {
                            $monthKey = $start->format('Y-m');
                            $monthSeconds += (int) ($manualTimeflipTotals['month'][$monthKey] ?? 0);
                        }

                        if ($monthRecord === null || $monthSeconds > (int) $monthRecord['seconds']) {
                            $monthRecord = [
                                'seconds' => $monthSeconds,
                                'start_date' => $start->toDateString(),
                                'end_date' => $end->toDateString(),
                            ];
                        }
                    }

                    if ($this->isCompleteYearSnapshot($start, $end)) {
                        $yearSeconds = $seconds;
                        if (!$this->isDailyRollupSnapshot($snapshot)) {
                            $yearSeconds += (int) ($manualTimeflipTotals['year'][(int) $start->year] ?? 0);
                        }

                        if ($yearRecord === null || $yearSeconds > (int) $yearRecord['seconds']) {
                            $yearRecord = [
                                'seconds' => $yearSeconds,
                                'start_date' => $start->toDateString(),
                                'end_date' => $end->toDateString(),
                            ];
                        }
                    }
                }

                return [
                    'tracking_since' => $trackingSince !== null ? (string) $trackingSince : null,
                    'day' => $dayRecord,
                    'month' => $monthRecord,
                    'year' => $yearRecord,
                ];
            }
        );
    }

    /**
     * @return array{
     *   start_date: string,
     *   end_date: string,
     *   days_in_period: int,
     *   total_seconds: int,
     *   daily_average_seconds: float,
     *   daily_goal_hours: float,
     *   progress_ratio: float,
     *   has_api_fallback: bool,
     *   quota_limited: bool,
     *   synced_at: string
     * }
     */
    public function getPeriodMetrics(
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        ?float $dailyGoalHours = null
    ): array {
        $this->assertValidPeriod($periodStart, $periodEnd);

        $dailyGoalHours = max(0.0, $dailyGoalHours ?? (float) config('toggl.daily_goal_hours', 8.0));
        $workspaceId = $this->workspaceId();
        $cacheKey = sprintf(
            'toggl.period.metrics.v4.%d.%s.%s.%s',
            $workspaceId,
            $periodStart->toDateString(),
            $periodEnd->toDateString(),
            number_format($dailyGoalHours, 2, '.', '')
        );

        return Cache::remember(
            $cacheKey,
            now()->addMinutes((int) config('toggl.cache_ttl_minutes', 10)),
            function () use ($workspaceId, $periodStart, $periodEnd, $dailyGoalHours): array {
                $snapshot = $this->syncPeriodSnapshot($workspaceId, $periodStart, $periodEnd);
                $daysInPeriod = $periodStart->diffInDays($periodEnd) + 1;
                $totalSeconds = $this->resolveSnapshotTotalSeconds(
                    $workspaceId,
                    $periodStart,
                    $periodEnd,
                    $snapshot
                );
                $dailyAverageSeconds = $totalSeconds / max(1, $daysInPeriod);
                $targetSeconds = $dailyGoalHours * 3600 * $daysInPeriod;
                $progressRatio = $targetSeconds > 0 ? $totalSeconds / $targetSeconds : 0.0;

                return [
                    'start_date' => $periodStart->toDateString(),
                    'end_date' => $periodEnd->toDateString(),
                    'days_in_period' => $daysInPeriod,
                    'total_seconds' => $totalSeconds,
                    'daily_average_seconds' => $dailyAverageSeconds,
                    'daily_goal_hours' => $dailyGoalHours,
                    'progress_ratio' => $progressRatio,
                    'has_api_fallback' => $this->isFallbackSnapshot($snapshot),
                    'quota_limited' => $this->isQuotaLimitedSnapshot($snapshot),
                    'synced_at' => $snapshot->synced_at->toIso8601String(),
                ];
            }
        );
    }

    /**
     * @return array{
     *   start_date: string,
     *   end_date: string,
     *   total_seconds: int,
     *   projects: array<int, array{name: string, client: string, seconds: int, hours: string, share_percent: string}>,
     *   clients: array<int, array{name: string, seconds: int, hours: string, share_percent: string, project_count: int}>,
     *   has_api_fallback: bool,
     *   quota_limited: bool,
     *   synced_at: string
     * }
     */
    public function getPeriodClientProjectBreakdown(
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd
    ): array {
        $this->assertValidPeriod($periodStart, $periodEnd);

        $workspaceId = $this->workspaceId();
        $cacheKey = sprintf(
            'toggl.period.breakdown.v2.%d.%s.%s',
            $workspaceId,
            $periodStart->toDateString(),
            $periodEnd->toDateString()
        );

        return Cache::remember(
            $cacheKey,
            now()->addMinutes((int) config('toggl.cache_ttl_minutes', 10)),
            function () use ($workspaceId, $periodStart, $periodEnd): array {
                try {
                    $payload = $this->fetchSummary($workspaceId, $periodStart, $periodEnd, 'projects');
                } catch (Throwable $throwable) {
                    report($throwable);

                    return [
                        'start_date' => $periodStart->toDateString(),
                        'end_date' => $periodEnd->toDateString(),
                        'total_seconds' => 0,
                        'projects' => [],
                        'clients' => [],
                        'has_api_fallback' => true,
                        'quota_limited' => $this->isQuotaLimitedThrowable($throwable),
                        'synced_at' => now()->toIso8601String(),
                    ];
                }

                $rawProjectRows = $this->extractProjectBreakdownRows($payload);
                $projectsByKey = [];

                foreach ($rawProjectRows as $row) {
                    $projectName = $row['project'];
                    $clientName = $this->resolveClientByMatchingRules($projectName, $row['client']);
                    $seconds = max(0, (int) $row['seconds']);
                    if ($seconds <= 0) {
                        continue;
                    }

                    $projectKey = mb_strtolower($projectName.'|'.$clientName);
                    if (!isset($projectsByKey[$projectKey])) {
                        $projectsByKey[$projectKey] = [
                            'name' => $projectName,
                            'client' => $clientName,
                            'seconds' => 0,
                        ];
                    }
                    $projectsByKey[$projectKey]['seconds'] += $seconds;
                }

                $totalSecondsFromProjects = 0;
                foreach ($projectsByKey as $project) {
                    $totalSecondsFromProjects += (int) $project['seconds'];
                }

                $totalSeconds = $totalSecondsFromProjects > 0
                    ? $totalSecondsFromProjects
                    : $this->extractTotalTrackedSeconds($payload);

                $projects = array_values($projectsByKey);
                usort($projects, static fn (array $left, array $right): int => $right['seconds'] <=> $left['seconds']);
                $projects = array_map(
                    static function (array $project) use ($totalSeconds): array {
                        $seconds = (int) $project['seconds'];
                        $sharePercent = $totalSeconds > 0 ? ($seconds / $totalSeconds) * 100 : 0.0;

                        return [
                            'name' => (string) $project['name'],
                            'client' => (string) $project['client'],
                            'seconds' => $seconds,
                            'hours' => number_format($seconds / 3600, 2),
                            'share_percent' => number_format($sharePercent, 1),
                        ];
                    },
                    $projects
                );

                $clientsByKey = [];
                foreach ($projects as $project) {
                    $clientName = (string) $project['client'];
                    $clientKey = mb_strtolower($clientName);

                    if (!isset($clientsByKey[$clientKey])) {
                        $clientsByKey[$clientKey] = [
                            'name' => $clientName,
                            'seconds' => 0,
                            'projects' => [],
                        ];
                    }

                    $clientsByKey[$clientKey]['seconds'] += (int) $project['seconds'];
                    $clientsByKey[$clientKey]['projects'][mb_strtolower((string) $project['name'])] = true;
                }

                $clients = [];
                foreach ($clientsByKey as $client) {
                    $seconds = (int) $client['seconds'];
                    $sharePercent = $totalSeconds > 0 ? ($seconds / $totalSeconds) * 100 : 0.0;
                    $clients[] = [
                        'name' => (string) $client['name'],
                        'seconds' => $seconds,
                        'hours' => number_format($seconds / 3600, 2),
                        'share_percent' => number_format($sharePercent, 1),
                        'project_count' => count($client['projects']),
                    ];
                }
                usort($clients, static fn (array $left, array $right): int => $right['seconds'] <=> $left['seconds']);

                return [
                    'start_date' => $periodStart->toDateString(),
                    'end_date' => $periodEnd->toDateString(),
                    'total_seconds' => $totalSeconds,
                    'projects' => $projects,
                    'clients' => $clients,
                    'has_api_fallback' => false,
                    'quota_limited' => false,
                    'synced_at' => now()->toIso8601String(),
                ];
            }
        );
    }

    /**
     * @return array{
     *   year: int,
     *   labels: array<int, string>,
     *   seconds: array<int, int>,
     *   fallback_count: int,
     *   quota_limited_count: int,
     *   synced_at: string
     * }
     */
    public function getMonthlyEvolution(int $year, ?CarbonImmutable $today = null): array
    {
        $today = $today ?? CarbonImmutable::today(config('app.timezone'));
        $workspaceId = $this->workspaceId();
        $cacheKey = sprintf('toggl.monthly.evolution.v4.%d.%d', $workspaceId, $year);

        return Cache::remember(
            $cacheKey,
            now()->addMinutes((int) config('toggl.cache_ttl_minutes', 10)),
            function () use ($year, $today, $workspaceId): array {
                $labels = [];
                $seconds = [];
                $latestSyncedAt = null;
                $fallbackCount = 0;
                $quotaLimitedCount = 0;

                for ($month = 1; $month <= 12; $month++) {
                    $monthStart = CarbonImmutable::create($year, $month, 1, 0, 0, 0, config('app.timezone'))->startOfMonth();
                    $monthEnd = $monthStart->endOfMonth();

                    $labels[] = $monthStart->locale('fr')->isoFormat('MMM');
                    if ($monthStart->gt($today)) {
                        $seconds[] = 0;
                        continue;
                    }

                    $effectiveEnd = $monthEnd->gt($today) ? $today : $monthEnd;
                    $snapshot = $this->syncPeriodSnapshot($workspaceId, $monthStart, $effectiveEnd);
                    $seconds[] = $this->resolveSnapshotTotalSeconds(
                        $workspaceId,
                        $monthStart,
                        $effectiveEnd,
                        $snapshot
                    );
                    if ($this->isFallbackSnapshot($snapshot)) {
                        $fallbackCount++;
                    }
                    if ($this->isQuotaLimitedSnapshot($snapshot)) {
                        $quotaLimitedCount++;
                    }

                    $latestSyncedAt = $latestSyncedAt === null || $snapshot->synced_at->gt($latestSyncedAt)
                        ? $snapshot->synced_at
                        : $latestSyncedAt;
                }

                return [
                    'year' => $year,
                    'labels' => $labels,
                    'seconds' => $seconds,
                    'fallback_count' => $fallbackCount,
                    'quota_limited_count' => $quotaLimitedCount,
                    'synced_at' => $latestSyncedAt?->toIso8601String() ?? now()->toIso8601String(),
                ];
            }
        );
    }

    /**
     * @return array{
     *   labels: array<int, string>,
     *   seconds: array<int, int>,
     *   fallback_count: int,
     *   quota_limited_count: int,
     *   synced_at: string
     * }
     */
    public function getYearlyEvolution(int $fromYear, int $toYear, ?CarbonImmutable $today = null): array
    {
        if ($toYear < $fromYear) {
            throw new RuntimeException('Invalid year range.');
        }

        $today = $today ?? CarbonImmutable::today(config('app.timezone'));
        $workspaceId = $this->workspaceId();
        $cacheKey = sprintf('toggl.yearly.evolution.v4.%d.%d.%d', $workspaceId, $fromYear, $toYear);

        return Cache::remember(
            $cacheKey,
            now()->addMinutes((int) config('toggl.cache_ttl_minutes', 10)),
            function () use ($fromYear, $toYear, $today, $workspaceId): array {
                $labels = [];
                $seconds = [];
                $latestSyncedAt = null;
                $fallbackCount = 0;
                $quotaLimitedCount = 0;

                for ($year = $fromYear; $year <= $toYear; $year++) {
                    $yearStart = CarbonImmutable::create($year, 1, 1, 0, 0, 0, config('app.timezone'))->startOfYear();
                    $yearEnd = $yearStart->endOfYear();

                    $labels[] = (string) $year;
                    if ($yearStart->gt($today)) {
                        $seconds[] = 0;
                        continue;
                    }

                    $effectiveEnd = $yearEnd->gt($today) ? $today : $yearEnd;
                    $snapshot = $this->syncPeriodSnapshot($workspaceId, $yearStart, $effectiveEnd);
                    $seconds[] = $this->resolveSnapshotTotalSeconds(
                        $workspaceId,
                        $yearStart,
                        $effectiveEnd,
                        $snapshot
                    );
                    if ($this->isFallbackSnapshot($snapshot)) {
                        $fallbackCount++;
                    }
                    if ($this->isQuotaLimitedSnapshot($snapshot)) {
                        $quotaLimitedCount++;
                    }

                    $latestSyncedAt = $latestSyncedAt === null || $snapshot->synced_at->gt($latestSyncedAt)
                        ? $snapshot->synced_at
                        : $latestSyncedAt;
                }

                return [
                    'labels' => $labels,
                    'seconds' => $seconds,
                    'fallback_count' => $fallbackCount,
                    'quota_limited_count' => $quotaLimitedCount,
                    'synced_at' => $latestSyncedAt?->toIso8601String() ?? now()->toIso8601String(),
                ];
            }
        );
    }

    /**
     * @return array{
     *   start_date: string,
     *   end_date: string,
     *   synced_days: int,
      *   missing_days: int,
     *   fallback_days: int,
     *   quota_limited_days: int,
     *   days: array<int, array{date: string, seconds: int, synced: bool}>
     * }
     */
    public function getDailyHeatmap(
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        int $maxOnDemandSync = 0,
        ?CarbonImmutable $today = null
    ): array {
        $this->assertValidPeriod($periodStart, $periodEnd);
        $workspaceId = $this->workspaceId();
        $today = $today ?? CarbonImmutable::today(config('app.timezone'));
        $allDates = $this->buildDateRange($periodStart, $periodEnd);

        $existingSnapshots = $this->fetchExistingDailySnapshots($workspaceId, $periodStart, $periodEnd);
        $secondsByDate = [];
        foreach ($existingSnapshots as $snapshot) {
            if ($this->isManualTimeflipZeroPlaceholderSnapshot($snapshot)) {
                continue;
            }

            $date = $snapshot->window_start_date->toDateString();
            $secondsByDate[$date] = max(0, (int) $snapshot->total_tracked_seconds);
        }

        $datesToSync = [];
        foreach ($allDates as $date) {
            if (!array_key_exists($date, $secondsByDate) && $date <= $today->toDateString()) {
                $datesToSync[] = $date;
            }
        }

        $todayKey = $today->toDateString();
        if (
            $periodStart->lte($today)
            && $periodEnd->gte($today)
            && !$this->isTodaySnapshotFresh($workspaceId, $today)
            && !in_array($todayKey, $datesToSync, true)
        ) {
            $datesToSync[] = $todayKey;
        }

        $syncedDays = 0;
        $syncBudget = max(0, $maxOnDemandSync);
        $fallbackDays = 0;
        $quotaLimitedDays = 0;
        foreach ($datesToSync as $date) {
            if ($syncedDays >= $syncBudget) {
                break;
            }

            $day = CarbonImmutable::parse($date, config('app.timezone'))->startOfDay();
            $snapshot = $this->syncPeriodSnapshot($workspaceId, $day, $day);
            if ($this->isFallbackSnapshot($snapshot)) {
                $fallbackDays++;
                if ($this->isQuotaLimitedSnapshot($snapshot)) {
                    $quotaLimitedDays++;
                    break;
                }

                continue;
            }

            $secondsByDate[$date] = max(0, (int) $snapshot->total_tracked_seconds);
            if ($this->isQuotaLimitedSnapshot($snapshot)) {
                $quotaLimitedDays++;
                break;
            }
            $syncedDays++;
        }

        $missingDays = 0;
        foreach ($allDates as $date) {
            if ($date > $today->toDateString()) {
                continue;
            }

            if (!array_key_exists($date, $secondsByDate)) {
                $missingDays++;
            }
        }

        $days = [];
        foreach ($allDates as $date) {
            $isSynced = array_key_exists($date, $secondsByDate);
            $days[] = [
                'date' => $date,
                'seconds' => $secondsByDate[$date] ?? 0,
                'synced' => $isSynced,
            ];
        }

        return [
            'start_date' => $periodStart->toDateString(),
            'end_date' => $periodEnd->toDateString(),
            'synced_days' => $syncedDays,
            'missing_days' => $missingDays,
            'fallback_days' => $fallbackDays,
            'quota_limited_days' => $quotaLimitedDays,
            'days' => $days,
        ];
    }

    /**
     * @return array{
     *   years_synced: int,
     *   months_synced: int,
     *   days_synced: int,
     *   quota_limited: bool
     * }
     */
    public function warmupDashboardSnapshots(
        ?CarbonImmutable $today = null,
        ?int $historyYears = null,
        ?int $dailyDays = null
    ): array {
        $today = $today ?? CarbonImmutable::today(config('app.timezone'));
        $historyYears = max(1, $historyYears ?? (int) config('toggl.history_years', 5));
        $dailyDays = max(0, $dailyDays ?? (int) config('toggl.warmup_daily_days', 120));
        $workspaceId = $this->workspaceId();

        $yearsSynced = 0;
        $monthsSynced = 0;
        $daysSynced = 0;
        $quotaLimited = false;
        $fromYear = (int) $today->year - ($historyYears - 1);

        for ($year = $fromYear; $year <= (int) $today->year; $year++) {
            $yearStart = CarbonImmutable::create($year, 1, 1, 0, 0, 0, config('app.timezone'))->startOfYear();
            $yearEnd = $year === (int) $today->year ? $today : $yearStart->endOfYear();

            $yearSnapshot = $this->syncPeriodSnapshot($workspaceId, $yearStart, $yearEnd);
            if ($this->isQuotaLimitedSnapshot($yearSnapshot)) {
                return [
                    'years_synced' => $yearsSynced,
                    'months_synced' => $monthsSynced,
                    'days_synced' => $daysSynced,
                    'quota_limited' => true,
                ];
            }
            $yearsSynced++;

            $maxMonth = $year === (int) $today->year ? (int) $today->month : 12;
            for ($month = 1; $month <= $maxMonth; $month++) {
                $monthStart = CarbonImmutable::create($year, $month, 1, 0, 0, 0, config('app.timezone'))->startOfMonth();
                $monthEnd = $monthStart->endOfMonth();
                $effectiveEnd = $monthEnd->gt($today) ? $today : $monthEnd;
                $monthSnapshot = $this->syncPeriodSnapshot($workspaceId, $monthStart, $effectiveEnd);
                if ($this->isQuotaLimitedSnapshot($monthSnapshot)) {
                    return [
                        'years_synced' => $yearsSynced,
                        'months_synced' => $monthsSynced,
                        'days_synced' => $daysSynced,
                        'quota_limited' => true,
                    ];
                }
                $monthsSynced++;
            }
        }

        for ($offset = 0; $offset < $dailyDays; $offset++) {
            $day = $today->subDays($offset)->startOfDay();
            $daySnapshot = $this->syncPeriodSnapshot($workspaceId, $day, $day);
            if ($this->isQuotaLimitedSnapshot($daySnapshot)) {
                $quotaLimited = true;
                break;
            }
            $daysSynced++;
        }

        return [
            'years_synced' => $yearsSynced,
            'months_synced' => $monthsSynced,
            'days_synced' => $daysSynced,
            'quota_limited' => $quotaLimited,
        ];
    }

    private function syncPeriodSnapshot(
        int $workspaceId,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd
    ): TogglSyncSnapshot {
        $snapshot = TogglSyncSnapshot::query()
            ->where('workspace_id', $workspaceId)
            ->whereDate('window_start_date', $periodStart->toDateString())
            ->whereDate('window_end_date', $periodEnd->toDateString())
            ->first();

        $isClosedPeriod = $periodEnd->lt(CarbonImmutable::today(config('app.timezone')));
        if ($snapshot !== null) {
            $isFallbackSnapshot = $this->isFallbackSnapshot($snapshot);
            $isDailyRollupSnapshot = $this->isDailyRollupSnapshot($snapshot);
            $isManualTimeflipZeroPlaceholderSnapshot = $this->isManualTimeflipZeroPlaceholderSnapshot($snapshot);
            $isClosedSnapshotFinal = $this->isClosedPeriodSnapshotFinal($snapshot, $periodEnd);

            if (
                !$isFallbackSnapshot
                && !$isDailyRollupSnapshot
                && !$isManualTimeflipZeroPlaceholderSnapshot
                && ($isClosedSnapshotFinal || $this->isSnapshotFresh($snapshot))
            ) {
                return $snapshot;
            }
        }

        try {
            $payload = $this->fetchSummary($workspaceId, $periodStart, $periodEnd);
            $totalTrackedSeconds = $this->extractTotalTrackedSeconds($payload);
        } catch (Throwable $throwable) {
            report($throwable);

            if (
                $snapshot !== null
                && !$this->isFallbackSnapshot($snapshot)
                && !$this->isManualTimeflipZeroPlaceholderSnapshot($snapshot)
            ) {
                return $snapshot;
            }

            if ($isClosedPeriod) {
                $dailyRollupSnapshot = $this->buildSnapshotFromDailyRollup($workspaceId, $periodStart, $periodEnd);
                if ($dailyRollupSnapshot !== null) {
                    return $dailyRollupSnapshot;
                }
            }

            return $this->buildFallbackSnapshot($workspaceId, $periodStart, $periodEnd, $throwable);
        }

        /** @var TogglSyncSnapshot $snapshot */
        $snapshot = TogglSyncSnapshot::query()->updateOrCreate(
            [
                'workspace_id' => $workspaceId,
                'window_start_date' => $periodStart->toDateString(),
                'window_end_date' => $periodEnd->toDateString(),
            ],
            [
                'total_tracked_seconds' => $totalTrackedSeconds,
                'raw_payload' => $payload,
                'synced_at' => now(),
            ]
        );

        return $snapshot;
    }

    /**
     * @return array<int, TogglSyncSnapshot>
     */
    private function fetchExistingDailySnapshots(
        int $workspaceId,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd
    ): array {
        /** @var array<int, TogglSyncSnapshot> $snapshots */
        $snapshots = TogglSyncSnapshot::query()
            ->where('workspace_id', $workspaceId)
            ->whereColumn('window_start_date', 'window_end_date')
            ->whereBetween('window_start_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->get()
            ->all();

        return $snapshots;
    }

    private function buildSnapshotFromDailyRollup(
        int $workspaceId,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd
    ): ?TogglSyncSnapshot {
        $dailySnapshots = $this->fetchExistingDailySnapshots($workspaceId, $periodStart, $periodEnd);
        $expectedDates = $this->buildDateRange($periodStart, $periodEnd);
        $expectedDays = count($expectedDates);
        if (count($dailySnapshots) !== $expectedDays) {
            return null;
        }

        $secondsByDate = [];
        foreach ($dailySnapshots as $dailySnapshot) {
            if ($this->isManualTimeflipZeroPlaceholderSnapshot($dailySnapshot)) {
                return null;
            }

            $date = $dailySnapshot->window_start_date->toDateString();
            $secondsByDate[$date] = max(0, (int) $dailySnapshot->total_tracked_seconds);
        }
        if (count($secondsByDate) !== $expectedDays) {
            return null;
        }

        $totalTrackedSeconds = 0;
        foreach ($expectedDates as $date) {
            if (!array_key_exists($date, $secondsByDate)) {
                return null;
            }

            $totalTrackedSeconds += (int) $secondsByDate[$date];
        }

        /** @var TogglSyncSnapshot $rollupSnapshot */
        $rollupSnapshot = TogglSyncSnapshot::query()->updateOrCreate(
            [
                'workspace_id' => $workspaceId,
                'window_start_date' => $periodStart->toDateString(),
                'window_end_date' => $periodEnd->toDateString(),
            ],
            [
                'total_tracked_seconds' => $totalTrackedSeconds,
                'raw_payload' => [
                    'source' => 'daily_rollup',
                    'derived_from_daily' => true,
                    'daily_snapshot_count' => $expectedDays,
                ],
                'synced_at' => now(),
            ]
        );

        return $rollupSnapshot;
    }

    private function isTodaySnapshotFresh(int $workspaceId, CarbonImmutable $today): bool
    {
        $snapshot = TogglSyncSnapshot::query()
            ->where('workspace_id', $workspaceId)
            ->whereDate('window_start_date', $today->toDateString())
            ->whereDate('window_end_date', $today->toDateString())
            ->first();

        return $snapshot !== null && $this->isSnapshotFresh($snapshot);
    }

    private function isSnapshotFresh(TogglSyncSnapshot $snapshot): bool
    {
        $syncTtlMinutes = (int) config('toggl.sync_ttl_minutes', 240);

        return $snapshot->synced_at !== null
            && $snapshot->synced_at->greaterThan(now()->subMinutes($syncTtlMinutes));
    }

    private function isClosedPeriodSnapshotFinal(
        TogglSyncSnapshot $snapshot,
        CarbonImmutable $periodEnd
    ): bool {
        if ($snapshot->synced_at === null) {
            return false;
        }

        $today = CarbonImmutable::today(config('app.timezone'));
        if (!$periodEnd->lt($today)) {
            return false;
        }

        // If the period is already closed, keep the cached snapshot only when it
        // was synced after the period end. This avoids freezing "yesterday"
        // with an early partial value (e.g. sync before midnight).
        return $snapshot->synced_at->greaterThanOrEqualTo($periodEnd->endOfDay());
    }

    private function buildFallbackSnapshot(
        int $workspaceId,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        ?Throwable $throwable = null
    ): TogglSyncSnapshot {
        $statusCode = null;
        $quotaLimited = false;

        if ($throwable instanceof RequestException) {
            $statusCode = $throwable->response?->status();
            $responseBody = strtolower((string) $throwable->response?->body());
            $quotaLimited = $statusCode === 402
                || str_contains($responseBody, 'hourly limit')
                || str_contains($responseBody, 'quota');
        }

        return new TogglSyncSnapshot([
            'workspace_id' => $workspaceId,
            'window_start_date' => $periodStart->toDateString(),
            'window_end_date' => $periodEnd->toDateString(),
            'total_tracked_seconds' => 0,
            'raw_payload' => [
                'error' => 'snapshot_fallback',
                'status_code' => $statusCode,
                'quota_limited' => $quotaLimited,
                'message' => $throwable?->getMessage(),
            ],
            'synced_at' => now(),
        ]);
    }

    private function isFallbackSnapshot(TogglSyncSnapshot $snapshot): bool
    {
        return is_array($snapshot->raw_payload)
            && ($snapshot->raw_payload['error'] ?? null) === 'snapshot_fallback';
    }

    private function isDailyRollupSnapshot(TogglSyncSnapshot $snapshot): bool
    {
        return is_array($snapshot->raw_payload)
            && ($snapshot->raw_payload['source'] ?? null) === 'daily_rollup';
    }

    private function isManualTimeflipZeroPlaceholderSnapshot(TogglSyncSnapshot $snapshot): bool
    {
        if ((int) $snapshot->total_tracked_seconds !== 0 || !is_array($snapshot->raw_payload)) {
            return false;
        }

        if (($snapshot->raw_payload['source'] ?? null) === 'timeflip_csv') {
            return true;
        }

        $manualSeconds = $snapshot->raw_payload['manual_imports']['timeflip_csv']['seconds'] ?? null;

        return is_numeric($manualSeconds) && (int) $manualSeconds === 0;
    }

    private function isQuotaLimitedSnapshot(TogglSyncSnapshot $snapshot): bool
    {
        return is_array($snapshot->raw_payload)
            && (bool) ($snapshot->raw_payload['quota_limited'] ?? false) === true;
    }

    /**
     * @return array<int, string>
     */
    private function fetchDailySnapshotDates(
        int $workspaceId,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate
    ): array {
        /** @var array<int, TogglSyncSnapshot> $snapshots */
        $snapshots = TogglSyncSnapshot::query()
            ->where('workspace_id', $workspaceId)
            ->whereColumn('window_start_date', 'window_end_date')
            ->whereBetween('window_start_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->get()
            ->all();

        $dates = [];
        foreach ($snapshots as $snapshot) {
            if ($this->isManualTimeflipZeroPlaceholderSnapshot($snapshot)) {
                continue;
            }

            $dates[] = $snapshot->window_start_date->toDateString();
        }

        $dates = array_values(array_unique($dates));
        sort($dates);

        return $dates;
    }

    /**
     * @return array<int, int>
     */
    private function fetchDailyTotalsByYear(int $workspaceId): array
    {
        /** @var array<int, TogglSyncSnapshot> $snapshots */
        $snapshots = TogglSyncSnapshot::query()
            ->where('workspace_id', $workspaceId)
            ->whereColumn('window_start_date', 'window_end_date')
            ->get()
            ->all();

        $daysByYear = [];
        foreach ($snapshots as $snapshot) {
            if ($this->isManualTimeflipZeroPlaceholderSnapshot($snapshot)) {
                continue;
            }

            $year = (int) $snapshot->window_start_date->year;
            $date = $snapshot->window_start_date->toDateString();
            if (!isset($daysByYear[$year])) {
                $daysByYear[$year] = [];
            }

            $daysByYear[$year][$date] = true;
        }

        ksort($daysByYear);

        $syncedDaysByYear = [];
        foreach ($daysByYear as $year => $days) {
            $syncedDaysByYear[(int) $year] = count($days);
        }

        return $syncedDaysByYear;
    }

    private function isCompleteMonthSnapshot(CarbonImmutable $start, CarbonImmutable $end): bool
    {
        return $start->day === 1
            && $start->year === $end->year
            && $start->month === $end->month
            && $end->isSameDay($start->endOfMonth());
    }

    private function isCompleteYearSnapshot(CarbonImmutable $start, CarbonImmutable $end): bool
    {
        return $start->day === 1
            && $start->month === 1
            && $start->year === $end->year
            && $end->isSameDay($start->endOfYear());
    }

    private function assertValidPeriod(CarbonImmutable $periodStart, CarbonImmutable $periodEnd): void
    {
        if ($periodEnd->lt($periodStart)) {
            throw new RuntimeException('Invalid period: end date must be greater than or equal to start date.');
        }
    }

    /**
     * @return array<int, string>
     */
    private function buildDateRange(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $dates = [];
        $cursor = $start->startOfDay();
        $end = $end->startOfDay();

        while ($cursor->lte($end)) {
            $dates[] = $cursor->toDateString();
            $cursor = $cursor->addDay();
        }

        return $dates;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchSummary(
        int $workspaceId,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        ?string $grouping = null
    ): array {
        $response = Http::acceptJson()
            ->withBasicAuth($this->apiToken(), 'api_token')
            ->timeout((int) config('toggl.timeout_seconds', 20))
            ->retry(2, 300)
            ->post($this->summaryUrl($workspaceId), [
                'start_date' => $periodStart->toDateString(),
                'end_date' => $periodEnd->toDateString(),
                'grouping' => $grouping ?? config('toggl.summary_grouping', 'users'),
            ]);

        $response->throw();

        $decoded = $response->json();
        if (!is_array($decoded)) {
            throw new RuntimeException('Unexpected Toggl response payload.');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractTotalTrackedSeconds(array $payload): int
    {
        foreach (['total_tracked_seconds', 'tracked_seconds', 'total_seconds', 'seconds'] as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key])) {
                return max(0, (int) round((float) $payload[$key]));
            }
        }

        $items = $payload['items'] ?? $payload['data'] ?? null;
        if (is_array($items)) {
            $sum = 0;
            foreach ($items as $item) {
                if (is_array($item)) {
                    $sum += $this->extractTrackedSecondsFromItem($item);
                }
            }

            if ($sum > 0) {
                return $sum;
            }
        }

        return $this->extractTrackedSecondsFromItem($payload);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function extractTrackedSecondsFromItem(array $item): int
    {
        foreach (['tracked_seconds', 'total_tracked_seconds', 'total_seconds', 'seconds'] as $key) {
            if (isset($item[$key]) && is_numeric($item[$key])) {
                return max(0, (int) round((float) $item[$key]));
            }
        }

        $sum = 0;
        foreach ($item as $value) {
            if (is_array($value)) {
                $sum += $this->sumNestedTrackedSeconds($value);
            }
        }

        return max(0, $sum);
    }

    /**
     * @param array<int|string, mixed> $node
     */
    private function sumNestedTrackedSeconds(array $node): int
    {
        $sum = 0;

        foreach ($node as $key => $value) {
            if (
                is_string($key)
                && in_array($key, ['tracked_seconds', 'total_tracked_seconds', 'total_seconds', 'seconds'], true)
                && is_numeric($value)
            ) {
                $sum += (int) round((float) $value);
                continue;
            }

            if (is_array($value)) {
                $sum += $this->sumNestedTrackedSeconds($value);
            }
        }

        return max(0, $sum);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array{project: string, client: string, seconds: int}>
     */
    private function extractProjectBreakdownRows(array $payload): array
    {
        $rows = [];
        $items = $payload['items'] ?? $payload['data'] ?? null;

        if (is_array($items)) {
            $rows = $this->extractProjectRowsFromNode($items);
        }

        if ($rows !== []) {
            return $rows;
        }

        return $this->extractProjectRowsFromNode($payload);
    }

    /**
     * @param array<int|string, mixed> $node
     * @return array<int, array{project: string, client: string, seconds: int}>
     */
    private function extractProjectRowsFromNode(array $node): array
    {
        $rows = [];

        if (array_is_list($node)) {
            foreach ($node as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $parsed = $this->extractProjectRowFromItem($item);
                if ($parsed !== null) {
                    $rows[] = $parsed;
                    continue;
                }

                $rows = array_merge($rows, $this->extractProjectRowsFromNode($item));
            }

            return $rows;
        }

        $parsed = $this->extractProjectRowFromItem($node);
        if ($parsed !== null) {
            $rows[] = $parsed;
        }

        foreach ($node as $value) {
            if (!is_array($value)) {
                continue;
            }

            $rows = array_merge($rows, $this->extractProjectRowsFromNode($value));
        }

        return $rows;
    }

    /**
     * @param array<int|string, mixed> $item
     * @return array{project: string, client: string, seconds: int}|null
     */
    private function extractProjectRowFromItem(array $item): ?array
    {
        $seconds = $this->extractTrackedSecondsFromItem($item);
        if ($seconds <= 0) {
            return null;
        }

        $projectName = $this->resolveProjectName($item);
        if ($projectName === null) {
            return null;
        }

        return [
            'project' => $projectName,
            'client' => $this->resolveClientName($item) ?? 'Sans client',
            'seconds' => $seconds,
        ];
    }

    /**
     * @param array<int|string, mixed> $item
     */
    private function resolveProjectName(array $item): ?string
    {
        foreach (['project', 'project_name', 'name', 'title', 'group'] as $key) {
            if (!array_key_exists($key, $item)) {
                continue;
            }

            $resolved = $this->extractLabelValue($item[$key], ['project', 'project_name', 'name', 'title']);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * @param array<int|string, mixed> $item
     */
    private function resolveClientName(array $item): ?string
    {
        foreach (['client', 'client_name'] as $key) {
            if (!array_key_exists($key, $item)) {
                continue;
            }

            $resolved = $this->extractLabelValue($item[$key], ['client', 'client_name', 'name', 'title']);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        foreach (['title', 'project'] as $containerKey) {
            if (!isset($item[$containerKey]) || !is_array($item[$containerKey])) {
                continue;
            }

            $resolved = $this->extractLabelValue($item[$containerKey], ['client', 'client_name', 'name']);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * @param mixed $value
     * @param array<int, string> $preferredKeys
     */
    private function extractLabelValue(mixed $value, array $preferredKeys): ?string
    {
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed !== '' ? $trimmed : null;
        }

        if (!is_array($value)) {
            return null;
        }

        foreach ($preferredKeys as $key) {
            if (!array_key_exists($key, $value) || !is_scalar($value[$key])) {
                continue;
            }

            $trimmed = trim((string) $value[$key]);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return null;
    }

    private function resolveClientByMatchingRules(string $projectName, string $currentClientName): string
    {
        $matchingConfig = config('toggl.client_matching');
        if (!is_array($matchingConfig) || !((bool) ($matchingConfig['enabled'] ?? false))) {
            return $currentClientName;
        }

        $applyWhenMissingOnly = (bool) ($matchingConfig['apply_when_missing_client_only'] ?? true);
        if ($applyWhenMissingOnly && !$this->isMissingClientLabel($currentClientName, $matchingConfig)) {
            return $currentClientName;
        }

        $rules = $matchingConfig['rules'] ?? [];
        if (!is_array($rules)) {
            return $currentClientName;
        }

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $targetClient = trim((string) ($rule['client'] ?? ''));
            if ($targetClient === '') {
                continue;
            }

            if ($this->projectMatchesClientRule($projectName, $rule)) {
                return $targetClient;
            }
        }

        return $currentClientName;
    }

    /**
     * @param array<string, mixed> $matchingConfig
     */
    private function isMissingClientLabel(string $clientName, array $matchingConfig): bool
    {
        $normalizedClientName = $this->normalizeForMatching($clientName);
        $missingLabels = $matchingConfig['missing_client_labels'] ?? [];
        if (!is_array($missingLabels)) {
            return $normalizedClientName === '';
        }

        $normalizedMissingLabels = [];
        foreach ($missingLabels as $label) {
            if (!is_scalar($label)) {
                continue;
            }

            $normalizedMissingLabels[] = $this->normalizeForMatching((string) $label);
        }

        if ($normalizedMissingLabels === []) {
            return $normalizedClientName === '';
        }

        return in_array($normalizedClientName, $normalizedMissingLabels, true);
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function projectMatchesClientRule(string $projectName, array $rule): bool
    {
        $normalizedProjectName = $this->normalizeForMatching($projectName);
        if ($normalizedProjectName === '') {
            return false;
        }

        $exactMatches = $rule['project_exact'] ?? [];
        if (is_array($exactMatches)) {
            foreach ($exactMatches as $exactMatch) {
                if (!is_scalar($exactMatch)) {
                    continue;
                }

                if ($normalizedProjectName === $this->normalizeForMatching((string) $exactMatch)) {
                    return true;
                }
            }
        }

        $containsMatches = $rule['project_contains'] ?? [];
        if (is_array($containsMatches)) {
            foreach ($containsMatches as $containsMatch) {
                if (!is_scalar($containsMatch)) {
                    continue;
                }

                $normalizedNeedle = $this->normalizeForMatching((string) $containsMatch);
                if ($normalizedNeedle === '') {
                    continue;
                }

                if (mb_strpos($normalizedProjectName, $normalizedNeedle) !== false) {
                    return true;
                }
            }
        }

        $regexMatches = $rule['project_regex'] ?? [];
        if (is_array($regexMatches)) {
            foreach ($regexMatches as $regexMatch) {
                if (!is_string($regexMatch) || $regexMatch === '') {
                    continue;
                }

                if ($this->safeRegexMatch($regexMatch, $projectName)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function normalizeForMatching(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    private function resolveSnapshotTotalSeconds(
        int $workspaceId,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        TogglSyncSnapshot $snapshot
    ): int {
        $baseSeconds = max(0, (int) $snapshot->total_tracked_seconds);
        if ($periodStart->isSameDay($periodEnd) || $this->isDailyRollupSnapshot($snapshot)) {
            return $baseSeconds;
        }

        $manualSeconds = $this->sumManualImportedSeconds($workspaceId, $periodStart, $periodEnd);

        return $baseSeconds + $manualSeconds;
    }

    private function sumManualImportedSeconds(
        int $workspaceId,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd
    ): int {
        $manualSeconds = 0;
        $dailySnapshots = $this->fetchExistingDailySnapshots($workspaceId, $periodStart, $periodEnd);
        foreach ($dailySnapshots as $snapshot) {
            if (!is_array($snapshot->raw_payload)) {
                continue;
            }

            $rawSeconds = $snapshot->raw_payload['manual_imports']['timeflip_csv']['seconds'] ?? null;
            if (!is_numeric($rawSeconds)) {
                continue;
            }

            $manualSeconds += max(0, (int) $rawSeconds);
        }

        return $manualSeconds;
    }

    /**
     * @return array{
     *   month: array<string, int>,
     *   year: array<int, int>
     * }
     */
    private function fetchManualTimeflipTotalsByMonthAndYear(int $workspaceId): array
    {
        $monthTotals = [];
        $yearTotals = [];

        /** @var array<int, TogglSyncSnapshot> $dailySnapshots */
        $dailySnapshots = TogglSyncSnapshot::query()
            ->where('workspace_id', $workspaceId)
            ->whereColumn('window_start_date', 'window_end_date')
            ->get()
            ->all();

        foreach ($dailySnapshots as $dailySnapshot) {
            if (!is_array($dailySnapshot->raw_payload)) {
                continue;
            }

            $manualSecondsRaw = $dailySnapshot->raw_payload['manual_imports']['timeflip_csv']['seconds'] ?? null;
            if (!is_numeric($manualSecondsRaw)) {
                continue;
            }

            $manualSeconds = max(0, (int) $manualSecondsRaw);
            if ($manualSeconds <= 0) {
                continue;
            }

            $date = $dailySnapshot->window_start_date->toDateString();
            $monthKey = substr($date, 0, 7);
            $yearKey = (int) substr($date, 0, 4);
            $monthTotals[$monthKey] = ($monthTotals[$monthKey] ?? 0) + $manualSeconds;
            $yearTotals[$yearKey] = ($yearTotals[$yearKey] ?? 0) + $manualSeconds;
        }

        return [
            'month' => $monthTotals,
            'year' => $yearTotals,
        ];
    }

    private function safeRegexMatch(string $pattern, string $subject): bool
    {
        set_error_handler(static fn (): bool => true);
        try {
            $result = preg_match($pattern, $subject);
        } finally {
            restore_error_handler();
        }

        return $result === 1;
    }

    private function isQuotaLimitedThrowable(Throwable $throwable): bool
    {
        if (!$throwable instanceof RequestException) {
            return false;
        }

        $statusCode = $throwable->response?->status();
        $responseBody = strtolower((string) $throwable->response?->body());

        return $statusCode === 402
            || str_contains($responseBody, 'hourly limit')
            || str_contains($responseBody, 'quota');
    }

    private function summaryUrl(int $workspaceId): string
    {
        $endpointPattern = (string) config('toggl.summary_endpoint', '/reports/api/v3/workspace/%d/summary/time_entries');
        $endpoint = sprintf($endpointPattern, $workspaceId);
        $baseUrl = rtrim((string) config('toggl.base_url', 'https://api.track.toggl.com'), '/');

        return $baseUrl.'/'.ltrim($endpoint, '/');
    }

    private function workspaceId(): int
    {
        $workspaceId = (int) config('toggl.workspace_id', 0);
        if ($workspaceId <= 0) {
            throw new RuntimeException('TOGGL_WORKSPACE_ID is missing or invalid.');
        }

        return $workspaceId;
    }

    private function apiToken(): string
    {
        $apiToken = (string) config('toggl.api_token', '');
        if ($apiToken === '') {
            throw new RuntimeException('TOGGL_API_TOKEN is missing.');
        }

        return $apiToken;
    }
}
