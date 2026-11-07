<?php

use App\Http\Controllers\Api\AiController;
use App\Http\Controllers\Api\AssetController;
use App\Http\Controllers\Api\BrandKitController;
use App\Http\Controllers\Api\CollaborationController;
use App\Http\Controllers\Api\DeckActivityController;
use App\Http\Controllers\Api\DeckCommentController;
use App\Http\Controllers\Api\DeckController;
use App\Http\Controllers\Api\DeckPeopleController;
use App\Http\Controllers\Api\DeckReviewController;
use App\Http\Controllers\Api\DeckShareController;
use App\Http\Controllers\Api\LayoutController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\SlideController;
use App\Http\Controllers\Api\ThemeController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::apiResource('projects', ProjectController::class);
    Route::apiResource('decks', DeckController::class);
    Route::apiResource('slides', SlideController::class);
    // Asset library (R-40). The signed routes stream the stored file with Range support.
    Route::apiResource('assets', AssetController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
    Route::get('assets/{asset}/usage', [AssetController::class, 'usage'])->name('assets.usage');
    Route::post('assets/{asset}/poster', [AssetController::class, 'poster'])->name('assets.poster');
    Route::get('assets/{asset}/file', [AssetController::class, 'file'])
        ->middleware('signed')
        ->name('assets.file');
    Route::get('assets/{asset}/download', [AssetController::class, 'download'])
        ->middleware('signed')
        ->name('assets.download');

    // R-40 access and collaboration: sharing, people, comments, reviews, activity. Each action authorises through
    // DeckAccess (see App\Services\Press\DeckAccess for the roles and abilities).
    Route::prefix('decks/{deck}')->group(function (): void {
        Route::get('sharing', [DeckShareController::class, 'show']);
        Route::put('sharing', [DeckShareController::class, 'update'])->middleware('throttle:60,1,press-share');
        Route::post('sharing/rotate-link', [DeckShareController::class, 'rotate'])->middleware('throttle:20,1,press-rotate');
        Route::get('sharing/people', [DeckShareController::class, 'people']);
        Route::post('sharing/people', [DeckShareController::class, 'addPerson'])->middleware('throttle:60,1,press-share');
        Route::patch('sharing/people/{user}', [DeckShareController::class, 'updatePerson'])->middleware('throttle:60,1,press-share');
        Route::delete('sharing/people/{user}', [DeckShareController::class, 'removePerson'])->middleware('throttle:60,1,press-share');
        Route::get('people', [DeckPeopleController::class, 'index']);

        Route::get('comments', [DeckCommentController::class, 'index']);
        Route::post('comments', [DeckCommentController::class, 'store'])->middleware('throttle:120,1,press-comments');
        Route::patch('comments/{comment}', [DeckCommentController::class, 'update'])->middleware('throttle:120,1,press-comments');
        Route::delete('comments/{comment}', [DeckCommentController::class, 'destroy'])->middleware('throttle:120,1,press-comments');
        Route::post('comments/{comment}/resolve', [DeckCommentController::class, 'resolve'])->middleware('throttle:120,1,press-comments');
        Route::post('comments/{comment}/reopen', [DeckCommentController::class, 'reopen'])->middleware('throttle:120,1,press-comments');

        Route::get('reviews', [DeckReviewController::class, 'index']);
        Route::post('reviews', [DeckReviewController::class, 'store'])->middleware('throttle:30,1,press-reviews');
        Route::post('reviews/{review}/decision', [DeckReviewController::class, 'decide'])->middleware('throttle:60,1,press-reviews');
        Route::delete('reviews/{review}', [DeckReviewController::class, 'destroy'])->middleware('throttle:60,1,press-reviews');

        Route::get('activity', [DeckActivityController::class, 'index']);
    });

    // Themes, brand kits and custom layouts (team scoped; editors and admins write, viewers read).
    Route::apiResource('themes', ThemeController::class);
    Route::apiResource('brand-kits', BrandKitController::class)->parameters(['brand-kits' => 'brandKit']);
    Route::apiResource('layouts', LayoutController::class);

    Route::prefix('ai')->middleware('throttle:ai')->group(function (): void {
        Route::get('usage', [AiController::class, 'usage']);
        Route::post('decks/{deck}/generate-slide', [AiController::class, 'generateSlide']);
        Route::post('decks/{deck}/generate-deck', [AiController::class, 'generateDeck']);
        Route::post('slides/{slide}/rewrite-text', [AiController::class, 'rewriteText']);
    });

    Route::prefix('collab')->middleware('throttle:collab')->group(function (): void {
        Route::post('slides/{slide}/heartbeat', [CollaborationController::class, 'heartbeat']);
        Route::get('slides/{slide}/participants', [CollaborationController::class, 'participants']);
    });
});
