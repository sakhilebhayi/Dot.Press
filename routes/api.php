<?php

use App\Http\Controllers\Api\AiController;
use App\Http\Controllers\Api\AiOutlineController;
use App\Http\Controllers\Api\AssetController;
use App\Http\Controllers\Api\CollaborationController;
use App\Http\Controllers\Api\DataParseController;
use App\Http\Controllers\Api\DeckController;
use App\Http\Controllers\Api\DeckIntelController;
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
        // R-40 intelligence: brief to outline. Local rules by default; the model only with a key.
        Route::post('outline', AiOutlineController::class);
    });

    // R-40 intelligence over a stored deck. analyze, story and spine are local rules and free;
    // suggest spends AI quota only when a language model answers.
    Route::prefix('decks/{deck}/intel')->group(function (): void {
        Route::middleware('throttle:120,1')->group(function (): void {
            Route::post('analyze', [DeckIntelController::class, 'analyze']);
            Route::post('story', [DeckIntelController::class, 'story']);
            Route::get('spine', [DeckIntelController::class, 'spine']);
            Route::post('spine', [DeckIntelController::class, 'spineEdit']);
        });
        Route::post('suggest', [DeckIntelController::class, 'suggest'])->middleware('throttle:ai');
    });

    Route::post('data/parse', DataParseController::class)->middleware('throttle:30,1');

    Route::prefix('collab')->middleware('throttle:collab')->group(function (): void {
        Route::post('slides/{slide}/heartbeat', [CollaborationController::class, 'heartbeat']);
        Route::get('slides/{slide}/participants', [CollaborationController::class, 'participants']);
    });
});
