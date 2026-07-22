<?php

use Illuminate\Support\Facades\Route;

// Serve the React SPA for all non-API/non-dashboard routes. The client-side
// router (React Router) takes over from here. Excludes paths owned by the
// backend (api, horizon, health check, storage, built assets).
Route::view('/{path?}', 'app')
    ->where('path', '^(?!api|horizon|up|storage|build).*$');
