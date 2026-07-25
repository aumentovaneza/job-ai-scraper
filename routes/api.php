<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\JobPostingController;
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

    // Admin-only invite management (T-09).
    Route::middleware('admin')->group(function () {
        Route::get('/invitations', [InvitationController::class, 'index']);
        Route::post('/invitations', [InvitationController::class, 'store']);
        Route::delete('/invitations/{invitation}', [InvitationController::class, 'destroy']);
    });
});
