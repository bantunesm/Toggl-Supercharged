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
     *   tracking_since: ?string,
     *   day: ?array{seconds: int, date: string},
     *   month: ?array{seconds: int, start_date: string, end_date: string},
     *   year: ?array{seconds: int, start_date: string, end_date: string}
     * }
     */
    public function getAllTimeRecords(): array
    {
        $workspaceId = $this->workspaceId();
        $cacheKey = sprintf('toggl.records.all_time.v1.%d', $workspaceId);

        return Cache::remember(
            $cacheKey,
            now()->addMinutes((int) config('toggl.cache_ttl_minutes', 10)),
            function () use ($workspaceId): array {
                $trackingSince = TogglSyncSnapshot::query()
                    ->where('workspace_id', $workspaceId)
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

                    if ($dayRecord === null && $start->isSameDay($end)) {
                        $dayRecord = [
                            'seconds' => $seconds,
                            'date' => $start->toDateString(),
                        ];
                    }

                    if ($monthRecord === null && $this->isCompleteMonthSnapshot($start, $end)) {
                        $monthRecord = [
                            'seconds' => $seconds,
                            'start_date' => $start->toDateString(),
                            'end_date' => $end->toDateString(),
                        ];
                    }

                    if ($yearRecord === null && $this->isCompleteYearSnapshot($start, $end)) {
                        $yearRecord = [
                            'seconds' => $seconds,
                            'start_date' => $start->toDateString(),
                            'end_date' => $end->toDateString(),
                        ];
                    }

                    if ($dayRecord !== null && $monthRecord !== null && $yearRecord !== null) {
                        break;
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
            'toggl.period.metrics.v2.%d.%s.%s.%s',
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
                $totalSeconds = max(0, (int) $snapshot->total_tracked_seconds);
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
            'toggl.period.breakdown.v1.%d.%s.%s',
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
                    $clientName = $row['client'];
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
        $cacheKey = sprintf('toggl.monthly.evolution.v2.%d.%d', $workspaceId, $year);

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
                    $seconds[] = max(0, (int) $snapshot->total_tracked_seconds);
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
        $cacheKey = sprintf('toggl.yearly.evolution.v2.%d.%d.%d', $workspaceId, $fromYear, $toYear);

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
                    $seconds[] = max(0, (int) $snapshot->total_tracked_seconds);
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
            if (!$isFallbackSnapshot && ($isClosedPeriod || $this->isSnapshotFresh($snapshot))) {
                return $snapshot;
            }
        }

        try {
            $payload = $this->fetchSummary($workspaceId, $periodStart, $periodEnd);
            $totalTrackedSeconds = $this->extractTotalTrackedSeconds($payload);
        } catch (Throwable $throwable) {
            report($throwable);

            if ($snapshot !== null && !$this->isFallbackSnapshot($snapshot)) {
                return $snapshot;
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

    private function isQuotaLimitedSnapshot(TogglSyncSnapshot $snapshot): bool
    {
        return is_array($snapshot->raw_payload)
            && (bool) ($snapshot->raw_payload['quota_limited'] ?? false) === true;
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
