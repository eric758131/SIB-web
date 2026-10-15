<?php

use App\Http\Controllers\Api\Admin\ActivityController;
use App\Http\Controllers\Api\Admin\CategoryController;
use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Admin\MediaController;
use App\Http\Controllers\Api\Admin\MessageController;
use App\Http\Controllers\Api\Admin\PostController;
use App\Http\Controllers\Api\Admin\RoleController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PublicController;
use Illuminate\Support\Facades\Route;

/*
| Public site
*/
Route::get('posts', [PublicController::class, 'posts']);
Route::get('posts/{slug}', [PublicController::class, 'post']);
Route::get('categories', [PublicController::class, 'categories']);
Route::post('contact', [PublicController::class, 'contact'])->middleware('throttle:contact');

/*
| Admin panel — permissions come from spatie/laravel-permission (see App\Support\PanelPermissions)
*/
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware(['auth:api', 'panel'])->prefix('admin')->group(function () {
    Route::get('me', [AuthController::class, 'me']);
    Route::put('me', [AuthController::class, 'updateProfile']);
    Route::post('logout', [AuthController::class, 'logout']);

    Route::get('dashboard', DashboardController::class);
    Route::get('categories', [CategoryController::class, 'index']);

    Route::middleware('permission:publicaciones.crear')->group(function () {
        Route::apiResource('posts', PostController::class)->whereNumber('post');
        Route::post('posts/{id}/restore', [PostController::class, 'restore'])->whereNumber('id');
    });

    Route::get('media', [MediaController::class, 'index']);
    Route::post('media', [MediaController::class, 'store'])->middleware('permission:medios.subir');

    Route::middleware('permission:categorias.gestionar')->group(function () {
        Route::post('categories', [CategoryController::class, 'store']);
        Route::put('categories/{id}', [CategoryController::class, 'update'])->whereNumber('id');
        Route::delete('categories/{id}', [CategoryController::class, 'destroy'])->whereNumber('id');
    });

    Route::middleware('permission:mensajes.gestionar')->group(function () {
        Route::apiResource('messages', MessageController::class)->except('store')->whereNumber('message');
    });

    Route::middleware('permission:usuarios.gestionar')->group(function () {
        Route::apiResource('users', UserController::class)->whereNumber('user');
    });

    Route::middleware('permission:roles.gestionar')->group(function () {
        Route::get('roles', [RoleController::class, 'index']);
        Route::post('roles', [RoleController::class, 'store']);
        Route::put('roles/{id}', [RoleController::class, 'update'])->whereNumber('id');
        Route::delete('roles/{id}', [RoleController::class, 'destroy'])->whereNumber('id');
    });

    Route::get('activity', [ActivityController::class, 'index'])->middleware('permission:actividad.ver');
});
