<?php

namespace App\Http\Controllers\Settings;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class UserManagementController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/Users/Index', [
            'users' => User::orderBy('name')->get()->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'is_active' => $user->is_active,
                'avatar' => $user->avatar,
                'created_at' => $user->created_at,
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'role' => 'required|string|in:' . implode(',', array_column(UserRole::cases(), 'value')),
        ]);

        User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => UserRole::from($validated['role']),
            'password' => bcrypt(Str::random(32)),
            'is_active' => true,
        ]);

        return back()->with('success', 'User invited successfully.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'role' => 'sometimes|string|in:' . implode(',', array_column(UserRole::cases(), 'value')),
            'is_active' => 'sometimes|boolean',
        ]);

        if (isset($validated['role'])) {
            $validated['role'] = UserRole::from($validated['role']);
        }

        $user->update($validated);

        return back()->with('success', 'User updated.');
    }
}
