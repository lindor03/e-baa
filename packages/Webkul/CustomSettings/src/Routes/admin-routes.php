<?php

use Illuminate\Support\Facades\Route;
use Webkul\CustomSettings\Http\Controllers\Admin\CustomSettingsController;


// Route::group(['middleware' => ['web', 'admin'], 'prefix' => 'admin/customsettings'], function () {
//     Route::controller(CustomSettingsController::class)->group(function () {
//         Route::get('', 'index')->name('admin.customsettings.index');
//     });
// });


Route::group(['middleware' => ['web', 'admin'], 'prefix' => 'admin/custom-settings'], function () {
    Route::get('/', [CustomSettingsController::class, 'index'])->name('admin.customsettings.index');
    Route::post('/', [CustomSettingsController::class, 'store'])->name('admin.customsettings.store');
    Route::get('/{id}/edit', [CustomSettingsController::class, 'edit'])->name('admin.customsettings.edit');
    Route::put('/{id}', [CustomSettingsController::class, 'update'])->name('admin.customsettings.update');
    Route::delete('/{id}', [CustomSettingsController::class, 'destroy'])->name('admin.customsettings.destroy');
});
