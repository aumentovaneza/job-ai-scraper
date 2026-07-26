<?php

use App\Console\Commands\DraftFollowUpsCommand;
use App\Console\Commands\InsightsDigestCommand;
use App\Console\Commands\ScrapeSourcesCommand;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        // Runs every minute; the command itself dispatches only the sources
        // whose per-source cron_schedule is due (T-26).
        $schedule->command(ScrapeSourcesCommand::class)
            ->everyMinute()
            ->withoutOverlapping();

        // Nightly: draft follow-up nudges for applications that have gone quiet
        // (T-45). Runs once a day so a user gets at most one draft per stale app.
        $schedule->command(DraftFollowUpsCommand::class)
            ->dailyAt('07:00')
            ->withoutOverlapping();

        // Weekly: build each user's insights digest — analytics + a Claude-written
        // narrative — and email it (Phase 6, T-61/T-63). Monday morning so it
        // frames the week ahead.
        $schedule->command(InsightsDigestCommand::class)
            ->weeklyOn(1, '08:00')
            ->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        // Correlation id on every request, for structured logging (T-07).
        $middleware->prepend(AssignRequestId::class);

        // Sanctum SPA auth: authenticate the React frontend via the session
        // cookie (first-party) instead of bearer tokens.
        $middleware->statefulApi();

        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Report unhandled exceptions to Sentry (no-op when SENTRY_LARAVEL_DSN is unset).
        Integration::handles($exceptions);
    })->create();
