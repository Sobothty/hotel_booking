<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Room;
use App\Services\CurrencyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CheckInController extends Controller
{
    protected $currencyService;

    public function __construct(CurrencyService $currencyService)
    {
        $this->currencyService = $currencyService;
    }

    public function checkIn(Request $request, Booking $booking)
    {
        // Direct check for admin role
        if ($request->user()->role !== 'admin') {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Admin access required.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'room_id' => 'required|exists:rooms,id',
            'payment_amount' => 'required|numeric',
            'currency' => 'required|in:USD,KHR',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()
            ], 422);
        }

        // Verify booking can be checked in
        if ($booking->status !== 'confirmed') {
            return response()->json([
                'status' => 'error',
                'message' => 'Only confirmed bookings can be checked in'
            ], 400);
        }

        // Check room availability
        $room = Room::findOrFail($request->room_id);
        if (!$room->is_available) {
            return response()->json([
                'status' => 'error',
                'message' => 'Selected room is not available'
            ], 400);
        }

        // Check room type
        if ($room->room_type_id !== $booking->room_type_id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Selected room does not match the booked type'
            ], 400);
        }

        // Convert payment to USD for storage
        $exchangeRate = $this->currencyService->getExchangeRate($request->currency);
        $amountUSD = $request->currency === 'USD'
            ? $request->payment_amount
            : $request->payment_amount / $exchangeRate;

        // Verify payment amount matches total price
        if ($amountUSD < $booking->total_price) {
            return response()->json([
                'status' => 'error',
                'message' => 'Payment amount must cover the full booking price'
            ], 400);
        }

        DB::beginTransaction();

        try {
            // Update room status
            $room->update(['is_available' => false]);

            // Update booking
            $booking->update([
                'room_id' => $room->id,
                'status' => 'checked_in',
                'payment_status' => 'paid'
            ]);

            // Record payment
            Payment::create([
                'booking_id' => $booking->id,
                'amount' => $request->payment_amount,
                'currency' => $request->currency,
                'exchange_rate' => $exchangeRate,
                'amount_usd' => $amountUSD,
                'processed_by' => Auth::id()
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Check-in processed successfully',
                'data' => $booking->fresh()->load(['roomType', 'room'])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred: ' . $e->getMessage()
            ], 500);
        }
    }

    public function checkOut(Request $request, Booking $booking)
    {
        // Direct check for admin role
        if ($request->user()->role !== 'admin') {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Admin access required.'
            ], 403);
        }

        // Verify booking can be checked out
        if ($booking->status !== 'checked_in') {
            return response()->json([
                'status' => 'error',
                'message' => 'Only checked-in bookings can be checked out'
            ], 400);
        }

        DB::beginTransaction();

        try {
            // Update room availability
            if ($booking->room) {
                $booking->room->update(['is_available' => true]);
            }

            // Update booking status
            $booking->update(['status' => 'completed']);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Check-out processed successfully',
                'data' => $booking->fresh()
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred: ' . $e->getMessage()
            ], 500);
        }
    }
}
