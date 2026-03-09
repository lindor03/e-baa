<?php

use Illuminate\Support\Facades\Route;
use RaiAccept\Http\Controllers\PaymentController;

Route::group(['middleware' => ['web']], function () {

    Route::get('/raiaccept/redirect', [PaymentController::class, 'redirect'])
        ->name('raiaccept.redirect');

    Route::get('/raiaccept/callback', [PaymentController::class, 'callback'])
        ->name('raiaccept.callback');

});
