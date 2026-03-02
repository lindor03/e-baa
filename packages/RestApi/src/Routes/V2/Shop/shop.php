<?php

use Illuminate\Support\Facades\Route;

Route::group([
    'prefix'     => 'v2',
    'middleware' => ['sanctum.locale', 'sanctum.currency'],
], function () {
    /**
     * Core routes.
     */
    require 'core-routes.php';

    /**
     * Catalog routes.
     */
    require 'catalog-routes.php';

    /**
     * Customer routes.
     */
    require 'customers-routes.php';

    /**
     * Cms routes.
     */
    require 'cms-routes.php';
});
