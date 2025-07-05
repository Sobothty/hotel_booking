<?php

use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\BookingController;
use App\Http\Controllers\API\CheckInController;
use App\Http\Controllers\API\RoomController;
use App\Http\Controllers\API\RoomTypeController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\API\PaymentController;

// Public authentication routes
Route::post('register', [AuthController::class, 'register']);
Route::post('register/admin', [AuthController::class, 'registerAdmin']);
Route::post('login', [AuthController::class, 'login']);

// Google authentication routes
Route::get('auth/google', [GoogleController::class, 'redirectToGoogle'])->name('auth.google');
Route::get('auth/google/callback', [GoogleController::class, 'handleGoogleCallback']);


// Public browsing routes - available to all users (even unauthenticated)
Route::get('rooms', [RoomController::class, 'index']);
Route::get('rooms/{room}', [RoomController::class, 'show']);
Route::get('room-types', [RoomTypeController::class, 'index']);
Route::get('room-types/{roomType}', [RoomTypeController::class, 'show']);

// All authenticated routes
Route::middleware(['auth:sanctum'])->group(function () {
    // Booking routes for all authenticated users
    Route::post('bookings', [BookingController::class, 'store']);
    Route::get('bookings', [BookingController::class, 'index']);
    Route::get('bookings/{booking}', [BookingController::class, 'show']);

    // Protected route for fetching user profile
    Route::get('/user/profile', [ProfileController::class, 'show']);

    // Admin routes
    Route::prefix('admin')->group(function () {
        Route::get('bookings', [BookingController::class, 'adminIndex']);
        Route::post('bookings/{booking}/check-in', [CheckInController::class, 'checkIn']);
        Route::post('bookings/{booking}/check-out', [CheckInController::class, 'checkOut']);
        Route::post('bookings/group/{bookingGroupId}/check-out', [CheckInController::class, 'checkOutGroup']);
        Route::post('bookings/{bookingId}/process', [BookingController::class, 'processBooking']);
        Route::delete('bookings/group/{bookingGroupId}', [BookingController::class, 'destroyGroup']);
        Route::apiResource('rooms', RoomController::class)->except(['index', 'show']);
        Route::apiResource('room-types', RoomTypeController::class)->except(['index', 'show']);

        // Room availability and assignment
        Route::get('room-types/{roomTypeId}/available-rooms', [RoomController::class, 'getAvailableRoomsByType']);
        Route::post('bookings/{bookingId}/assign-room', [CheckInController::class, 'assignRoom']);

        // Payment update endpoint
        Route::post('bookings/{bookingGroupId}/update-payment', [CheckInController::class, 'updatePayment']);

        // User-friendly check-in endpoints
        Route::get('bookings/{bookingGroupId}/check-in-details', [CheckInController::class, 'getCheckInDetails']);
        Route::post('bookings/{bookingGroupId}/assign-rooms', [CheckInController::class, 'assignRooms']);

        // Admin booking management
        Route::get('users/{userId}/bookings', [BookingController::class, 'getUserBookingsByAdmin']);
        Route::get('all-user-bookings', [BookingController::class, 'getAllUserBookings']);

        // Payment processing
        Route::post('bookings/{bookingGroupId}/process-payment', [PaymentController::class, 'processPayment']);
    });
});
