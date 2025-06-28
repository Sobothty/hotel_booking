<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class BookingController extends Controller
{
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'room_type_id' => 'required|exists:room_types,id',
            'check_in_date' => 'required|date|after_or_equal:today',
            'check_out_date' => 'required|date|after:check_in_date',
            'guests' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()
            ], 422);
        }

        // Calculate number of nights
        $checkIn = new \DateTime($request->check_in_date);
        $checkOut = new \DateTime($request->check_out_date);
        $nights = $checkIn->diff($checkOut)->days;

        // Get room type price
        $roomType = RoomType::findOrFail($request->room_type_id);

        // Calculate total price
        $totalPrice = $nights * $roomType->price;

        // Check room availability
        $availableRoomsCount = Room::where('room_type_id', $request->room_type_id)
            ->where('is_available', true)
            ->count();

        if ($availableRoomsCount === 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'No rooms of this type are available for the selected dates'
            ], 400);
        }

        // Create booking
        $booking = Booking::create([
            'user_id' => Auth::id(),
            'room_type_id' => $request->room_type_id,
            'check_in_date' => $request->check_in_date,
            'check_out_date' => $request->check_out_date,
            'guests' => $request->guests,
            'status' => 'confirmed',
            'total_price' => $totalPrice,
            'payment_method' => 'cash', // Default to cash
            'payment_status' => 'unpaid',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Booking created successfully',
            'data' => $booking->load('roomType')
        ], 201);
    }

    public function index()
    {
        $bookings = Booking::where('user_id', Auth::id())
            ->with(['roomType', 'room'])
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $bookings
        ]);
    }

    public function show(Booking $booking)
    {
        if ($booking->user_id !== Auth::id()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized'
            ], 403);
        }

        return response()->json([
            'status' => 'success',
            'data' => $booking->load(['roomType', 'room'])
        ]);
    }

    public function adminIndex()
    {
        $bookings = Booking::with(['user', 'roomType', 'room'])
            ->orderBy('check_in_date')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $bookings
        ]);
    }
}
