<?php

use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\BookingController;
use App\Http\Controllers\API\CheckInController;
use App\Http\Controllers\API\RoomController;
use App\Http\Controllers\API\RoomTypeController;
use Illuminate\Support\Facades\Route;

// Public authentication routes
Route::post('register', [AuthController::class, 'register']);
Route::post('register/admin', [AuthController::class, 'registerAdmin']);
Route::post('login', [AuthController::class, 'login']);

// Routes for authenticated users (both regular users and admins)
Route::middleware(['auth:sanctum'])->group(function () {
    // Room browsing - available to all authenticated users
    Route::get('rooms', [RoomController::class, 'index']);
    Route::get('rooms/{room}', [RoomController::class, 'show']);
    Route::get('room-types', [RoomTypeController::class, 'index']);
    Route::get('room-types/{roomType}', [RoomTypeController::class, 'show']);

    // Booking management - available to all authenticated users
    Route::post('bookings', [BookingController::class, 'store']);
    Route::get('bookings', [BookingController::class, 'index']);
    Route::get('bookings/{booking}', [BookingController::class, 'show']);
});

// Admin-only routes
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->group(function () {
    // Room CRUD operations
    Route::apiResource('rooms', RoomController::class)->except(['index', 'show']);
    Route::apiResource('room-types', RoomTypeController::class)->except(['index', 'show']);

    // Check-in/check-out process
    Route::post('bookings/{booking}/check-in', [CheckInController::class, 'checkIn']);
    Route::post('bookings/{booking}/check-out', [CheckInController::class, 'checkOut']);

    // Admin dashboard
    Route::get('bookings', [BookingController::class, 'adminIndex']);
});
