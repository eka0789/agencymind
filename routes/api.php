<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware(['auth:sanctum', 'tenant.access', 'tenant.schema'])->group(function () {
    Route::get('/v1/clients/{client}/status', function (Request $request) {
        $client = $request->attributes->get('client');
        return response()->json([
            'client' => $client->slug,
            'schema' => \Illuminate\Support\Facades\DB::select("SHOW search_path")[0]->search_path ?? 'public',
        ]);
    });
});

Route::middleware(['tenant.schema', 'auth:sanctum'])->post(
    '/v1/clients/{client}/logs',
    [\App\Http\Controllers\Api\LogIngestController::class, 'ingest']
);

Route::middleware(['auth.service'])->group(function () {
    Route::post('/v1/commands/log-check', [\App\Http\Controllers\Api\DiscordCommandController::class, 'logCheck']);
    Route::post('/v1/commands/validate', [\App\Http\Controllers\Api\DiscordCommandController::class, 'validateProject']);
    Route::post('/v1/commands/threshold-set', [\App\Http\Controllers\Api\DiscordCommandController::class, 'thresholdSet']);
    Route::post('/v1/commands/report-weekly', [\App\Http\Controllers\Api\DiscordCommandController::class, 'reportWeekly']);
    Route::post('/v1/commands/report-approve', [\App\Http\Controllers\Api\DiscordCommandController::class, 'reportApprove']);
});

Route::middleware(['auth.service', 'tenant.schema'])->group(function () {
    Route::get('/v1/clients/{client}/status-detailed', [\App\Http\Controllers\Api\DiscordCommandController::class, 'clientStatus']);
});
