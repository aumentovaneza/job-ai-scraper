<?php

use App\Http\Controllers\AiKeyController;
use App\Http\Controllers\AiUsageController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\ApplicationStageController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\CoverLetterController;
use App\Http\Controllers\CoverLetterExportController;
use App\Http\Controllers\FollowUpController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\JobPostingController;
use App\Http\Controllers\JobSourceController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SnippetController;
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

    // Application pipeline stages: per-user CRUD + reorder (T-40). Ownership is
    // enforced by the BelongsToUser scope + ApplicationStagePolicy.
    Route::get('/application-stages', [ApplicationStageController::class, 'index']);
    Route::post('/application-stages', [ApplicationStageController::class, 'store']);
    Route::put('/application-stages/reorder', [ApplicationStageController::class, 'reorder']);
    Route::patch('/application-stages/{applicationStage}', [ApplicationStageController::class, 'update']);
    Route::delete('/application-stages/{applicationStage}', [ApplicationStageController::class, 'destroy']);

    // Application tracker: event-sourced pipeline (T-41/T-42). Every write goes
    // through ApplicationService, appending to application_events.
    Route::get('/applications', [ApplicationController::class, 'index']);
    Route::post('/applications', [ApplicationController::class, 'store']);
    Route::get('/applications/{application}', [ApplicationController::class, 'show']);
    Route::patch('/applications/{application}/move', [ApplicationController::class, 'move']);
    Route::post('/applications/{application}/notes', [ApplicationController::class, 'storeNote']);
    Route::post('/applications/{application}/contacts', [ApplicationController::class, 'storeContact']);

    // Insights dashboard: conversion analytics + weekly Claude narrative (T-60/T-61).
    Route::get('/analytics', [AnalyticsController::class, 'show']);

    // Follow-up inbox: review AI-drafted nudges, mark sent/dismissed (T-45).
    Route::get('/follow-ups', [FollowUpController::class, 'index']);
    Route::patch('/follow-ups/{followUp}', [FollowUpController::class, 'update']);

    // Cover letters (T-52/T-55/T-56). Generation fans out on the ai queue; the
    // paragraph rewrite is a small synchronous Claude call. User-scoped via the
    // BelongsToUser scope + Application/CoverLetter/CoverLetterVersion policies.
    Route::get('/applications/{application}/cover-letter', [CoverLetterController::class, 'show']);
    Route::post('/applications/{application}/cover-letter/generate', [CoverLetterController::class, 'generate']);
    Route::patch('/cover-letters/{coverLetter}/active-version', [CoverLetterController::class, 'setActiveVersion']);
    Route::post('/cover-letters/{coverLetter}/regenerate-paragraph', [CoverLetterController::class, 'regenerateParagraph']);
    Route::patch('/cover-letter-versions/{version}', [CoverLetterController::class, 'updateVersion']);
    Route::get('/cover-letter-versions/{version}/export', [CoverLetterExportController::class, 'export']);

    // Reusable letter snippet library (T-54). User-scoped CRUD.
    Route::get('/snippets', [SnippetController::class, 'index']);
    Route::post('/snippets', [SnippetController::class, 'store']);
    Route::patch('/snippets/{snippet}', [SnippetController::class, 'update']);
    Route::delete('/snippets/{snippet}', [SnippetController::class, 'destroy']);

    // BYOK: per-user AI key management for each provider (T-10).
    Route::get('/ai-key', [AiKeyController::class, 'show']);
    Route::post('/ai-key', [AiKeyController::class, 'store']);
    Route::delete('/ai-key', [AiKeyController::class, 'destroy']);

    // Switch the user's active AI provider (Claude / ChatGPT).
    Route::put('/ai-provider', [ProfileController::class, 'setProvider']);

    // Profile + onboarding: targets, resume ingestion, voice profile (T-12/T-13/T-14).
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::post('/profile/resume', [ProfileController::class, 'uploadResume']);
    Route::post('/profile/voice', [ProfileController::class, 'extractVoice']);

    // Account settings + live AI spend (T-15).
    Route::put('/settings', [SettingsController::class, 'update']);
    Route::get('/ai/usage', [AiUsageController::class, 'show']);

    // Admin-only invite management (T-09).
    Route::middleware('admin')->group(function () {
        Route::get('/invitations', [InvitationController::class, 'index']);
        Route::post('/invitations', [InvitationController::class, 'store']);
        Route::delete('/invitations/{invitation}', [InvitationController::class, 'destroy']);
    });
});
