<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    /**
     * Display a listing of users
     */
    public function index(Request $request): JsonResponse
    {
        $users = User::query()
            ->when($request->search, fn($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->paginate(15);
            
        return response()->json($users);
    }
    
    /**
     * Store a new user
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'password' => 'required|min:8',
        ]);
        
        $user = User::create($validated);
        
        return response()->json($user, 201);
    }
    
    /**
     * Display the specified user
     */
    public function show(User $user): JsonResponse
    {
        return response()->json($user->load('posts'));
    }
    
    /**
     * Update the specified user
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $user->id,
        ]);
        
        $user->update($validated);
        
        return response()->json($user);
    }
    
    /**
     * Remove the specified user
     */
    public function destroy(User $user): JsonResponse
    {
        $user->delete();
        
        return response()->json(null, 204);
    }
}
