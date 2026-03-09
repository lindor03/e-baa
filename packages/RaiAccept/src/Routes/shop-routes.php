<?php

use Illuminate\Support\Facades\Route;
use RaiAccept\Http\Controllers\Shop\RaiAcceptController;
use RaiAccept\Http\Controllers\PaymentController;

Route::group([
    'middleware' => ['web', 'theme', 'locale', 'currency'],
    'prefix' => 'raiaccept'
], function () {

    Route::get('/raiaccept/redirect', [PaymentController::class, 'redirect'])
        ->name('raiaccept.redirect');

    Route::post('/raiaccept/return', [PaymentController::class, 'return'])
        ->name('raiaccept.return');

    Route::post('/raiaccept/notify', [PaymentController::class, 'notify'])
        ->name('raiaccept.notify');

});
