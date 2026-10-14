<?php

declare(strict_types=1);
use Illuminate\Support\Facades\Route;
use RustServerWiper\Http\AdminController;

Route::get('/', [AdminController::class, 'index']);
Route::post('/bulk/preview', [AdminController::class, 'preview']);
Route::post('/bulk', [AdminController::class, 'store']);
