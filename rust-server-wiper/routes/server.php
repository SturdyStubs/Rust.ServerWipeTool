<?php

declare(strict_types=1);
use Illuminate\Support\Facades\Route;
use RustServerWiper\Http\FeaturesController;
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
Route::put('/maps/{mapId}', [FeaturesController::class, 'updateMap'])->whereNumber('mapId');
Route::post('/presets', [FeaturesController::class, 'savePreset']);
Route::put('/presets/{presetId}', [FeaturesController::class, 'updatePreset'])->whereNumber('presetId');
Route::delete('/presets/{presetId}', [FeaturesController::class, 'deletePreset'])->whereNumber('presetId');
Route::get('/settings', [FeaturesController::class, 'settings']);
Route::put('/settings', [FeaturesController::class, 'saveSettings']);
Route::post('/settings/probe', [FeaturesController::class, 'probe']);
Route::get('/runs/{runId}/report', [FeaturesController::class, 'report'])->whereNumber('runId');
Route::post('/runs/{runId}/restore', [FeaturesController::class, 'restore'])->whereNumber('runId');
Route::post('/maintenance/recover', [FeaturesController::class, 'recover']);

Route::post('/health', [\RustServerWiper\Http\HealthController::class, 'check']);
Route::get('/health/probes/{probeId}', [\RustServerWiper\Http\HealthController::class, 'probe']);
Route::get('/configuration/export', [\RustServerWiper\Http\ConfigurationController::class, 'export']);
Route::post('/configuration/preview', [\RustServerWiper\Http\ConfigurationController::class, 'preview']);
Route::post('/configuration/import', [\RustServerWiper\Http\ConfigurationController::class, 'import']);
Route::post('/wipes/{wipeId}/skip', [WiperController::class, 'skip'])->whereNumber('wipeId');

Route::get('/announcements', [\RustServerWiper\Http\AnnouncementsController::class, 'index']);
Route::put('/announcements', [\RustServerWiper\Http\AnnouncementsController::class, 'save']);
Route::post('/announcements/preview', [\RustServerWiper\Http\AnnouncementsController::class, 'preview']);
Route::post('/announcements/probe', [\RustServerWiper\Http\AnnouncementsController::class, 'probe']);
Route::get('/announcements/probes/{probeId}', [\RustServerWiper\Http\AnnouncementsController::class, 'probeStatus']);
