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
