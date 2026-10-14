<?php
declare(strict_types=1);
use Illuminate\Support\Facades\Route;
use RustServerWiper\Http\WiperController;
Route::get('/', [WiperController::class, 'index']);
Route::get('/files', [WiperController::class, 'files']);
Route::post('/preview', [WiperController::class, 'preview']);
Route::post('/run', [WiperController::class, 'run']);
Route::post('/wipes', [WiperController::class, 'store']);
Route::put('/wipes/{wipeId}', [WiperController::class, 'update'])->whereNumber('wipeId');
Route::patch('/wipes/{wipeId}/enabled', [WiperController::class, 'toggle'])->whereNumber('wipeId');
Route::delete('/wipes/{wipeId}', [WiperController::class, 'delete'])->whereNumber('wipeId');
Route::post('/maps', [WiperController::class, 'map']);
Route::delete('/maps/{mapId}', [WiperController::class, 'deleteMap'])->whereNumber('mapId');
