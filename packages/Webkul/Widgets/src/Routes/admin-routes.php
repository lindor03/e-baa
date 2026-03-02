<?php

use Illuminate\Support\Facades\Route;
use Webkul\Widgets\Http\Controllers\Admin\WidgetController;

Route::group([
    'prefix'     => 'admin/widgets',
    'middleware' => ['web', 'admin'],
], function () {
    Route::get('/',        [WidgetController::class, 'index'])->name('admin.widgets.index');
    Route::get('/create',  [WidgetController::class, 'create'])->name('admin.widgets.create');
    Route::post('/',       [WidgetController::class, 'store'])->name('admin.widgets.store');
    Route::get('/{id}/edit',[WidgetController::class, 'edit'])->name('admin.widgets.edit');
    Route::put('/{id}',    [WidgetController::class, 'update'])->name('admin.widgets.update');
    Route::delete('/{id}', [WidgetController::class, 'destroy'])->name('admin.widgets.destroy');
    Route::post('reorder', [WidgetController::class, 'reorder'])->name('admin.widgets.reorder');


    // Dynamic field partial
    Route::get('/render/{type}', [WidgetController::class, 'renderForm']);

    // AJAX helpers
    Route::get('search-products',                  [WidgetController::class, 'searchProducts']);
    Route::get('search-categories',                [WidgetController::class, 'searchCategories']);
    Route::get('attributes',                       [WidgetController::class, 'getAttributes']);
    Route::get('attribute-options/{id}',           [WidgetController::class, 'getAttributeOptions']);
    Route::get('get-products-by-attribute-option/{id}', [WidgetController::class, 'getProductsByAttributeOption']);
    Route::get('get-products-by-category/{id}',    [WidgetController::class, 'getProductsByCategory']);

    Route::get('search-promotions', [WidgetController::class, 'searchPromotions'])->name('admin.widgets.search-promotions');
    Route::get('promotions/{id}', [WidgetController::class, 'getPromotion'])->name('admin.widgets.get-promotion');

});
