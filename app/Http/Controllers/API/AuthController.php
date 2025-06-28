<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "name" => "required|string|max:255",
            "email" => "required|string|email",
            "password" => "required|string|min:8",
            "confirm_password" => "required|string|min:8|same:password",
        ]);

        if ($validator->fails()) {
            return response()->json([
                "status" => 0,
                "message" => "error",
                "data" => $validator->errors()->all()
            ], 422);
        };

        $user = User::create([
            "name" => $request->name,
            "email" => $request->email,
            "password" => bcrypt($request->password),
            "role" => 'user',
        ]);

        $response = [];
        $response["name"] = $user->name;
        $response["email"] = $user->email;
        $response["token"] = $user->createToken("MyApp")->plainTextToken;
        $response["role"] = $user->role;
        $response["created_at"] = $user->created_at->format('Y-m-d H:i:s');

        return response()->json([
            "status" => "success",
            "message" => "User registered successfully",
            "data" => $response
        ]);
    }

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "email" => "required|string|email",
            "password" => "required|string|min:8",
        ]);

        if ($validator->fails()) {
            return response()->json([
                "status" => 0,
                "message" => "error",
                "data" => $validator->errors()->all()
            ], 422);
        };

        if (Auth::attempt(['email' => $request->email, 'password' => $request->password])) {
            $user = Auth::user();

            $response = [];
            $response["name"] = $user->name;
            $response["email"] = $user->email;
            $response["role"] = $user->role;
            $response["token"] = $user->createToken("MyApp")->plainTextToken;

            return response()->json([
                'status' => 'success',
                'message' => 'User logged in successfully',
                'data' => $response
            ]);
        }
        // If authentication fails
        return response()->json([
            'status' => 'error',
            'message' => 'Invalid credentials',
        ], 401);
    }

    public function registerAdmin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "name" => "required",
            "email" => "required|string|email|unique:users,email",
            "password" => "required|string|min:8",
            "confirm_password" => "required|string|min:8|same:password",
        ]);

        if ($validator->fails()) {
            return response()->json([
                "status" => 0,
                "message" => "error",
                "data" => $validator->errors()->all()
            ], 422);
        };

        $user = User::create([
            "name" => $request->name,
            "email" => $request->email,
            "password" => bcrypt($request->password),
            "role" => "admin",
        ]);

        $response = [];
        $response["name"] = $user->name;
        $response["email"] = $user->email;
        $response["token"] = $user->createToken("MyApp")->plainTextToken;
        $response["role"] = $user->role;
        $response["created_at"] = $user->created_at->format('Y-m-d H:i:s');

        return response()->json([
            "status" => "success",
            "message" => "Admin registered successfully",
            "data" => $response
        ]);
    }
}
