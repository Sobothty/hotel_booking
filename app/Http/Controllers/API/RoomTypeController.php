<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\RoomType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RoomTypeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $roomTypes = RoomType::all();
        return response()->json([
            'status' => 'success',
            'data' => $roomTypes
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:room_types',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()
            ], 422);
        }

        $roomType = RoomType::create($request->all());

        return response()->json([
            'status' => 'success',
            'message' => 'Room type created successfully',
            'data' => $roomType
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(RoomType $roomType)
    {
        return response()->json([
            'status' => 'success',
            'data' => $roomType
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, RoomType $roomType)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:room_types,name,' . $roomType->id,
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()
            ], 422);
        }

        $roomType->update($request->all());

        return response()->json([
            'status' => 'success',
            'message' => 'Room type updated successfully',
            'data' => $roomType
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(RoomType $roomType)
    {
        // Check if there are any rooms using this type
        if ($roomType->rooms()->count() > 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot delete room type that has rooms assigned to it'
            ], 400);
        }

        $roomType->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Room type deleted successfully'
        ]);
    }
}
