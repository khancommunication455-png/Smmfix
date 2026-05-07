<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Webhook routes - excluded from CSRF in middleware
Route::prefix('webhooks')->group(function () {
    Route::post('/stripe', 'App\Http\Controllers\WebhookController@stripe')->name('webhooks.stripe');
    Route::post('/paypal', 'App\Http\Controllers\WebhookController@paypal')->name('webhooks.paypal');
});
