<?php

use App\Http\Controllers\Auth\AuthController;
use Illuminate\Support\Facades\Route;

// Public auth endpoint. SPA calls /sanctum/csrf-cookie first, then /login.
// No /register — accounts arrive through the invite flow (T-09).
Route::post('/login', [AuthController::class, 'login']);

// Session-authenticated (Sanctum SPA) endpoints.
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
});
