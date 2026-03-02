<?php

use Illuminate\Support\Facades\Route;
use Webkul\CustomPromotions\Http\Controllers\Api\PromotionController;

Route::group([
    'middleware' => ['api'],
    'prefix'     => 'api/custom-promotions',
], function () {
    Route::get('', [PromotionController::class, 'index'])->name('api.custompromotions.index');
    Route::get('/simple', [PromotionController::class, 'simpleList'])->name('api.custompromotions.simple');
});
