<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TogglSyncSnapshot extends Model
{
    protected $fillable = [
        'workspace_id',
        'window_start_date',
        'window_end_date',
        'total_tracked_seconds',
        'raw_payload',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'window_start_date' => 'date',
            'window_end_date' => 'date',
            'total_tracked_seconds' => 'integer',
            'raw_payload' => 'array',
            'synced_at' => 'datetime',
        ];
    }
}

