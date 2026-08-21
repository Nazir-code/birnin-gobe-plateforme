<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', fn () => ['status' => 'ok']);
    // API controllers must call the same application/domain use cases as Inertia controllers.
});
