<?php

use App\Http\Controllers\Api\AiController;
use App\Http\Controllers\Api\AssetController;
use App\Http\Controllers\Api\DeckController;
use App\Http\Controllers\Api\DeckDocumentController;
use App\Http\Controllers\Api\DeckVersionController;
use App\Http\Controllers\Api\PresenceController;
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
    Route::post('decks/{deck}/duplicate', [DeckController::class, 'duplicate']);
    Route::post('decks/{deck}/unarchive', [DeckController::class, 'unarchive']);
    Route::post('decks/{deck}/favourite', [DeckController::class, 'favourite'])->middleware('throttle:press-read');
    Route::post('decks/{deck}/touch', [DeckController::class, 'touch'])->middleware('throttle:press-read');

    // The presentation document: read it, change it with operations, follow what others changed.
    Route::get('decks/{deck}/document', [DeckDocumentController::class, 'show'])->middleware('throttle:press-read');
    Route::post('decks/{deck}/ops', [DeckDocumentController::class, 'ops'])->middleware('throttle:press-ops');
    Route::get('decks/{deck}/changes', [DeckDocumentController::class, 'changes'])->middleware('throttle:press-changes');
    Route::post('decks/{deck}/presence', [PresenceController::class, 'store'])->middleware('throttle:press-presence');

    Route::get('decks/{deck}/versions', [DeckVersionController::class, 'index'])->middleware('throttle:press-read');
    Route::post('decks/{deck}/versions', [DeckVersionController::class, 'store'])->middleware('throttle:press-read');
    Route::get('decks/{deck}/versions/{version}', [DeckVersionController::class, 'show'])->whereNumber('version')->middleware('throttle:press-read');
    Route::post('decks/{deck}/versions/{version}/restore', [DeckVersionController::class, 'restore'])->whereNumber('version')->middleware('throttle:press-read');

    Route::get('slides/{slide}', [SlideController::class, 'show'])->name('slides.show');
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
});
