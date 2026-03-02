<?php

use Illuminate\Support\Facades\Route;
use Webkul\CustomSettings\Http\Controllers\Shop\CustomSettingsController;

Route::group(['middleware' => ['web', 'theme', 'locale', 'currency'], 'prefix' => 'customsettings'], function () {
    Route::get('', [CustomSettingsController::class, 'index'])->name('shop.customsettings.index');
});