<?php

namespace Tests\Feature;

use App\Services\TogglService;
use Mockery\MockInterface;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_root_redirects_to_productivity_dashboard(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('cockpit.productivity'));
    }

    public function test_productivity_dashboard_is_accessible_with_mocked_toggl_service(): void
    {
        $periodMetrics = [
            'start_date' => '2026-02-01',
            'end_date' => '2026-02-07',
            'days_in_period' => 7,
            'total_seconds' => 151200,
            'daily_average_seconds' => 21600.0,
            'daily_goal_hours' => 8.0,
            'progress_ratio' => 0.75,
            'has_api_fallback' => false,
            'quota_limited' => false,
            'synced_at' => '2026-02-07T10:00:00+00:00',
        ];

        $this->mock(TogglService::class, function (MockInterface $mock) use ($periodMetrics): void {
            $mock->shouldReceive('getPeriodMetrics')
                ->andReturn($periodMetrics);

            $mock->shouldReceive('getPeriodClientProjectBreakdown')
                ->andReturn([
                    'start_date' => '2026-02-01',
                    'end_date' => '2026-02-07',
                    'total_seconds' => 151200,
                    'projects' => [
                        ['name' => 'Cockpit', 'client' => 'Interne', 'seconds' => 151200, 'hours' => '42.00', 'share_percent' => '100.0'],
                    ],
                    'clients' => [
                        ['name' => 'Interne', 'seconds' => 151200, 'hours' => '42.00', 'share_percent' => '100.0', 'project_count' => 1],
                    ],
                    'has_api_fallback' => false,
                    'quota_limited' => false,
                    'synced_at' => '2026-02-07T10:00:00+00:00',
                ]);

            $mock->shouldReceive('getAllTimeRecords')
                ->andReturn([
                    'tracking_since' => '2017-01-01',
                    'day' => ['seconds' => 54036, 'date' => '2026-02-05'],
                    'week' => ['seconds' => 252000, 'start_date' => '2026-02-02', 'end_date' => '2026-02-08'],
                    'month' => ['seconds' => 1070136, 'start_date' => '2024-12-01', 'end_date' => '2024-12-31'],
                    'year' => ['seconds' => 4463424, 'start_date' => '2024-01-01', 'end_date' => '2024-12-31'],
                ]);

            $mock->shouldReceive('getMonthlyEvolution')
                ->andReturn([
                    'year' => 2026,
                    'labels' => ['Jan', 'Fev', 'Mar', 'Avr', 'Mai', 'Jun', 'Jul', 'Aou', 'Sep', 'Oct', 'Nov', 'Dec'],
                    'seconds' => [151200, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
                    'fallback_count' => 0,
                    'quota_limited_count' => 0,
                    'synced_at' => '2026-02-07T10:00:00+00:00',
                ]);

            $mock->shouldReceive('getYearlyEvolution')
                ->andReturn([
                    'labels' => ['2022', '2023', '2024', '2025', '2026'],
                    'seconds' => [1000000, 2200000, 3000000, 2500000, 151200],
                    'fallback_count' => 0,
                    'quota_limited_count' => 0,
                    'synced_at' => '2026-02-07T10:00:00+00:00',
                ]);

            $mock->shouldReceive('getDailyHeatmap')
                ->andReturn([
                    'start_date' => '2026-02-01',
                    'end_date' => '2026-02-07',
                    'synced_days' => 2,
                    'missing_days' => 0,
                    'fallback_days' => 0,
                    'quota_limited_days' => 0,
                    'days' => [
                        ['date' => '2026-02-01', 'seconds' => 7200, 'synced' => true],
                        ['date' => '2026-02-02', 'seconds' => 14400, 'synced' => true],
                    ],
                ]);
        });

        $response = $this->get(route('cockpit.productivity'));

        $response->assertOk();
        $response->assertSee('Productivité Toggl');
        $response->assertSee('Highlights');
    }
}
