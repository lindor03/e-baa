<?php

use Illuminate\Support\Facades\Route;
use Webkul\RestApi\Http\Controllers\V2\Shop\Catalog\AttributeController;
use Webkul\RestApi\Http\Controllers\V2\Shop\Catalog\AttributeFamilyController;
use Webkul\RestApi\Http\Controllers\V2\Shop\Catalog\CategoryController;
use Webkul\RestApi\Http\Controllers\V2\Shop\Catalog\ProductController;
use Webkul\RestApi\Http\Controllers\V2\Shop\Catalog\ProductReviewController;
use Webkul\RestApi\Http\Controllers\V2\Shop\Catalog\SearchController;
use Webkul\RestApi\Http\Controllers\V2\Shop\Catalog\FacetController;
use Webkul\RestApi\Http\Controllers\V2\Shop\Catalog\WidgetApiController;

/**
 * Product routes.
 */
Route::controller(ProductController::class)->prefix('products')->group(function () {
    Route::get('', 'allResources');

    Route::get('/{id}', 'getResource');

    Route::get('{id}/additional-information', 'additionalInformation');

    Route::get('{id}/configurable-config', 'configurableConfig');

    Route::get('{id}/reviews', 'reviews');


    Route::get('{id}/related', 'relatedProducts');

    Route::get('{id}/up-sell', 'upSellProducts');
});

Route::controller(SearchController::class)->prefix('search')->group(function () {

    Route::get('', [SearchController::class, 'index']);
    Route::post('image', [SearchController::class, 'upload']);

});


Route::get('filters', [FacetController::class, 'available']);


/**
 * Breadcrumb routes.
 */
Route::prefix('breadcrumbs')->group(function () {
    // Breadcrumb for a category by slug
    Route::get('category/{slug}', [CategoryController::class, 'breadcrumbsBySlug'])
        ->name('shop.api.breadcrumbs.category');

    // Breadcrumb(s) for a product by slug
    Route::get('product/{slug}', [ProductController::class, 'breadcrumbsByProductSlug'])
        ->name('shop.api.breadcrumbs.product');
});






Route::group(['middleware' => ['auth:sanctum', 'sanctum.customer']], function () {
    /**
     * Review routes.
     */
    Route::controller(ProductReviewController::class)->prefix('products')->group(function () {
        Route::post('{product_id}/reviews', 'store');
    });
});

/**
 * Category routes.
 */
Route::controller(CategoryController::class)->prefix('categories')->group(function () {
    Route::get('', 'allResources');

    Route::get('{id}', 'getResource');

});

/**
 * descendant category routes.
 */
Route::controller(CategoryController::class)->prefix('descendant-categories')->group(function () {
    Route::get('', 'descendantCategories');
});

/**
 * Attribute routes.
 */
Route::controller(AttributeController::class)->prefix('attributes')->group(function () {
    Route::get('', 'allResources');

    Route::get('{id}', 'getResource');
});

/**
 * Attribute family routes.
 */
Route::controller(AttributeFamilyController::class)->prefix('attribute-families')->group(function () {
    Route::get('', 'allResources');

    Route::get('{id}', 'getResource');
});



Route::controller(WidgetApiController::class)->prefix('widgets')->group(function () {
    Route::get('home', 'homeWidgets');
    Route::get('', 'all');
    Route::get('{id}', 'get')->whereNumber('id');
});


