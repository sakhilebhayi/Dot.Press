<?php

use App\Http\Controllers\Api\AiController;
use App\Http\Controllers\Api\AssetController;
use App\Http\Controllers\Api\CollaborationController;
use App\Http\Controllers\Api\DeckController;
use App\Http\Controllers\Api\PresentSessionController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\SlideController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::apiResource('projects', ProjectController::class);
    Route::apiResource('decks', DeckController::class);
    Route::apiResource('slides', SlideController::class);
    Route::apiResource('assets', AssetController::class)->only(['index', 'store', 'show', 'destroy']);
    Route::get('assets/{asset}/download', [AssetController::class, 'download'])
        ->middleware('signed')
        ->name('assets.download');

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

    // R-40 presenting: live sessions and the offline present package.
    Route::post('decks/{deck}/present-sessions', [PresentSessionController::class, 'store'])->middleware('throttle:present-session');
    Route::put('present-sessions/{token}', [PresentSessionController::class, 'update'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:present-push');
    Route::delete('present-sessions/{token}', [PresentSessionController::class, 'destroy'])->where('token', '[A-Za-z0-9]{40}');
    Route::get('decks/{deck}/present-package', [PresentSessionController::class, 'package'])->middleware('throttle:export');
});
