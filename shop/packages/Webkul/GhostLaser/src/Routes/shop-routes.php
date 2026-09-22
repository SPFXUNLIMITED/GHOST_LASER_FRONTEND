<?php

use Illuminate\Support\Facades\Route;
use Webkul\GhostLaser\Http\Controllers\Shop\GhostLaserController;

Route::group(['middleware' => ['web', 'theme', 'locale', 'currency'], 'prefix' => 'ghostlaser'], function () {
    Route::get('', [GhostLaserController::class, 'index'])->name('shop.ghostlaser.index');
});