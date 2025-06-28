<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RoomController extends Controller
{
    /**
     * Display a listing of available rooms.
     */
    public function index(Request $request)
    {
        $query = Room::with('roomType');

        // Filter by room type if provided
        if ($request->has('room_type_id')) {
            $query->where('room_type_id', $request->room_type_id);
        }

        // Filter by availability
        if ($request->has('available') && $request->available) {
            $query->where('is_available', true);
        }

        $rooms = $query->get();

        return response()->json([
            'status' => 'success',
            'message' => 'Rooms fetched successfully',
            'data' => $rooms
        ]);
    }

    /**
     * Store a newly created room.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'room_type_id' => 'required|exists:room_types,id',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'is_available' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()
            ], 422);
        }

        $room = Room::create($request->all());

        return response()->json([
            'status' => 'success',
            'message' => 'Room created successfully',
            'data' => $room->load('roomType')
        ], 201);
    }

    /**
     * Display the specified room.
     */
    public function show(Room $room)
    {
        return response()->json([
            'status' => 'success',
            'data' => $room->load('roomType')
        ]);
    }

    /**
     * Update the specified room.
     */
    public function update(Request $request, Room $room)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'room_type_id' => 'required|exists:room_types,id',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'is_available' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()
            ], 422);
        }

        $room->update($request->all());

        return response()->json([
            'status' => 'success',
            'message' => 'Room updated successfully',
            'data' => $room->load('roomType')
        ]);
    }

    /**
     * Remove the specified room.
     */
    public function destroy(Room $room)
    {
        // Check if room has any active bookings
        if ($room->bookings()->whereIn('status', ['confirmed', 'checked_in'])->count() > 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot delete room with active bookings'
            ], 400);
        }

        $room->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Room deleted successfully'
        ]);
    }
}
