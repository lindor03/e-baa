<?php

use Illuminate\Support\Facades\Route;
use RaiAccept\Http\Controllers\PaymentController;

Route::group([
    'middleware' => ['web', 'theme', 'locale', 'currency'],
    'prefix'     => 'raiaccept',
], function () {
    Route::get('/redirect', [PaymentController::class, 'redirect'])
        ->name('raiaccept.redirect');

    Route::match(['GET', 'POST'], '/return', [PaymentController::class, 'return'])
        ->name('raiaccept.return');

    Route::match(['GET', 'POST'], '/notify', [PaymentController::class, 'notify'])
        ->name('raiaccept.notify');
});
