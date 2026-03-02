<?php

// Apply the attach middleware to all Shop routes (guests still work)
Route::middleware('attach.sanctum.customer')->group(function () {
    // Shop routes.
    require 'Shop/shop.php';
});

// Admin routes (unchanged)
require 'Admin/admin.php';
