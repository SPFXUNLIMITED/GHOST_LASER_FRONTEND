<?php

use Illuminate\Support\Facades\Route;
use Webkul\GhostLaser\Http\Controllers\Admin\GhostLaserController;

Route::group(['middleware' => ['web', 'admin'], 'prefix' => 'admin/ghostlaser'], function () {
    Route::controller(GhostLaserController::class)->group(function () {
        Route::get('', 'index')->name('admin.ghostlaser.index');
    });
});