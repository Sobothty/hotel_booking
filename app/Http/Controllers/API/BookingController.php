<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class BookingController extends Controller
{
    public function store(Request $request)
    {
        // Validate request
        $validator = Validator::make($request->all(), [
            'room_type_ids' => 'required|array|min:1',
            'room_type_ids.*' => 'required|exists:room_types,id',
            'check_in_date' => 'required|date|after_or_equal:today',
            'check_out_date' => 'required|date|after:check_in_date',
            'guests' => 'required|integer|min:1',
            'payment_method' => 'required|in:cash,credit_card,bank_transfer',
            'currency' => 'sometimes|in:USD,KHR',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()
            ], 422);
        }

        // Set default currency if not provided
        $currency = $request->currency ?? 'USD';

        // Calculate number of nights
        $checkIn = new \DateTime($request->check_in_date);
        $checkOut = new \DateTime($request->check_out_date);
        $nights = $checkIn->diff($checkOut)->days;

        // Create a unique booking group ID for this reservation
        $bookingGroupId = 'BKG-' . uniqid();

        $bookings = [];
        $totalPrice = 0;

        // Count room type occurrences (in case user books same room type multiple times)
        $roomTypeCounts = array_count_values($request->room_type_ids);

        // Process each unique room type
        foreach ($roomTypeCounts as $roomTypeId => $quantity) {
            // Get room type details
            $roomType = RoomType::findOrFail($roomTypeId);

            // Check room availability - must have enough rooms of this type
            $availableRoomsCount = Room::where('room_type_id', $roomTypeId)
                ->where('is_available', true)
                ->count();

            if ($availableRoomsCount < $quantity) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Not enough rooms of type ' . $roomType->name . ' are available. Requested: ' .
                        $quantity . ', Available: ' . $availableRoomsCount
                ], 400);
            }

            // Create bookings for this room type (one for each quantity requested)
            for ($i = 0; $i < $quantity; $i++) {
                // Calculate price for this room
                $roomTypePrice = $nights * $roomType->price;
                $totalPrice += $roomTypePrice;

                // Determine payment status based on payment method
                $paymentStatus = ($request->payment_method === 'cash') ? 'unpaid' : 'pending';

                // Create booking record - make sure to include booking_group_id
                $booking = Booking::create([
                    'booking_group_id' => $bookingGroupId,
                    'user_id' => Auth::id(),
                    'room_type_id' => $roomTypeId,
                    'check_in_date' => $request->check_in_date,
                    'check_out_date' => $request->check_out_date,
                    'guests' => $request->guests,
                    'status' => 'confirmed',
                    'total_price' => $roomTypePrice,
                    'payment_method' => $request->payment_method,
                    'payment_status' => $paymentStatus,
                    'currency' => $currency,
                ]);

                $bookings[] = $booking;
            }
        }

        // Load room types for the response
        $bookingsWithRoomTypes = collect($bookings)->map->load('roomType');

        // Group room types for the response (to show quantities)
        $groupedRoomTypes = $bookingsWithRoomTypes->groupBy('room_type_id')
            ->map(function ($group) {
                $roomType = $group->first()->roomType;
                return [
                    'id' => $roomType->id,
                    'name' => $roomType->name,
                    'price' => $roomType->price,
                    'quantity' => $group->count(),
                    'subtotal' => $group->sum('total_price')
                ];
            })->values();

        // Determine next steps based on payment method
        $nextSteps = '';
        if ($request->payment_method === 'cash') {
            $nextSteps = 'Please pay at the hotel during check-in.';
        } else if ($request->payment_method === 'credit_card') {
            $nextSteps = 'You will be redirected to the payment gateway to complete your payment.';
        } else {
            $nextSteps = 'Please transfer the payment to our bank account and send the receipt to confirm your booking.';
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Bookings created successfully',
            'data' => [
                'booking_id' => $bookingGroupId,
                'check_in_date' => $request->check_in_date,
                'check_out_date' => $request->check_out_date,
                'guests' => $request->guests,
                'room_types' => $groupedRoomTypes,
                'total_price' => $totalPrice,
                'currency' => $currency,
                'status' => 'confirmed',
                'payment_status' => ($request->payment_method === 'cash') ? 'unpaid' : 'pending',
                'payment_method' => $request->payment_method,
                'next_steps' => $nextSteps
            ]
        ], 201);
    }

    public function index()
    {
        $bookings = Booking::where('user_id', Auth::id())
            ->with(['roomType', 'room'])
            ->orderBy('created_at', 'desc')
            ->get();

        // Group bookings by booking_group_id
        $groupedBookings = $bookings->groupBy('booking_group_id')
            ->map(function ($group) {
                $firstBooking = $group->first();

                // Create room_types array with all room types in this booking group
                $roomTypes = $group->map(function ($booking) {
                    return [
                        'id' => $booking->roomType->id,
                        'name' => $booking->roomType->name,
                        'rooms' => $booking->room ? [
                            'id' => $booking->room->id,
                            'name' => $booking->room->name,
                        ] : null,
                    ];
                });

                return [
                    'booking_id' => $firstBooking->booking_group_id,
                    'check_in_date' => $firstBooking->check_in_date,
                    'check_out_date' => $firstBooking->check_out_date,
                    'guests' => $firstBooking->guests,
                    'status' => $firstBooking->status,
                    'payment_method' => $firstBooking->payment_method,
                    'payment_status' => $firstBooking->payment_status,
                    'created_at' => $firstBooking->created_at,
                    'total_price' => $group->sum('total_price'),
                    'room_types' => $roomTypes->values()
                ];
            })->values();

        return response()->json([
            'status' => 'success',
            'data' => $groupedBookings
        ]);
    }

    public function show(Request $request, $bookingGroupId)
    {
        $bookings = Booking::where('booking_group_id', $bookingGroupId)
            ->where('user_id', Auth::id())
            ->with(['roomType', 'room'])
            ->get();

        if ($bookings->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Booking not found or unauthorized'
            ], 404);
        }

        $firstBooking = $bookings->first();

        // Create room_types array with all room types in this booking group
        $roomTypes = $bookings->map(function ($booking) {
            return [
                'id' => $booking->roomType->id,
                'name' => $booking->roomType->name,
                'rooms' => $booking->room ? [
                    'id' => $booking->room->id,
                    'name' => $booking->room->name,
                ] : null,
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => [
                'booking_id' => $firstBooking->booking_group_id,
                'check_in_date' => $firstBooking->check_in_date,
                'check_out_date' => $firstBooking->check_out_date,
                'guests' => $firstBooking->guests,
                'status' => $firstBooking->status,
                'payment_method' => $firstBooking->payment_method,
                'payment_status' => $firstBooking->payment_status,
                'created_at' => $firstBooking->created_at,
                'total_price' => $bookings->sum('total_price'),
                'room_types' => $roomTypes->values()
            ]
        ]);
    }

    public function adminIndex(Request $request)
    {
        // Direct check for admin role
        if ($request->user()->role !== 'admin') {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Admin access required.'
            ], 403);
        }

        // Add status filter if provided
        $query = Booking::query()->with(['user', 'roomType', 'room']);

        // Add date range filter if provided
        if ($request->has('date_from') && $request->has('date_to')) {
            $query->where('check_in_date', '>=', $request->date_from)
                ->where('check_out_date', '<=', $request->date_to);
        }

        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Add payment status filter if provided
        if ($request->has('payment_status') && $request->payment_status !== 'all') {
            $query->where('payment_status', $request->payment_status);
        }

        $bookings = $query->orderBy('check_in_date')->get();

        // For bookings with null booking_group_id, create a virtual group ID
        $processedBookings = $bookings->map(function ($booking) {
            if ($booking->booking_group_id === null) {
                // Create a virtual booking_group_id for legacy bookings
                $booking->virtual_group_id = 'LEGACY-' .
                    $booking->id . '-' .
                    $booking->user_id;
            } else {
                $booking->virtual_group_id = $booking->booking_group_id;
            }
            return $booking;
        });

        // Log booking information
        Log::info('Admin index found ' . $bookings->count() . ' bookings');

        // Group bookings by virtual_group_id instead of booking_group_id
        $groupedBookings = $processedBookings->groupBy('virtual_group_id')
            ->map(function ($group) {
                $firstBooking = $group->first();

                // Group by room type
                $roomTypes = $group->groupBy('room_type_id')
                    ->map(function ($typeGroup) {
                        $firstOfType = $typeGroup->first();
                        return [
                            'id' => $firstOfType->roomType->id,
                            'name' => $firstOfType->roomType->name,
                            'bookings' => $typeGroup->map(function ($booking) {
                                return [
                                    'booking_id' => $booking->id,
                                    'room' => $booking->room
                                ];
                            })->values()
                        ];
                    })->values();

                // Use booking_group_id if available, otherwise use the virtual one
                $groupId = $firstBooking->booking_group_id ?? $firstBooking->virtual_group_id;

                return [
                    'booking_group_id' => $groupId,
                    'check_in_date' => $firstBooking->check_in_date,
                    'check_out_date' => $firstBooking->check_out_date,
                    'user' => [
                        'id' => $firstBooking->user->id,
                        'name' => $firstBooking->user->name,
                        'email' => $firstBooking->user->email,
                    ],
                    'guests' => $firstBooking->guests,
                    'status' => $firstBooking->status,
                    'payment_status' => $firstBooking->payment_status,
                    'payment_method' => $firstBooking->payment_method,
                    'created_at' => $firstBooking->created_at,
                    'total_price' => $group->sum('total_price'),
                    'room_types' => $roomTypes
                ];
            })->values();

        return response()->json([
            'status' => 'success',
            'data' => $groupedBookings
        ]);
    }

    public function checkIn(Request $request, Booking $booking)
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
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()
            ], 422);
        }

        // Get the room
        $room = Room::findOrFail($request->room_id);

        // Check if room type matches the booking's room type
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

        // Assign room to booking and update status
        $booking->room_id = $room->id;
        $booking->status = 'checked_in';
        $booking->save();

        // Update room availability
        $room->is_available = false;
        $room->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Check-in successful',
            'data' => $booking->load(['roomType', 'room'])
        ]);
    }

    public function processBooking(Request $request, $bookingId)
    {
        // Check admin role
        if ($request->user()->role !== 'admin') {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Admin access required.'
            ], 403);
        }

        // Find all bookings in the same booking group
        $bookings = Booking::where('booking_group_id', $bookingId)->get();

        if ($bookings->isEmpty()) {
            // Check if this is an individual booking
            $booking = Booking::find($bookingId);
            if (!$booking) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Booking not found'
                ], 404);
            }
            $bookings = collect([$booking]);
        }

        // Check if any booking is already checked in
        $alreadyCheckedIn = $bookings->contains(function ($booking) {
            return $booking->status === 'checked_in' && $booking->room_id !== null;
        });

        // Create validation rules
        $rules = [
            'status' => 'required|in:confirmed,checked_in,checked_out,cancelled',
            'payment_status' => 'sometimes|required|in:paid,unpaid,pending',
            'payment_method' => 'required_if:payment_status,paid|in:cash,credit_card,bank_transfer',
            'currency' => 'sometimes|in:USD,KHR',
            'exchange_rate' => 'required_if:currency,KHR|numeric',
        ];

        // Only require room_id for check-in if not already checked in
        if ($request->status === 'checked_in' && !$alreadyCheckedIn) {
            $rules['room_id'] = 'required|exists:rooms,id';
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()
            ], 422);
        }

        // Handle specific status transitions
        foreach ($bookings as $booking) {
            // Update booking status
            $booking->status = $request->status;

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

            // Handle check-in case only if room_id is provided and booking isn't already checked in
            if ($request->status === 'checked_in' && $request->has('room_id') && $booking->room_id === null) {
                // Get the room
                $room = Room::findOrFail($request->room_id);

                // Check if room type matches the booking's room type
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

                // Assign room to booking
                $booking->room_id = $room->id;

                // Update room availability
                $room->is_available = false;
                $room->save();
            }

            // Handle check-out case
            if ($request->status === 'checked_out' && $booking->room_id) {
                // Free up the room
                $room = Room::find($booking->room_id);
                if ($room) {
                    $room->is_available = true;
                    $room->save();
                }
            }

            // Handle cancellation
            if ($request->status === 'cancelled' && $booking->room_id) {
                // Free up the room if it was assigned
                $room = Room::find($booking->room_id);
                if ($room) {
                    $room->is_available = true;
                    $room->save();
                    $booking->room_id = null; // Unassign room from booking
                }
            }

            $booking->save();
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Booking status updated successfully',
            'data' => [
                'booking_id' => $bookingId,
                'status' => $request->status,
                'payment_status' => $request->input('payment_status', $bookings->first()->payment_status),
                'updated_at' => now()
            ]
        ]);
    }

    /**
     * Delete bookings with a specific booking group ID (admin only).
     * 
     * @param Request $request
     * @param string $bookingGroupId
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroyGroup(Request $request, $bookingGroupId)
    {
        // Check admin role
        if ($request->user()->role !== 'admin') {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Admin access required.'
            ], 403);
        }

        // Find all bookings in the group
        $bookings = Booking::where('booking_group_id', $bookingGroupId)->get();

        if ($bookings->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => 'No bookings found with the specified booking group ID'
            ], 404);
        }

        // Release any rooms that might be assigned to these bookings
        foreach ($bookings as $booking) {
            if ($booking->room_id) {
                $room = Room::find($booking->room_id);
                if ($room) {
                    $room->is_available = true;
                    $room->save();
                }
            }
        }

        // Delete all bookings in the group
        $count = Booking::where('booking_group_id', $bookingGroupId)->delete();

        return response()->json([
            'status' => 'success',
            'message' => "{$count} bookings deleted successfully",
            'data' => [
                'booking_group_id' => $bookingGroupId,
                'deleted_at' => now()
            ]
        ]);
    }

    /**
     * Get all bookings for the authenticated user
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUserBookings()
    {
        $bookings = Booking::where('user_id', Auth::id())
            ->with(['roomType', 'room'])
            ->orderBy('created_at', 'desc')
            ->get();

        // Group bookings by booking_group_id
        $groupedBookings = $bookings->groupBy(function ($booking) {
            return $booking->booking_group_id ?? 'BOOKING-' . $booking->id;
        })
            ->map(function ($group) {
                $firstBooking = $group->first();

                // Create virtual booking ID if null
                $bookingGroupId = $firstBooking->booking_group_id ?? 'BOOKING-' . $firstBooking->id;

                // Get all room types in this booking
                $roomTypes = $group->map(function ($booking) {
                    return [
                        'id' => $booking->roomType->id,
                        'name' => $booking->roomType->name,
                        'price' => $booking->roomType->price,
                        'room' => $booking->room ? [
                            'id' => $booking->room->id,
                            'name' => $booking->room->name,
                        ] : null,
                    ];
                });

                return [
                    'booking_id' => $bookingGroupId,
                    'check_in_date' => $firstBooking->check_in_date,
                    'check_out_date' => $firstBooking->check_out_date,
                    'guests' => $firstBooking->guests,
                    'status' => $firstBooking->status,
                    'payment_method' => $firstBooking->payment_method,
                    'payment_status' => $firstBooking->payment_status,
                    'created_at' => $firstBooking->created_at,
                    'total_price' => $group->sum('total_price'),
                    'currency' => $firstBooking->currency ?? 'USD',
                    'room_types' => $roomTypes->values()
                ];
            })->values();

        return response()->json([
            'status' => 'success',
            'data' => $groupedBookings
        ]);
    }

    /**
     * Get all bookings for a specific user (admin only)
     *
     * @param Request $request
     * @param int $userId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUserBookingsByAdmin(Request $request, $userId)
    {
        // Check admin role
        if ($request->user()->role !== 'admin') {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Admin access required.'
            ], 403);
        }

        $bookings = Booking::where('user_id', $userId)
            ->with(['roomType', 'room', 'user'])
            ->orderBy('created_at', 'desc')
            ->get();

        // Group bookings by booking_group_id
        $groupedBookings = $bookings->groupBy(function ($booking) {
            return $booking->booking_group_id ?? 'BOOKING-' . $booking->id;
        })
            ->map(function ($group) {
                $firstBooking = $group->first();

                // Create virtual booking ID if null
                $bookingGroupId = $firstBooking->booking_group_id ?? 'BOOKING-' . $firstBooking->id;

                // Get all room types in this booking
                $roomTypes = $group->map(function ($booking) {
                    return [
                        'id' => $booking->roomType->id,
                        'name' => $booking->roomType->name,
                        'price' => $booking->roomType->price,
                        'room' => $booking->room ? [
                            'id' => $booking->room->id,
                            'name' => $booking->room->name,
                        ] : null,
                    ];
                });

                return [
                    'booking_id' => $bookingGroupId,
                    'user' => [
                        'id' => $firstBooking->user->id,
                        'name' => $firstBooking->user->name,
                        'email' => $firstBooking->user->email,
                    ],
                    'check_in_date' => $firstBooking->check_in_date,
                    'check_out_date' => $firstBooking->check_out_date,
                    'guests' => $firstBooking->guests,
                    'status' => $firstBooking->status,
                    'payment_method' => $firstBooking->payment_method,
                    'payment_status' => $firstBooking->payment_status,
                    'created_at' => $firstBooking->created_at,
                    'total_price' => $group->sum('total_price'),
                    'currency' => $firstBooking->currency ?? 'USD',
                    'room_types' => $roomTypes->values()
                ];
            })->values();

        return response()->json([
            'status' => 'success',
            'data' => $groupedBookings
        ]);
    }

    /**
     * Get all bookings from all users (admin only)
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAllUserBookings(Request $request)
    {
        // Check admin role
        if ($request->user()->role !== 'admin') {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Admin access required.'
            ], 403);
        }

        $query = Booking::with(['roomType', 'room', 'user'])
            ->orderBy('created_at', 'desc');

        // Filter by user if requested
        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        // Filter by status if requested
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Filter by payment status if requested
        if ($request->has('payment_status') && $request->payment_status !== 'all') {
            $query->where('payment_status', $request->payment_status);
        }

        // Filter by date range if provided
        if ($request->has('date_from') && $request->has('date_to')) {
            $query->where('check_in_date', '>=', $request->date_from)
                ->where('check_out_date', '<=', $request->date_to);
        }

        $bookings = $query->get();

        // Group bookings by booking_group_id
        $groupedBookings = $bookings->groupBy(function ($booking) {
            return $booking->booking_group_id ?? 'BOOKING-' . $booking->id;
        })
            ->map(function ($group) {
                $firstBooking = $group->first();

                // Create virtual booking ID if null
                $bookingGroupId = $firstBooking->booking_group_id ?? 'BOOKING-' . $firstBooking->id;

                // Get all room types in this booking
                $roomTypes = $group->map(function ($booking) {
                    return [
                        'id' => $booking->roomType->id,
                        'name' => $booking->roomType->name,
                        'price' => $booking->roomType->price,
                        'room' => $booking->room ? [
                            'id' => $booking->room->id,
                            'name' => $booking->room->name,
                        ] : null,
                    ];
                });

                return [
                    'booking_id' => $bookingGroupId,
                    'user' => [
                        'id' => $firstBooking->user->id,
                        'name' => $firstBooking->user->name,
                        'email' => $firstBooking->user->email,
                    ],
                    'check_in_date' => $firstBooking->check_in_date,
                    'check_out_date' => $firstBooking->check_out_date,
                    'guests' => $firstBooking->guests,
                    'status' => $firstBooking->status,
                    'payment_method' => $firstBooking->payment_method,
                    'payment_status' => $firstBooking->payment_status,
                    'created_at' => $firstBooking->created_at,
                    'total_price' => $group->sum('total_price'),
                    'currency' => $firstBooking->currency ?? 'USD',
                    'room_types' => $roomTypes->values()
                ];
            })->values();

        return response()->json([
            'status' => 'success',
            'data' => $groupedBookings
        ]);
    }
}
