<?php

use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\RoomController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');

Route::post('register', [AuthController::class, 'register']);
Route::post('register/admin', [AuthController::class, 'registerAdmin']);
Route::post('login', [AuthController::class, 'login']);

Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->group(
    function () {
        Route::apiResource('rooms', RoomController::class);
    }
);

Route::middleware(['auth:sanctum', 'role:user'])->prefix('user')->group(
    function () {
        Route::get('rooms', [RoomController::class, 'index']);
    }
);
