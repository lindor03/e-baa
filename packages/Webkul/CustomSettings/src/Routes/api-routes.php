<?php

use Illuminate\Support\Facades\Route;
use Webkul\CustomSettings\Http\Controllers\Api\ColorApiController;

Route::group([
    'prefix' => 'api/custom-settings',
    'middleware' => ['api'], // optional: add auth if needed
], function () {
    Route::get('/colors', [ColorApiController::class, 'index']);
});
