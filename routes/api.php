<?php

declare(strict_types=1);

/**
 * Content Module API Routes
 *
 * REST API for content briefs, media, and AI generation.
 * Supports both session auth and API key auth.
 */

use Core\Mod\Content\Controllers\Api\ContentBriefController;
use Core\Mod\Content\Controllers\Api\ContentMediaController;
use Core\Mod\Content\Controllers\Api\ContentRevisionController;
use Core\Mod\Content\Controllers\Api\ContentSearchController;
use Core\Mod\Content\Controllers\Api\ContentWebhookController;
use Core\Mod\Content\Controllers\Api\GenerationController;
use Core\Mod\Content\Controllers\ContentPreviewController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Content API (Auth Required)
|--------------------------------------------------------------------------
|
| Full REST API for managing content briefs and AI generation.
| Session-based authentication.
|
*/

Route::middleware('auth')->prefix('content')->name('api.content.')->group(function () {
    // ─────────────────────────────────────────────────────────────────────
    // Content Briefs
    // ─────────────────────────────────────────────────────────────────────

    Route::prefix('briefs')->name('briefs.')->group(function () {
        Route::get('/', [ContentBriefController::class, 'index'])
            ->name('index');
        Route::post('/', [ContentBriefController::class, 'store'])
            ->name('store');
        Route::post('/bulk', [ContentBriefController::class, 'bulkStore'])
            ->name('bulk');
        Route::get('/next', [ContentBriefController::class, 'next'])
            ->name('next');
        Route::get('/{brief}', [ContentBriefController::class, 'show'])
            ->name('show');
        Route::put('/{brief}', [ContentBriefController::class, 'update'])
            ->name('update');
        Route::delete('/{brief}', [ContentBriefController::class, 'destroy'])
            ->name('destroy');
        Route::post('/{brief}/approve', [GenerationController::class, 'approve'])
            ->name('approve');
    });

    // ─────────────────────────────────────────────────────────────────────
    // AI Generation (rate limited - expensive operations)
    // ─────────────────────────────────────────────────────────────────────

    Route::prefix('generate')->name('generate.')->middleware('throttle:content-generate')->group(function () {
        Route::post('/draft', [GenerationController::class, 'draft'])
            ->name('draft');
        Route::post('/refine', [GenerationController::class, 'refine'])
            ->name('refine');
        Route::post('/full', [GenerationController::class, 'full'])
            ->name('full');
        Route::post('/social', [GenerationController::class, 'socialPosts'])
            ->name('social');
    });

    // ─────────────────────────────────────────────────────────────────────
    // Media Upload
    // ─────────────────────────────────────────────────────────────────────

    Route::prefix('media')->name('media.')->group(function () {
        Route::get('/', [ContentMediaController::class, 'index'])->name('index');
        Route::post('/', [ContentMediaController::class, 'store'])->name('store');
        Route::get('/{media}', [ContentMediaController::class, 'show'])->name('show');
        Route::put('/{media}', [ContentMediaController::class, 'update'])->name('update');
        Route::delete('/{media}', [ContentMediaController::class, 'destroy'])->name('destroy');
    });

    // ─────────────────────────────────────────────────────────────────────
    // Content Revisions
    // ─────────────────────────────────────────────────────────────────────

    Route::prefix('items/{item}/revisions')->name('items.revisions.')->group(function () {
        Route::get('/', [ContentRevisionController::class, 'index'])->name('index');
    });

    Route::prefix('revisions')->name('revisions.')->group(function () {
        Route::get('/{revision}', [ContentRevisionController::class, 'show'])->name('show');
        Route::post('/{revision}/restore', [ContentRevisionController::class, 'restore'])->name('restore');
        Route::get('/{revision}/compare/{compareWith}', [ContentRevisionController::class, 'compare'])->name('compare');
    });

    // ─────────────────────────────────────────────────────────────────────
    // Usage Statistics
    // ─────────────────────────────────────────────────────────────────────

    Route::get('/usage', [GenerationController::class, 'usage'])
        ->name('usage');

    // ─────────────────────────────────────────────────────────────────────
    // Content Preview
    // ─────────────────────────────────────────────────────────────────────

    Route::prefix('items/{item}/preview')->name('items.preview.')->group(function () {
        Route::post('/generate', [ContentPreviewController::class, 'generateLink'])->name('generate');
        Route::delete('/revoke', [ContentPreviewController::class, 'revokeLink'])->name('revoke');
    });

    // ─────────────────────────────────────────────────────────────────────
    // Content Search
    // ─────────────────────────────────────────────────────────────────────

    Route::prefix('search')->name('search.')->middleware('throttle:content-search')->group(function () {
        Route::get('/', [ContentSearchController::class, 'search'])->name('index');
        Route::get('/suggest', [ContentSearchController::class, 'suggest'])->name('suggest');
        Route::get('/info', [ContentSearchController::class, 'info'])->name('info');
        Route::post('/reindex', [ContentSearchController::class, 'reindex'])->name('reindex');
    });
});

