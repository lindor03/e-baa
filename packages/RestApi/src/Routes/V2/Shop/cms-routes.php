<?php

use Illuminate\Support\Facades\Route;
use Webkul\RestApi\Http\Controllers\V2\Shop\Cms\PageController;

Route::controller(PageController::class)->prefix('cms/pages')->group(function () {
        Route::get('', 'index');
        Route::get('{id}', 'show');
        Route::get('slug/{slug}', 'showBySlug');
    });
