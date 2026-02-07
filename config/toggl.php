<?php

declare(strict_types=1);

return [
    'base_url' => env('TOGGL_BASE_URL', 'https://api.track.toggl.com'),
    'api_token' => env('TOGGL_API_TOKEN'),
    'workspace_id' => (int) env('TOGGL_WORKSPACE_ID', 0),
    'summary_endpoint' => env('TOGGL_SUMMARY_ENDPOINT', '/reports/api/v3/workspace/%d/summary/time_entries'),
    'summary_grouping' => env('TOGGL_SUMMARY_GROUPING', 'users'),
    'daily_goal_hours' => (float) env('TOGGL_DAILY_GOAL_HOURS', 8),
    'cache_ttl_minutes' => (int) env('TOGGL_CACHE_TTL_MINUTES', 10),
    'sync_ttl_minutes' => (int) env('TOGGL_SYNC_TTL_MINUTES', 240),
    'history_years' => (int) env('TOGGL_HISTORY_YEARS', 5),
    'warmup_daily_days' => (int) env('TOGGL_WARMUP_DAILY_DAYS', 120),
    'heatmap_on_demand_max_sync' => (int) env('TOGGL_HEATMAP_ON_DEMAND_MAX_SYNC', 14),
    'heatmap_month_on_demand_max_sync' => (int) env('TOGGL_HEATMAP_MONTH_ON_DEMAND_MAX_SYNC', 7),
    'warmup_schedule_time' => env('TOGGL_WARMUP_SCHEDULE_TIME', '03:10'),
    'timeout_seconds' => (int) env('TOGGL_TIMEOUT_SECONDS', 20),
];