/*
|--------------------------------------------------------------------------
| Content Webhooks (Public - No Auth Required)
|--------------------------------------------------------------------------
|
| External webhook endpoints for receiving content updates from WordPress,
| CMS systems, and other content sources. Authentication is handled via
| signature verification using the endpoint's secret key.
|
*/

Route::prefix('content/webhooks')->name('api.content.webhooks.')->group(function () {
    Route::post('/{endpoint}', [ContentWebhookController::class, 'receive'])
        ->name('receive')
        ->middleware('throttle:content-webhooks');
});

/*
|--------------------------------------------------------------------------
| Content API (API Key Auth)
|--------------------------------------------------------------------------
|
| Same endpoints authenticated via API key.
| Use Authorization: Bearer hk_xxx header.
|
*/

Route::middleware(['api.auth', 'api.scope.enforce'])->prefix('content')->name('api.key.content.')->group(function () {
    // Scope enforcement: GET=read, POST/PUT/PATCH=write, DELETE=delete
    // Briefs
    Route::prefix('briefs')->name('briefs.')->group(function () {
        Route::get('/', [ContentBriefController::class, 'index'])->name('index');
        Route::post('/', [ContentBriefController::class, 'store'])->name('store');
        Route::post('/bulk', [ContentBriefController::class, 'bulkStore'])->name('bulk');
        Route::get('/next', [ContentBriefController::class, 'next'])->name('next');
        Route::get('/{brief}', [ContentBriefController::class, 'show'])->name('show');
        Route::put('/{brief}', [ContentBriefController::class, 'update'])->name('update');
        Route::delete('/{brief}', [ContentBriefController::class, 'destroy'])->name('destroy');
        Route::post('/{brief}/approve', [GenerationController::class, 'approve'])->name('approve');
    });

    // Generation (rate limited - expensive operations)
    Route::prefix('generate')->name('generate.')->middleware('throttle:content-generate')->group(function () {
        Route::post('/draft', [GenerationController::class, 'draft'])->name('draft');
        Route::post('/refine', [GenerationController::class, 'refine'])->name('refine');
        Route::post('/full', [GenerationController::class, 'full'])->name('full');
        Route::post('/social', [GenerationController::class, 'socialPosts'])->name('social');
    });

    // Media
    Route::prefix('media')->name('media.')->group(function () {
        Route::get('/', [ContentMediaController::class, 'index'])->name('index');
        Route::post('/', [ContentMediaController::class, 'store'])->name('store');
        Route::get('/{media}', [ContentMediaController::class, 'show'])->name('show');
        Route::put('/{media}', [ContentMediaController::class, 'update'])->name('update');
        Route::delete('/{media}', [ContentMediaController::class, 'destroy'])->name('destroy');
    });

    // Usage
    Route::get('/usage', [GenerationController::class, 'usage'])->name('usage');

    // Content Revisions
    Route::prefix('items/{item}/revisions')->name('items.revisions.')->group(function () {
        Route::get('/', [ContentRevisionController::class, 'index'])->name('index');
    });

    Route::prefix('revisions')->name('revisions.')->group(function () {
        Route::get('/{revision}', [ContentRevisionController::class, 'show'])->name('show');
        Route::post('/{revision}/restore', [ContentRevisionController::class, 'restore'])->name('restore');
        Route::get('/{revision}/compare/{compareWith}', [ContentRevisionController::class, 'compare'])->name('compare');
    });

    // Search
    Route::prefix('search')->name('search.')->middleware('throttle:content-search')->group(function () {
        Route::get('/', [ContentSearchController::class, 'search'])->name('index');
        Route::get('/suggest', [ContentSearchController::class, 'suggest'])->name('suggest');
        Route::get('/info', [ContentSearchController::class, 'info'])->name('info');
        Route::post('/reindex', [ContentSearchController::class, 'reindex'])->name('reindex');
    });
});
