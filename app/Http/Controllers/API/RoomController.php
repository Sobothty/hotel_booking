<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class RoomController extends Controller
{
    public function index(Request $request)
    {
        // Logic to fetch and return posts
        // For example, you might return all posts from a database
        // return Post::all();

        return response()->json([
            'status' => 'success',
            'message' => 'Posts fetched successfully',
            'data' => [] 
        ]);
    }
}
