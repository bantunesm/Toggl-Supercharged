<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('toggl_sync_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->date('window_start_date');
            $table->date('window_end_date');
            $table->unsignedBigInteger('total_tracked_seconds')->default(0);
            $table->json('raw_payload')->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(
                ['workspace_id', 'window_start_date', 'window_end_date'],
                'toggl_workspace_window_unique'
            );
            $table->index(['workspace_id', 'synced_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('toggl_sync_snapshots');
    }
};

