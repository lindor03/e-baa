<?php

use Illuminate\Support\Facades\Route;
use Webkul\Widgets\Http\Controllers\Shop\WidgetsController;

Route::group(['middleware' => ['web', 'theme', 'locale', 'currency'], 'prefix' => 'widgets'], function () {
    Route::get('', [WidgetsController::class, 'index'])->name('shop.widgets.index');
});

// JSON API (un-themed)
Route::prefix('api/v1')->group(function () {
    Route::get('/widgets/{slug}', [WidgetsController::class, 'show'])->name('shop.api.widgets.show');
});
