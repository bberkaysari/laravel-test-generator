<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\UserController as ApiUserController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// API resources
Route::prefix('api/v1')->group(function () {
    Route::apiResource('users', ApiUserController::class);

    Route::get('/users/{user}/posts', [ApiUserController::class, 'posts'])->name('api.users.posts');
});
