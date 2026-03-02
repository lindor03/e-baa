<?php

use Illuminate\Support\Facades\Route;
use Webkul\CustomPromotions\Http\Controllers\Admin\CustomPromotionsController;

Route::group(['middleware' => ['web', 'admin'], 'prefix' => 'admin/custompromotions'], function () {
    Route::controller(CustomPromotionsController::class)->group(function () {
        Route::get('', 'index')->name('admin.custompromotions.index');
        Route::get('create', 'create')->name('admin.custompromotions.create');
        Route::post('', 'store')->name('admin.custompromotions.store');
        Route::get('{promotion}/edit', 'edit')->name('admin.custompromotions.edit');
        Route::put('{promotion}', 'update')->name('admin.custompromotions.update');
        Route::delete('{promotion}', 'destroy')->name('admin.custompromotions.destroy');
        Route::post('apply', 'apply')->name('admin.custompromotions.apply');

        // searches
        Route::get('search/products', 'searchProducts')->name('admin.custompromotions.search.products');
        Route::get('search/products/by-categories', 'searchProductsByCategories')->name('admin.custompromotions.search.products_by_categories');
    });
});


