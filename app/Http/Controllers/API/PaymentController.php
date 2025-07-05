<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PaymentController extends Controller
{
    /**
     * Process payment for a booking group
     * 
     * @param Request $request
     * @param string $bookingGroupId
     * @return \Illuminate\Http\JsonResponse
     */
    public function processPayment(Request $request, $bookingGroupId)
    {
        $validator = Validator::make($request->all(), [
            'payment_method' => 'required|in:cash,credit_card,bank_transfer',
            'currency' => 'required|in:USD,KHR',
            'exchange_rate' => 'required_if:currency,KHR|numeric',
            'transaction_id' => 'sometimes|string',
            'notes' => 'sometimes|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()
            ], 422);
        }

        // Get bookings in this group
        $bookings = Booking::where('booking_group_id', $bookingGroupId)->get();

        if ($bookings->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Booking group not found'
            ], 404);
        }

        // Calculate total amount
        $totalAmount = $bookings->sum('total_price');
        $totalAmountLocal = null;
        $exchangeRate = null;

        // Handle currency conversion
        if ($request->currency === 'KHR') {
            $exchangeRate = $request->exchange_rate;
            $totalAmountLocal = $totalAmount * $exchangeRate;
        }

        DB::beginTransaction();

        try {
            // Create payment record
            $payment = Payment::create([
                'booking_group_id' => $bookingGroupId,
                'amount' => $totalAmount,
                'currency' => $request->currency,
                'exchange_rate' => $exchangeRate,
                'amount_local' => $totalAmountLocal,
                'payment_method' => $request->payment_method,
                'transaction_id' => $request->transaction_id ?? null,
                'notes' => $request->notes ?? null,
                'processed_by' => Auth::id()
            ]);

            // Update all bookings in the group
            foreach ($bookings as $booking) {
                $booking->payment_status = 'paid';
                $booking->payment_method = $request->payment_method;
                $booking->currency = $request->currency;

                if ($request->currency === 'KHR') {
                    $booking->exchange_rate = $request->exchange_rate;
                    $booking->total_price_local = $booking->total_price * $request->exchange_rate;
                }

                $booking->save();
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Payment processed successfully',
                'data' => [
                    'booking_group_id' => $bookingGroupId,
                    'payment_id' => $payment->id,
                    'payment_method' => $payment->payment_method,
                    'amount' => [
                        'usd' => $totalAmount,
                        'local' => $totalAmountLocal,
                        'currency' => $request->currency
                    ],
                    'transaction_id' => $payment->transaction_id,
                    'timestamp' => $payment->created_at
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'Payment processing failed: ' . $e->getMessage()
            ], 500);
        }
    }
}
