<?php

use App\Http\Controllers\Api\AiController;
use App\Http\Controllers\Api\AiOutlineController;
use App\Http\Controllers\Api\AssetController;
use App\Http\Controllers\Api\BrandKitController;
use App\Http\Controllers\Api\DataParseController;
use App\Http\Controllers\Api\DeckActivityController;
use App\Http\Controllers\Api\DeckCommentController;
use App\Http\Controllers\Api\DeckController;
use App\Http\Controllers\Api\DeckDocumentController;
use App\Http\Controllers\Api\DeckExportController;
use App\Http\Controllers\Api\DeckImportController;
use App\Http\Controllers\Api\DeckIntelController;
use App\Http\Controllers\Api\DeckPeopleController;
use App\Http\Controllers\Api\DeckReviewController;
use App\Http\Controllers\Api\DeckShareController;
use App\Http\Controllers\Api\DeckVersionController;
use App\Http\Controllers\Api\LayoutController;
use App\Http\Controllers\Api\LayoutLibraryController;
use App\Http\Controllers\Api\PresenceController;
use App\Http\Controllers\Api\PresentSessionController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\SlideController;
use App\Http\Controllers\Api\TemplateController;
use App\Http\Controllers\Api\ThemeController;
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
    Route::post('decks/{deck}/versions', [DeckVersionController::class, 'store'])->middleware('throttle:10,1,press-version');
    Route::get('decks/{deck}/versions/{version}', [DeckVersionController::class, 'show'])->whereNumber('version')->middleware('throttle:press-read');
    Route::post('decks/{deck}/versions/{version}/restore', [DeckVersionController::class, 'restore'])->whereNumber('version')->middleware('throttle:press-read');

    Route::get('slides/{slide}', [SlideController::class, 'show'])->name('slides.show');
    // Asset library (R-40). The signed routes stream the stored file with Range support.
    Route::apiResource('assets', AssetController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
    Route::get('assets/{asset}/usage', [AssetController::class, 'usage'])->name('assets.usage');
    Route::post('assets/{asset}/poster', [AssetController::class, 'poster'])->name('assets.poster');
    Route::get('assets/{asset}/file', [AssetController::class, 'file'])
        ->middleware('signed:relative')
        ->name('assets.file');
    Route::get('assets/{asset}/download', [AssetController::class, 'download'])
        ->middleware('signed:relative')
        ->name('assets.download');

    // R-40 access and collaboration: sharing, people, comments, reviews, activity. Each action authorises through
    // DeckAccess (see App\Services\Press\DeckAccess for the roles and abilities).
    Route::prefix('decks/{deck}')->group(function (): void {
        Route::get('sharing', [DeckShareController::class, 'show']);
        Route::put('sharing', [DeckShareController::class, 'update'])->middleware('throttle:60,1,press-share');
        Route::post('sharing/rotate-link', [DeckShareController::class, 'rotate'])->middleware('throttle:20,1,press-rotate');
        Route::get('sharing/people', [DeckShareController::class, 'people']);
        // Looking people up by address is the one thing here worth guessing at, so it has the tightest limit.
        Route::post('sharing/people', [DeckShareController::class, 'addPerson'])->middleware('throttle:20,1,press-share-add');
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

    // R-40 built-in libraries: slide layouts and presentation templates (read only).
    Route::get('layout-library', [LayoutLibraryController::class, 'index'])->middleware('throttle:press-read');
    Route::get('intel/frameworks', [DeckIntelController::class, 'frameworks'])->middleware('throttle:press-read');
    Route::get('templates', [TemplateController::class, 'index'])->middleware('throttle:press-read');
    Route::get('templates/{key}', [TemplateController::class, 'show'])->where('key', '[a-z0-9-]+')->middleware('throttle:press-read');

    Route::prefix('ai')->middleware('throttle:ai')->group(function (): void {
        Route::get('usage', [AiController::class, 'usage']);
        Route::post('decks/{deck}/generate-slide', [AiController::class, 'generateSlide']);
        Route::post('decks/{deck}/generate-deck', [AiController::class, 'generateDeck']);
        Route::post('slides/{slide}/rewrite-text', [AiController::class, 'rewriteText']);
        // R-40 intelligence: brief to outline. Local rules by default; the model only with a key.
        Route::post('outline', AiOutlineController::class);
    });

    // R-40 presenting: live sessions and the offline present package.
    Route::post('decks/{deck}/present-sessions', [PresentSessionController::class, 'store'])->middleware('throttle:present-session');
    Route::put('present-sessions/{token}', [PresentSessionController::class, 'update'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:present-push');
    Route::delete('present-sessions/{token}', [PresentSessionController::class, 'destroy'])->where('token', '[A-Za-z0-9]{40}');
    Route::get('decks/{deck}/present-package', [PresentSessionController::class, 'package'])->middleware('throttle:export');

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

    // R-40 export and import (PDF, PPTX): the preflight says what each element becomes before anything is written.
    Route::post('decks/{deck}/exports/preflight', [DeckExportController::class, 'preflight'])->middleware('throttle:press-export');
    Route::post('decks/{deck}/exports', [DeckExportController::class, 'store'])->middleware('throttle:press-export');
    Route::get('decks/{deck}/exports', [DeckExportController::class, 'index']);
    Route::get('exports/{export}', [DeckExportController::class, 'show'])->whereNumber('export');
    Route::get('exports/{export}/download', [DeckExportController::class, 'download'])->whereNumber('export')->name('press.exports.download');
    Route::post('imports/pptx', [DeckImportController::class, 'store'])->middleware('throttle:press-import');

    Route::post('data/parse', DataParseController::class)->middleware('throttle:30,1');
});
