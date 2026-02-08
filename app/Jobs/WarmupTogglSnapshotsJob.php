<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\TogglService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use romanzipp\QueueMonitor\Traits\IsMonitored;

class WarmupTogglSnapshotsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use IsMonitored;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 1200;

    public function __construct(
        public readonly int $historyYears,
        public readonly int $dailyDays,
        public readonly string $trigger = 'scheduler'
    ) {
        $this->onQueue('default');
    }

    /**
     * @return array<string, int|string>
     */
    public function initialMonitorData(): array
    {
        return [
            'trigger' => $this->trigger,
            'history_years' => $this->historyYears,
            'daily_days' => $this->dailyDays,
        ];
    }

    public function handle(TogglService $togglService): void
    {
        $this->queueProgress(5);

        $recap = $togglService->warmupDashboardSnapshotsWithRecap(
            null,
            $this->historyYears,
            $this->dailyDays
        );

        $this->queueData([
            'trigger' => $this->trigger,
            'years_synced' => (int) $recap['years_synced'],
            'months_synced' => (int) $recap['months_synced'],
            'days_synced' => (int) $recap['days_synced'],
            'quota_limited' => (bool) $recap['quota_limited'],
            'new_daily_days' => (int) $recap['new_daily_days'],
            'new_daily_days_first' => $recap['new_daily_days_first'],
            'new_daily_days_last' => $recap['new_daily_days_last'],
        ], true);

        $this->queueProgress(100);

        Log::info('Toggl warmup recap', array_merge($recap, [
            'trigger' => $this->trigger,
            'mode' => 'queued_job',
        ]));
    }
}
