<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\JobPostingController;
use App\Http\Controllers\JobSourceController;
use Illuminate\Support\Facades\Route;

// Public auth endpoint. SPA calls /sanctum/csrf-cookie first, then /login.
// No /register — accounts arrive through the invite flow (T-09).
Route::post('/login', [AuthController::class, 'login']);

// Public invite acceptance (T-05/T-09): resolve a token, then set a password to
// create the account. No auth — the token is the credential.
Route::get('/invitations/{token}', [InvitationController::class, 'show']);
Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept']);

// Session-authenticated (Sanctum SPA) endpoints.
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Job catalog feed: keyword search + filters (T-06).
    Route::get('/jobs', [JobPostingController::class, 'index']);

    // Job sources: per-user CRUD + synchronous test-scrape (T-21). Ownership is
    // enforced by the BelongsToUser scope + JobSourcePolicy.
    Route::get('/job-sources', [JobSourceController::class, 'index']);
    Route::post('/job-sources', [JobSourceController::class, 'store']);
    Route::post('/job-sources/{jobSource}/test-scrape', [JobSourceController::class, 'testScrape']);
    Route::patch('/job-sources/{jobSource}', [JobSourceController::class, 'update']);
    Route::delete('/job-sources/{jobSource}', [JobSourceController::class, 'destroy']);

    // Admin-only invite management (T-09).
    Route::middleware('admin')->group(function () {
        Route::get('/invitations', [InvitationController::class, 'index']);
        Route::post('/invitations', [InvitationController::class, 'store']);
        Route::delete('/invitations/{invitation}', [InvitationController::class, 'destroy']);
    });
});
