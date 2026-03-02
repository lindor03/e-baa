<?php

use Illuminate\Support\Facades\Route;
use Webkul\CustomPromotions\Http\Controllers\Shop\CustomPromotionsController;

Route::group(['middleware' => ['web', 'theme', 'locale', 'currency'], 'prefix' => 'custompromotions'], function () {
    Route::get('', [CustomPromotionsController::class, 'index'])->name('shop.custompromotions.index');
});