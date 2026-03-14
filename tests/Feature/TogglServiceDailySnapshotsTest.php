<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TogglSyncSnapshot;
use App\Services\TogglService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TogglServiceDailySnapshotsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('app.timezone', 'Europe/Paris');
        Config::set('toggl.api_token', 'test-token');
        Config::set('toggl.workspace_id', 123);
        Config::set('toggl.base_url', 'https://api.track.toggl.test');
        Http::preventStrayRequests();
    }

    public function test_sync_day_snapshot_splits_time_entries_across_midnight(): void
    {
        Http::fake([
            'https://api.track.toggl.test/api/v9/me/time_entries*' => Http::response([
                [
                    'id' => 1,
                    'workspace_id' => 123,
                    'start' => '2026-03-13T22:00:00+01:00',
                    'stop' => '2026-03-14T02:00:00+01:00',
                    'duration' => 14400,
                ],
            ], 200),
        ]);

        $service = app(TogglService::class);

        $march13 = $service->syncDaySnapshot(CarbonImmutable::create(2026, 3, 13, 0, 0, 0, 'Europe/Paris'));
        $march14 = $service->syncDaySnapshot(CarbonImmutable::create(2026, 3, 14, 0, 0, 0, 'Europe/Paris'));

        $this->assertSame(7200, $march13['seconds']);
        $this->assertSame(7200, $march14['seconds']);

        /** @var TogglSyncSnapshot $snapshot */
        $snapshot = TogglSyncSnapshot::query()
            ->where('workspace_id', 123)
            ->whereDate('window_start_date', '2026-03-13')
            ->whereDate('window_end_date', '2026-03-13')
            ->firstOrFail();

        $this->assertSame('time_entries_daily_split', $snapshot->raw_payload['source'] ?? null);
    }

    public function test_get_daily_heatmap_keeps_legacy_daily_snapshot_until_forced(): void
    {
        TogglSyncSnapshot::query()->create([
            'workspace_id' => 123,
            'window_start_date' => '2026-03-13',
            'window_end_date' => '2026-03-13',
            'total_tracked_seconds' => 42768,
            'raw_payload' => [
                'items' => [],
                'tracked_seconds' => 42768,
            ],
            'synced_at' => CarbonImmutable::create(2026, 3, 14, 8, 0, 0, 'Europe/Paris'),
        ]);

        Http::fake([
            'https://api.track.toggl.test/api/v9/me/time_entries*' => Http::response([
                [
                    'id' => 1,
                    'workspace_id' => 123,
                    'start' => '2026-03-13T08:00:00+01:00',
                    'stop' => '2026-03-13T16:00:00+01:00',
                    'duration' => 28800,
                ],
                [
                    'id' => 2,
                    'workspace_id' => 123,
                    'start' => '2026-03-13T18:00:00+01:00',
                    'stop' => '2026-03-14T02:00:00+01:00',
                    'duration' => 28800,
                ],
            ], 200),
        ]);

        $service = app(TogglService::class);
        $heatmap = $service->getDailyHeatmap(
            CarbonImmutable::create(2026, 3, 13, 0, 0, 0, 'Europe/Paris'),
            CarbonImmutable::create(2026, 3, 13, 0, 0, 0, 'Europe/Paris'),
            1,
            CarbonImmutable::create(2026, 3, 14, 0, 0, 0, 'Europe/Paris')
        );

        $this->assertSame(42768, $heatmap['days'][0]['seconds']);

        /** @var TogglSyncSnapshot $snapshot */
        $snapshot = TogglSyncSnapshot::query()
            ->where('workspace_id', 123)
            ->whereDate('window_start_date', '2026-03-13')
            ->whereDate('window_end_date', '2026-03-13')
            ->firstOrFail();

        $this->assertSame(42768, $snapshot->total_tracked_seconds);
        $this->assertArrayNotHasKey('source', $snapshot->raw_payload);
    }

    public function test_sync_day_snapshot_force_refreshes_legacy_daily_snapshot_with_precise_time_entries(): void
    {
        TogglSyncSnapshot::query()->create([
            'workspace_id' => 123,
            'window_start_date' => '2026-03-13',
            'window_end_date' => '2026-03-13',
            'total_tracked_seconds' => 42768,
            'raw_payload' => [
                'items' => [],
                'tracked_seconds' => 42768,
            ],
            'synced_at' => CarbonImmutable::create(2026, 3, 14, 8, 0, 0, 'Europe/Paris'),
        ]);

        Http::fake([
            'https://api.track.toggl.test/api/v9/me/time_entries*' => Http::response([
                [
                    'id' => 1,
                    'workspace_id' => 123,
                    'start' => '2026-03-13T08:00:00+01:00',
                    'stop' => '2026-03-13T16:00:00+01:00',
                    'duration' => 28800,
                ],
                [
                    'id' => 2,
                    'workspace_id' => 123,
                    'start' => '2026-03-13T18:00:00+01:00',
                    'stop' => '2026-03-14T02:00:00+01:00',
                    'duration' => 28800,
                ],
            ], 200),
        ]);

        $service = app(TogglService::class);
        $snapshot = $service->syncDaySnapshot(
            CarbonImmutable::create(2026, 3, 13, 0, 0, 0, 'Europe/Paris'),
            true
        );

        $this->assertSame(50400, $snapshot['seconds']);

        /** @var TogglSyncSnapshot $storedSnapshot */
        $storedSnapshot = TogglSyncSnapshot::query()
            ->where('workspace_id', 123)
            ->whereDate('window_start_date', '2026-03-13')
            ->whereDate('window_end_date', '2026-03-13')
            ->firstOrFail();

        $this->assertSame(50400, $storedSnapshot->total_tracked_seconds);
        $this->assertSame('time_entries_daily_split', $storedSnapshot->raw_payload['source'] ?? null);
    }

    public function test_sync_day_snapshot_preserves_existing_manual_imports(): void
    {
        TogglSyncSnapshot::query()->create([
            'workspace_id' => 123,
            'window_start_date' => '2026-03-13',
            'window_end_date' => '2026-03-13',
            'total_tracked_seconds' => 9000,
            'raw_payload' => [
                'tracked_seconds' => 7200,
                'manual_imports' => [
                    'timeflip_csv' => [
                        'seconds' => 1800,
                        'file' => 'timeflip.csv',
                    ],
                ],
            ],
            'synced_at' => CarbonImmutable::create(2026, 3, 14, 8, 0, 0, 'Europe/Paris'),
        ]);

        Http::fake([
            'https://api.track.toggl.test/api/v9/me/time_entries*' => Http::response([
                [
                    'id' => 1,
                    'workspace_id' => 123,
                    'start' => '2026-03-13T10:00:00+01:00',
                    'stop' => '2026-03-13T12:00:00+01:00',
                    'duration' => 7200,
                ],
            ], 200),
        ]);

        $service = app(TogglService::class);
        $snapshot = $service->syncDaySnapshot(CarbonImmutable::create(2026, 3, 13, 0, 0, 0, 'Europe/Paris'));

        $this->assertSame(9000, $snapshot['seconds']);

        /** @var TogglSyncSnapshot $storedSnapshot */
        $storedSnapshot = TogglSyncSnapshot::query()
            ->where('workspace_id', 123)
            ->whereDate('window_start_date', '2026-03-13')
            ->whereDate('window_end_date', '2026-03-13')
            ->firstOrFail();

        $this->assertSame(1800, $storedSnapshot->raw_payload['manual_imports']['timeflip_csv']['seconds'] ?? null);
    }
}
