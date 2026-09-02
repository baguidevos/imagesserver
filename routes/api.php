<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ImageController;
use Illuminate\Support\Facades\Route;

// Route publique pour afficher/télécharger les images (balises <img>, Chrome, WhatsApp, Flutter Web)
Route::get('/v1/images/{image}', [ImageController::class, 'show'])->name('images.show');

Route::middleware(['application', 'throttle:auth'])->group(function () {
    Route::post('/v1/auth/register', [AuthController::class, 'register']);
    Route::post('/v1/auth/login', [AuthController::class, 'login']);
});

Route::middleware(['application', 'auth:sanctum', 'token.application', 'throttle:api'])->group(function () {
    Route::post('/v1/auth/logout', [AuthController::class, 'logout']);
    Route::get('/v1/images', [ImageController::class, 'index'])->name('images.index');
    Route::post('/v1/images', [ImageController::class, 'store'])->name('images.store');
    Route::delete('/v1/images/{image}', [ImageController::class, 'destroy'])->name('images.destroy');
});
