<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\SsoAuthController;

Route::get('/', function () {
    return view('welcome');
});

// SSO-1 (tenant-scoped): OAuth redirect + callback
// Must run under the web middleware stack (session required by Socialite).
Route::middleware(['web', 'tenant.initialize', 'throttle:sso'])->group(function () {
    // NOTE: callback can arrive without tenant header/query; tenant is enforced via signed state.
    Route::get('/auth/{provider}/redirect', [SsoAuthController::class, 'redirect']);
    Route::get('/auth/{provider}/callback', [SsoAuthController::class, 'callback']);
});
