<?php

use Ashrafic\UspsShipping\Http\Controllers\Admin\UspsShipmentController;
use Illuminate\Support\Facades\Route;
use Webkul\Core\Http\Middleware\NoCacheMiddleware;

Route::group(['middleware' => ['web', 'admin', NoCacheMiddleware::class], 'prefix' => config('app.admin_url').'/usps-shipping'], function () {
    Route::controller(UspsShipmentController::class)->group(function () {
        Route::get('', 'index')->name('admin.usps-shipping.index');

        Route::get('orders/{orderId}/label/create', 'create')->name('admin.usps-shipping.labels.create');

        Route::post('orders/{orderId}/label', 'store')->name('admin.usps-shipping.labels.store');

        Route::get('labels/{id}/download', 'download')->name('admin.usps-shipping.labels.download');

        Route::post('labels/{id}/void', 'void')->name('admin.usps-shipping.labels.void');
    });
});
