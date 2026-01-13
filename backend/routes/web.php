<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\SsoAuthController;
use App\Http\Controllers\Api\TenantController;

Route::get('/', function () {
    return view('welcome');
});

// Tenant signup email verification (browser entrypoint)
Route::get('/tenants/verify-signup', [TenantController::class, 'verifySignupRedirect'])
    ->middleware('throttle:public-auth');

// SSO-1 (tenant-scoped): OAuth redirect + callback
// Must run under the web middleware stack (session required by Socialite).
Route::middleware(['web', 'tenant.initialize', 'throttle:sso'])->group(function () {
    // NOTE: callback can arrive without tenant header/query; tenant is enforced via signed state.
    Route::get('/auth/{provider}/redirect', [SsoAuthController::class, 'redirect']);
    Route::get('/auth/{provider}/callback', [SsoAuthController::class, 'callback']);
});
