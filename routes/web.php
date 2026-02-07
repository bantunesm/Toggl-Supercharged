<?php

declare(strict_types=1);

use App\Http\Controllers\Cockpit\ProductivityDashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('cockpit.productivity');
});

Route::get('/cockpit/productivity', ProductivityDashboardController::class)
    ->name('cockpit.productivity');
