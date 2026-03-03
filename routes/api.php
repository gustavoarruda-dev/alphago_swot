<?php

use App\Http\Controllers\SwotAnalysisController;
use App\Http\Controllers\SwotSourceGovernanceController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json(['success' => true]);
});

Route::prefix('v1')
    ->middleware(['internal.api'])
    ->group(function (): void {
        Route::get('health', function () {
            return response()->json([
                'success' => true,
                'service' => 'alphago_swot',
                'timestamp' => now()->toIso8601String(),
            ]);
        });

        Route::prefix('swot')->group(function (): void {
            Route::get('analysis', [SwotAnalysisController::class, 'index']);
            Route::post('analysis/generate', [SwotAnalysisController::class, 'generate']);
            Route::get('analysis/{analysis:uuid}', [SwotAnalysisController::class, 'show']);

            Route::post('analysis/{analysis:uuid}/factors', [SwotAnalysisController::class, 'storeFactor']);
            Route::patch('analysis/{analysis:uuid}/factors/{item:uuid}', [SwotAnalysisController::class, 'updateFactor']);
            Route::delete('analysis/{analysis:uuid}/factors/{item:uuid}', [SwotAnalysisController::class, 'destroyFactor']);

            Route::patch('analysis/{analysis:uuid}/recommendations/{item:uuid}', [SwotAnalysisController::class, 'updateRecommendation']);
            Route::delete('analysis/{analysis:uuid}/recommendations/{item:uuid}', [SwotAnalysisController::class, 'destroyRecommendation']);

            Route::patch('analysis/{analysis:uuid}/actions/{item:uuid}', [SwotAnalysisController::class, 'updateAction']);
            Route::delete('analysis/{analysis:uuid}/actions/{item:uuid}', [SwotAnalysisController::class, 'destroyAction']);

            Route::get('source-governance', [SwotSourceGovernanceController::class, 'index']);
            Route::post('source-governance', [SwotSourceGovernanceController::class, 'store']);
            Route::patch('source-governance/{source:uuid}', [SwotSourceGovernanceController::class, 'update']);
            Route::delete('source-governance/{source:uuid}', [SwotSourceGovernanceController::class, 'destroy']);
        });
    });
