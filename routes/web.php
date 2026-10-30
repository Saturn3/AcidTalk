<?php

use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\PageController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PageController::class, 'welcome'])->name('home');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
});
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

Route::get('/invite/{token}', [PageController::class, 'invite'])->name('invite.show');

// SPA: ogni rotta sotto /app è gestita lato client (c/{id}, admin, profile, ...)
Route::middleware('auth')->group(function () {
    Route::get('/app/{any?}', [PageController::class, 'app'])->where('any', '.*')->name('app');
    Route::get('/chat', fn () => redirect('/app'));
    Route::get('/admin', fn () => redirect('/app/admin'));
});

// Immagini avatar (nome file casuale, non indovinabile): servite senza symlink storage:link
Route::get('/media/avatars/{file}', function (string $file) {
    $path = storage_path('app/public/avatars/'.$file);
    abort_unless(is_file($path), 404);

    return response()->file($path, ['Cache-Control' => 'public, max-age=31536000, immutable']);
})->where('file', '[A-Za-z0-9_-]+\.(jpg|png|webp|gif)');
