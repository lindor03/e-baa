<?php

use Illuminate\Support\Facades\Route;
use RaiAccept\Http\Controllers\Admin\RaiAcceptController;

Route::group(['middleware' => ['web', 'admin'], 'prefix' => 'admin/raiaccept'], function () {
    Route::controller(RaiAcceptController::class)->group(function () {
        Route::get('', 'index')->name('admin.raiaccept.index');
    });
});