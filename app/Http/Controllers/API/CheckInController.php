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

    /**
     * Check if user is admin
     * 
     * @param Request $request
     * @return bool
     */
    private function isAdmin(Request $request)
    {
        return $request->user()->role === 'admin';
    }

    /**
     * Get available rooms for a specific room type
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAvailableRooms(Request $request)
    {
        if (!$this->isAdmin($request)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Admin access required.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'room_type_id' => 'required|exists:room_types,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()
            ], 422);
        }

        // Get available rooms of the specified room type
        $availableRooms = Room::where('room_type_id', $request->room_type_id)
            ->where('is_available', true)
            ->get(['id', 'name', 'room_number', 'floor', 'room_type_id']);

        return response()->json([
            'status' => 'success',
            'data' => $availableRooms
        ]);
    }

    /**
     * Get bookings requiring room assignment for a specific booking group
     * 
     * @param Request $request
     * @param string $bookingGroupId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getRoomAssignments(Request $request, $bookingGroupId)
    {
        if (!$this->isAdmin($request)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Admin access required.'
            ], 403);
        }

        // Get all bookings in this group
        $bookings = Booking::where('booking_group_id', $bookingGroupId)
            ->with('roomType')
            ->get();

        if ($bookings->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Booking group not found'
            ], 404);
        }

        // Group bookings by room type and count how many rooms of each type are needed
        $roomRequirements = $bookings->groupBy('room_type_id')
            ->map(function ($group) {
                $firstBooking = $group->first();
                return [
                    'room_type_id' => $firstBooking->room_type_id,
                    'room_type_name' => $firstBooking->roomType->name,
                    'rooms_needed' => $group->count(),
                    'rooms_assigned' => $group->whereNotNull('room_id')->count(),
                    'bookings' => $group->map(function ($booking) {
                        return [
                            'booking_id' => $booking->id,
                            'room_id' => $booking->room_id,
                            'status' => $booking->status,
                        ];
                    })->values()
                ];
            })->values();

        return response()->json([
            'status' => 'success',
            'data' => [
                'booking_group_id' => $bookingGroupId,
                'room_requirements' => $roomRequirements
            ]
        ]);
    }

    /**
     * Assign rooms to bookings by booking_id and room_id (single/multiple assignments)
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function assignRoomsToBookings(Request $request)
    {
        if (!$this->isAdmin($request)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Admin access required.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'assignments' => 'required|array',
            'assignments.*.booking_id' => 'required|exists:bookings,id',
            'assignments.*.room_id' => 'required|exists:rooms,id',
            'payment_status' => 'sometimes|in:paid,unpaid',
            'payment_method' => 'required_if:payment_status,paid|in:cash,credit_card,bank_transfer',
            'currency' => 'sometimes|in:USD,KHR',
            'exchange_rate' => 'required_if:currency,KHR|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()
            ], 422);
        }

        $results = [];
        $errors = [];

        foreach ($request->assignments as $assignment) {
            $booking = Booking::find($assignment['booking_id']);
            $room = Room::find($assignment['room_id']);

            // Validation checks
            if (!$booking) {
                $errors[] = "Booking #{$assignment['booking_id']} not found";
                continue;
            }

            if (!$room) {
                $errors[] = "Room #{$assignment['room_id']} not found";
                continue;
            }

            if ($room->room_type_id != $booking->room_type_id) {
                $errors[] = "Room #{$room->id} type does not match booking #{$booking->id} requirements";
                continue;
            }

            if (!$room->is_available) {
                $errors[] = "Room #{$room->id} is not available";
                continue;
            }

            // Update booking
            $booking->status = 'checked_in';
            $booking->room_id = $room->id;

            // Update payment info if provided
            if ($request->has('payment_status')) {
                $booking->payment_status = $request->payment_status;

                if ($request->payment_status === 'paid' && $request->has('payment_method')) {
                    $booking->payment_method = $request->payment_method;

                    // Handle currency conversion if needed
                    if ($request->has('currency')) {
                        $booking->currency = $request->currency;

                        // If paying in KHR, record the exchange rate and converted amount
                        if ($request->currency === 'KHR' && $request->has('exchange_rate')) {
                            $booking->exchange_rate = $request->exchange_rate;
                            $booking->total_price_local = $booking->total_price * $request->exchange_rate;
                        }
                    }
                }
            }

            $booking->save();

            // Update room availability
            $room->is_available = false;
            $room->save();

            $results[] = [
                'booking_id' => $booking->id,
                'room_id' => $room->id,
                'room_name' => $room->name,
                'status' => 'checked_in'
            ];
        }

        return response()->json([
            'status' => count($errors) > 0 ? 'partial' : 'success',
            'message' => count($results) > 0
                ? 'Room assignments completed' . (count($errors) > 0 ? ' with some errors' : '')
                : 'No rooms were assigned',
            'data' => [
                'successful_assignments' => $results,
                'errors' => $errors
            ]
        ]);
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

    /**
     * Check-in a single booking by ID
     * 
     * @param Request $request
     * @param int $bookingId
     * @return \Illuminate\Http\JsonResponse
     */
    public function checkInBooking(Request $request, $bookingId)
    {
        if (!$this->isAdmin($request)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Admin access required.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'room_id' => 'required|exists:rooms,id',
            'payment_status' => 'sometimes|in:paid,unpaid',
            'payment_method' => 'required_if:payment_status,paid|in:cash,credit_card,bank_transfer',
            'currency' => 'sometimes|in:USD,KHR',
            'exchange_rate' => 'required_if:currency,KHR|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()
            ], 422);
        }

        // Find the booking
        $booking = Booking::find($bookingId);

        if (!$booking) {
            return response()->json([
                'status' => 'error',
                'message' => 'Booking not found'
            ], 404);
        }

        // Get the room
        $room = Room::find($request->room_id);

        // Validate room type matches booking
        if ($room->room_type_id != $booking->room_type_id) {
            return response()->json([
                'status' => 'error',
                'message' => 'The selected room type does not match the booking room type'
            ], 400);
        }

        // Check if room is available
        if (!$room->is_available) {
            return response()->json([
                'status' => 'error',
                'message' => 'The selected room is not available'
            ], 400);
        }

        // Update booking status
        $booking->status = 'checked_in';
        $booking->room_id = $room->id;

        // Update payment information if provided
        if ($request->has('payment_status')) {
            $booking->payment_status = $request->payment_status;

            if ($request->payment_status === 'paid' && $request->has('payment_method')) {
                $booking->payment_method = $request->payment_method;

                // Handle currency conversion if needed
                if ($request->has('currency')) {
                    $booking->currency = $request->currency;

                    // If paying in KHR, record the exchange rate and converted amount
                    if ($request->currency === 'KHR' && $request->has('exchange_rate')) {
                        $booking->exchange_rate = $request->exchange_rate;
                        $booking->total_price_local = $booking->total_price * $request->exchange_rate;
                    }
                }
            }
        }

        $booking->save();

        // Update room availability
        $room->is_available = false;
        $room->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Check-in successful',
            'data' => [
                'booking' => $booking->load(['roomType', 'room', 'user']),
                'room' => $room
            ]
        ]);
    }

    /**
     * Assign a room to a booking during check-in
     * 
     * @param Request $request
     * @param int $bookingId
     * @return \Illuminate\Http\JsonResponse
     */
    public function assignRoom(Request $request, $bookingId)
    {
        // Check admin role
        if ($request->user()->role !== 'admin') {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Admin access required.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'room_id' => 'required|exists:rooms,id',
            'payment_status' => 'sometimes|in:paid,unpaid',
            'payment_method' => 'required_if:payment_status,paid|in:cash,credit_card,bank_transfer',
            'currency' => 'sometimes|in:USD,KHR',
            'exchange_rate' => 'required_if:currency,KHR|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()
            ], 422);
        }

        // Find booking
        $booking = Booking::find($bookingId);
        if (!$booking) {
            return response()->json([
                'status' => 'error',
                'message' => 'Booking not found'
            ], 404);
        }

        // Get room
        $room = Room::find($request->room_id);

        // Verify room type matches booking
        if ($room->room_type_id != $booking->room_type_id) {
            return response()->json([
                'status' => 'error',
                'message' => 'The selected room type does not match the booking'
            ], 400);
        }

        // Check if room is available
        if (!$room->is_available) {
            return response()->json([
                'status' => 'error',
                'message' => 'The selected room is not available'
            ], 400);
        }

        // Update booking status and assign room
        $booking->status = 'checked_in';
        $booking->room_id = $room->id;

        // Update payment information if provided
        if ($request->has('payment_status')) {
            $booking->payment_status = $request->payment_status;

            if ($request->payment_status === 'paid' && $request->has('payment_method')) {
                $booking->payment_method = $request->payment_method;

                // Handle currency conversion if needed
                if ($request->has('currency')) {
                    $booking->currency = $request->currency;

                    // If paying in KHR, record the exchange rate
                    if ($request->currency === 'KHR' && $request->has('exchange_rate')) {
                        $booking->exchange_rate = $request->exchange_rate;
                        $booking->total_price_local = $booking->total_price * $request->exchange_rate;
                    }
                }
            }
        }

        $booking->save();

        // Update room availability
        $room->is_available = false;
        $room->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Room assigned and guest checked in successfully',
            'data' => [
                'booking_id' => $booking->id,
                'booking_group_id' => $booking->booking_group_id,
                'room' => $room,
                'status' => $booking->status,
                'payment_status' => $booking->payment_status
            ]
        ]);
    }

    /**
     * Get booking details with required room types for check-in
     * 
     * @param Request $request
     * @param string $bookingGroupId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCheckInDetails(Request $request, $bookingGroupId)
    {
        // Check admin role
        if (!$this->isAdmin($request)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Admin access required.'
            ], 403);
        }

        // Get all bookings in this group
        $bookings = Booking::where('booking_group_id', $bookingGroupId)
            ->with(['roomType', 'user'])
            ->get();

        if ($bookings->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Booking group not found'
            ], 404);
        }

        $firstBooking = $bookings->first();

        // Determine group status
        $groupStatus = 'mixed';

        if ($bookings->every(function ($booking) {
            return $booking->status === 'confirmed';
        })) {
            $groupStatus = 'confirmed';
        } elseif ($bookings->every(function ($booking) {
            return $booking->status === 'checked_in';
        })) {
            $groupStatus = 'checked_in';
        } elseif ($bookings->every(function ($booking) {
            return $booking->status === 'checked_out';
        })) {
            $groupStatus = 'checked_out';
        } elseif ($bookings->every(function ($booking) {
            return $booking->status === 'cancelled';
        })) {
            $groupStatus = 'cancelled';
        }

        // Group bookings by room type and count requirements
        $roomRequirements = [];
        $roomTypeCounts = $bookings->groupBy('room_type_id');

        foreach ($roomTypeCounts as $roomTypeId => $typeBookings) {
            $roomType = $typeBookings->first()->roomType;

            // Get available rooms of this type - using only fields that exist in your database
            $availableRooms = Room::where('room_type_id', $roomTypeId)
                ->where('is_available', true)
                ->get();

            $roomRequirements[] = [
                'room_type_id' => $roomTypeId,
                'room_type_name' => $roomType->name,
                'room_type_description' => $roomType->description,
                'required_count' => $typeBookings->count(),
                'available_count' => $availableRooms->count(),
                'sufficient' => $availableRooms->count() >= $typeBookings->count(),
                'available_rooms' => $availableRooms,
                'bookings' => $typeBookings->map(function ($booking) {
                    return [
                        'booking_id' => $booking->id,
                        'status' => $booking->status
                    ];
                })
            ];
        }

        // Determine group status with priority logic
        $checkedInCount = $bookings->where('status', 'checked_in')->count();
        $completedCount = $bookings->where('status', 'completed')->count();
        $checkedOutCount = $bookings->where('status', 'checked_out')->count();
        $confirmedCount = $bookings->where('status', 'confirmed')->count();
        $cancelledCount = $bookings->where('status', 'cancelled')->count();
        $totalCount = $bookings->count();

        // Prioritize statuses for more meaningful representation
        if ($completedCount > 0) {
            // If any booking is completed, consider the group as completed
            $groupStatus = 'completed';
        } elseif ($checkedOutCount > 0) {
            // If any booking is checked out (and none are completed), consider the group as checked out
            $groupStatus = 'checked_out';
        } elseif ($checkedInCount > 0) {
            // If any booking is checked in (and none are checked out or completed), consider the group as checked in
            $groupStatus = 'checked_in';
        } elseif ($confirmedCount === $totalCount) {
            // If all bookings are confirmed, the group is confirmed
            $groupStatus = 'confirmed';
        } elseif ($cancelledCount === $totalCount) {
            // If all bookings are cancelled, the group is cancelled
            $groupStatus = 'cancelled';
        } else {
            // Default status if none of the above conditions are met
            $groupStatus = 'confirmed';
        }

        // Determine payment status
        $paidCount = $bookings->where('payment_status', 'paid')->count();
        $unpaidCount = $bookings->where('payment_status', 'unpaid')->count();
        $pendingCount = $bookings->where('payment_status', 'pending')->count();

        // Prioritize payment statuses
        if ($paidCount === $totalCount) {
            $groupPaymentStatus = 'paid';
        } elseif ($unpaidCount > 0) {
            // If any booking is unpaid, consider the group as unpaid
            $groupPaymentStatus = 'unpaid';
        } elseif ($pendingCount > 0) {
            // If any booking is pending and none are unpaid, consider the group as pending
            $groupPaymentStatus = 'pending';
        } else {
            // Default status
            $groupPaymentStatus = 'unpaid';
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'booking_group_id' => $bookingGroupId,
                'booking_status' => $groupStatus,
                'payment_status' => $groupPaymentStatus,
                'guest' => [
                    'name' => $firstBooking->user->name ?? 'Guest',
                    'email' => $firstBooking->user->email ?? '',
                    'phone' => $firstBooking->user->phone ?? ''
                ],
                'details' => [
                    'check_in_date' => $firstBooking->check_in_date,
                    'check_out_date' => $firstBooking->check_out_date,
                    'guests' => $firstBooking->guests,
                    'nights' => \Carbon\Carbon::parse($firstBooking->check_in_date)
                        ->diffInDays(\Carbon\Carbon::parse($firstBooking->check_out_date))
                ],
                'payment' => [
                    'status' => $firstBooking->payment_status,
                    'method' => $firstBooking->payment_method,
                    'total_price' => $bookings->sum('total_price')
                ],
                'room_requirements' => $roomRequirements
            ]
        ]);
    }

    /**
     * Check in a booking group with automatic room assignment
     * 
     * @param Request $request
     * @param string $bookingGroupId
     * @return \Illuminate\Http\JsonResponse
     */
    public function checkInGroup(Request $request, $bookingGroupId)
    {
        // Check admin role
        if ($request->user()->role !== 'admin') {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Admin access required.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'room_type_assignments' => 'required|array',
            'room_type_assignments.*.room_type_id' => 'required|exists:room_types,id',
            'room_type_assignments.*.room_ids' => 'required|array',
            'room_type_assignments.*.room_ids.*' => 'required|exists:rooms,id',
            'payment_status' => 'sometimes|in:paid,unpaid',
            'payment_method' => 'required_if:payment_status,paid|in:cash,credit_card,bank_transfer',
            'currency' => 'sometimes|in:USD,KHR',
            'exchange_rate' => 'required_if:currency,KHR|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()
            ], 422);
        }

        // Get all bookings in this group
        $bookings = Booking::where('booking_group_id', $bookingGroupId)
            ->where('status', 'confirmed') // Only include confirmed (not checked-in) bookings
            ->get();

        if ($bookings->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => 'No eligible bookings found for check-in'
            ], 404);
        }

        $results = [];
        $errors = [];

        // Process each room type assignment
        foreach ($request->room_type_assignments as $assignment) {
            $roomTypeId = $assignment['room_type_id'];
            $roomIds = $assignment['room_ids'];

            // Get bookings for this room type
            $typeBookings = $bookings->where('room_type_id', $roomTypeId)
                ->where('room_id', null) // Only include bookings without assigned rooms
                ->values();

            // Check if we have enough rooms provided
            if (count($roomIds) < $typeBookings->count()) {
                $errors[] = "Not enough rooms provided for room type ID {$roomTypeId}. Needed: {$typeBookings->count()}, Provided: " . count($roomIds);
                continue;
            }

            // Assign rooms to bookings of this type
            foreach ($typeBookings as $index => $booking) {
                if (!isset($roomIds[$index])) {
                    break; // Just in case
                }

                $roomId = $roomIds[$index];
                $room = Room::find($roomId);

                // Validate room
                if (!$room) {
                    $errors[] = "Room #{$roomId} not found";
                    continue;
                }

                // Validate room type
                if ($room->room_type_id != $roomTypeId) {
                    $errors[] = "Room #{$roomId} is not of the required type {$roomTypeId}";
                    continue;
                }

                // Validate room availability
                if (!$room->is_available) {
                    $errors[] = "Room #{$roomId} is not available";
                    continue;
                }

                // Assign room to booking
                $booking->status = 'checked_in';
                $booking->room_id = $roomId;

                // Update payment information if provided
                if ($request->has('payment_status')) {
                    $booking->payment_status = $request->payment_status;

                    if ($request->payment_status === 'paid' && $request->has('payment_method')) {
                        $booking->payment_method = $request->payment_method;

                        // Handle currency conversion if needed
                        if ($request->has('currency')) {
                            $booking->currency = $request->currency;

                            // If paying in KHR, record the exchange rate
                            if ($request->currency === 'KHR' && $request->has('exchange_rate')) {
                                $booking->exchange_rate = $request->exchange_rate;
                                $booking->total_price_local = $booking->total_price * $request->exchange_rate;
                            }
                        }
                    }
                }

                $booking->save();

                // Mark room as unavailable
                $room->is_available = false;
                $room->save();

                $results[] = [
                    'booking_id' => $booking->id,
                    'room_type_id' => $roomTypeId,
                    'room_id' => $roomId,
                    'room_name' => $room->name,
                    'status' => 'checked_in'
                ];
            }
        }

        return response()->json([
            'status' => count($errors) > 0 ? 'partial' : 'success',
            'message' => count($results) > 0
                ? 'Check-in completed' . (count($errors) > 0 ? ' with some errors' : '')
                : 'No rooms were assigned',
            'data' => [
                'booking_group_id' => $bookingGroupId,
                'successful_assignments' => $results,
                'errors' => $errors
            ]
        ]);
    }

    /**
     * Assign rooms and check-in a booking group
     */
    public function assignRooms(Request $request, $bookingGroupId)
    {
        // Check admin role
        if ($request->user()->role !== 'admin') {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Admin access required.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'assignments' => 'required|array',
            'assignments.*.room_type_id' => 'required|exists:room_types,id',
            'assignments.*.selected_rooms' => 'required|array',
            'assignments.*.selected_rooms.*' => 'required|exists:rooms,id',
            'payment_info' => 'required|array',
            'payment_info.status' => 'required|in:paid,unpaid',
            'payment_info.method' => 'required_if:payment_info.status,paid|in:cash,credit_card,bank_transfer',
            'payment_info.currency' => 'sometimes|in:USD,KHR',
            'payment_info.exchange_rate' => 'required_if:payment_info.currency,KHR|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()
            ], 422);
        }

        // Get all bookings in this group
        $bookings = Booking::where('booking_group_id', $bookingGroupId)
            ->where('status', 'confirmed')  // Only process confirmed bookings
            ->whereNull('room_id')          // That don't have rooms assigned
            ->get();

        if ($bookings->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => 'No bookings available for check-in'
            ], 404);
        }

        $results = [];
        $errors = [];

        // Group bookings by room type for easier matching
        $bookingsByType = $bookings->groupBy('room_type_id');

        // Process each room type assignment
        foreach ($request->assignments as $assignment) {
            $roomTypeId = $assignment['room_type_id'];
            $selectedRooms = $assignment['selected_rooms'];

            // Check if we have any bookings of this type
            if (!isset($bookingsByType[$roomTypeId])) {
                $errors[] = "No pending bookings found for room type {$roomTypeId}";
                continue;
            }

            $typeBookings = $bookingsByType[$roomTypeId];

            // Verify room count
            if (count($selectedRooms) != $typeBookings->count()) {
                $errors[] = "Incorrect number of rooms for room type {$roomTypeId}. " .
                    "Expected: {$typeBookings->count()}, Provided: " . count($selectedRooms);
                continue;
            }

            // Assign rooms to bookings of this type
            foreach ($typeBookings as $index => $booking) {
                // Get the room ID for this booking
                $roomId = $selectedRooms[$index];
                $room = Room::find($roomId);

                // Validate room
                if (!$room) {
                    $errors[] = "Room #{$roomId} not found";
                    continue;
                }

                // Validate room type
                if ($room->room_type_id != $roomTypeId) {
                    $errors[] = "Room #{$roomId} is not of the required type {$roomTypeId}";
                    continue;
                }

                // Validate room availability
                if (!$room->is_available) {
                    $errors[] = "Room #{$roomId} is not available";
                    continue;
                }

                // Assign room to booking
                $booking->status = 'checked_in';
                $booking->room_id = $roomId;

                // Update payment information if provided
                if ($request->has('payment_status')) {
                    $booking->payment_status = $request->payment_status;

                    if ($request->payment_status === 'paid' && $request->has('payment_method')) {
                        $booking->payment_method = $request->payment_method;

                        // Handle currency conversion if needed
                        if ($request->has('currency')) {
                            $booking->currency = $request->currency;

                            // If paying in KHR, record the exchange rate
                            if ($request->currency === 'KHR' && $request->has('exchange_rate')) {
                                $booking->exchange_rate = $request->exchange_rate;
                                $booking->total_price_local = $booking->total_price * $request->exchange_rate;
                            }
                        }
                    }
                }

                $booking->save();

                // Mark room as unavailable
                $room->is_available = false;
                $room->save();

                $results[] = [
                    'booking_id' => $booking->id,
                    'room_type' => $room->roomType->name,
                    'room_id' => $roomId,
                    'room_name' => $room->name,
                    'status' => 'checked_in',
                    'payment_status' => $booking->payment_status
                ];
            }
        }

        // Get the overall group status
        $allGroupBookings = Booking::where('booking_group_id', $bookingGroupId)->get();

        // Determine booking group status with priority logic
        $completedCount = $allGroupBookings->where('status', 'completed')->count();
        $checkedInCount = $allGroupBookings->where('status', 'checked_in')->count();
        $checkedOutCount = $allGroupBookings->where('status', 'checked_out')->count();
        $confirmedCount = $allGroupBookings->where('status', 'confirmed')->count();
        $cancelledCount = $allGroupBookings->where('status', 'cancelled')->count();
        $totalCount = $allGroupBookings->count();

        // Prioritize statuses for more meaningful representation
        if ($completedCount > 0) {
            // If any booking is completed, consider the group as completed
            $groupStatus = 'completed';
        } elseif ($checkedOutCount > 0) {
            // If any booking is checked out (and none are completed), consider the group as checked out
            $groupStatus = 'checked_out';
        } elseif ($checkedInCount > 0) {
            // If any booking is checked in (and none are checked out or completed), consider the group as checked in
            $groupStatus = 'checked_in';
        } elseif ($confirmedCount === $totalCount) {
            // If all bookings are confirmed, the group is confirmed
            $groupStatus = 'confirmed';
        } elseif ($cancelledCount === $totalCount) {
            // If all bookings are cancelled, the group is cancelled
            $groupStatus = 'cancelled';
        } else {
            // Default status if none of the above conditions are met
            $groupStatus = 'confirmed';
        }

        // Determine payment status with priority logic
        $paidCount = $allGroupBookings->where('payment_status', 'paid')->count();
        $unpaidCount = $allGroupBookings->where('payment_status', 'unpaid')->count();
        $pendingCount = $allGroupBookings->where('payment_status', 'pending')->count();

        // Prioritize payment statuses
        if ($paidCount === $totalCount) {
            $groupPaymentStatus = 'paid';
        } elseif ($unpaidCount > 0) {
            // If any booking is unpaid, consider the group as unpaid
            $groupPaymentStatus = 'unpaid';
        } elseif ($pendingCount > 0) {
            // If any booking is pending and none are unpaid, consider the group as pending
            $groupPaymentStatus = 'pending';
        } else {
            // Default status
            $groupPaymentStatus = 'unpaid';
        }

        return response()->json([
            'status' => count($errors) > 0 ? 'partial' : 'success',
            'message' => count($results) > 0
                ? 'Check-in completed' . (count($errors) > 0 ? ' with some errors' : '')
                : 'No rooms were assigned',
            'data' => [
                'booking_group_id' => $bookingGroupId,
                'booking_group_status' => $groupStatus,
                'payment_status' => $groupPaymentStatus,
                'successful_assignments' => $results,
                'errors' => $errors
            ]
        ]);
    }

    /**
     * Update payment information for a booking group
     * 
     * @param Request $request
     * @param string $bookingGroupId
     * @return \Illuminate\Http\JsonResponse
     */
    public function updatePayment(Request $request, $bookingGroupId)
    {
        // Check admin role
        if ($request->user()->role !== 'admin') {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Admin access required.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'payment_status' => 'required|in:paid,unpaid,pending',
            'payment_method' => 'required_if:payment_status,paid|in:cash,credit_card,bank_transfer',
            'currency' => 'required_if:payment_status,paid|in:USD,KHR',
            'exchange_rate' => 'required_if:currency,KHR|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()
            ], 422);
        }

        // Get all bookings in this group
        $bookings = Booking::where('booking_group_id', $bookingGroupId)->get();

        if ($bookings->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => 'No bookings found in this group'
            ], 404);
        }

        // Update payment information for all bookings in the group
        foreach ($bookings as $booking) {
            $booking->payment_status = $request->payment_status;

            if ($request->payment_status === 'paid') {
                $booking->payment_method = $request->payment_method;
                $booking->currency = $request->currency;

                // Handle KHR currency
                if ($request->currency === 'KHR' && $request->has('exchange_rate')) {
                    $booking->exchange_rate = $request->exchange_rate;
                    $booking->total_price_local = $booking->total_price * $request->exchange_rate;
                }
            }

            $booking->save();
        }

        // Calculate total in both currencies for the response
        $totalPriceUSD = $bookings->sum('total_price');
        $totalPriceLocal = null;

        if ($request->payment_status === 'paid' && $request->currency === 'KHR' && $request->has('exchange_rate')) {
            $totalPriceLocal = $totalPriceUSD * $request->exchange_rate;
        }

        // After updating all bookings, determine the group status
        $allBookings = Booking::where('booking_group_id', $bookingGroupId)->get();

        // Determine booking status with priority logic
        $completedCount = $allBookings->where('status', 'completed')->count();
        $checkedInCount = $allBookings->where('status', 'checked_in')->count();
        $checkedOutCount = $allBookings->where('status', 'checked_out')->count();
        $confirmedCount = $allBookings->where('status', 'confirmed')->count();
        $totalCount = $allBookings->count();

        if ($completedCount > 0) {
            $groupStatus = 'completed';
        } elseif ($checkedOutCount > 0) {
            $groupStatus = 'checked_out';
        } elseif ($checkedInCount > 0) {
            $groupStatus = 'checked_in';
        } elseif ($confirmedCount === $totalCount) {
            $groupStatus = 'confirmed';
        } else {
            $groupStatus = 'confirmed';
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Payment information updated successfully',
            'data' => [
                'booking_group_id' => $bookingGroupId,
                'booking_status' => $groupStatus,
                'payment_status' => $request->payment_status,
                'payment_method' => $request->payment_method ?? null,
                'currency' => $request->currency ?? null,
                'total_price_usd' => $totalPriceUSD,
                'total_price_local' => $totalPriceLocal,
                'exchange_rate' => $request->currency === 'KHR' ? $request->exchange_rate : null,
                'updated_at' => now()
            ]
        ]);
    }

    /**
     * Check out all bookings in a booking group
     * 
     * @param Request $request
     * @param string $bookingGroupId
     * @return \Illuminate\Http\JsonResponse
     */
    public function checkOutGroup(Request $request, $bookingGroupId)
    {
        // Direct check for admin role
        if ($request->user()->role !== 'admin') {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Admin access required.'
            ], 403);
        }

        // Get all checked-in bookings in this group
        $bookings = Booking::where('booking_group_id', $bookingGroupId)
            ->where('status', 'checked_in')
            ->get();

        if ($bookings->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => 'No checked-in bookings found in this group'
            ], 404);
        }

        DB::beginTransaction();

        try {
            $results = [];

            foreach ($bookings as $booking) {
                // Update room availability
                if ($booking->room) {
                    $booking->room->update(['is_available' => true]);
                }
                // Update booking status
                $booking->update(['status' => 'completed']);

                $results[] = [
                    'booking_id' => $booking->id,
                    'room_id' => $booking->room_id,
                    'status' => 'success',
                ];
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => count($results) . ' bookings checked out successfully',
                'data' => [
                    'booking_group_id' => $bookingGroupId,
                    'checked_out_bookings' => $results
                ]
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
