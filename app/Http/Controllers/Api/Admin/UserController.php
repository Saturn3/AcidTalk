<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function index(Request $request)
    {
        return UserResource::collection(User::where('organization_id', $request->user()->organization_id)->where('is_bot', false)->orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|max:190|unique:users,email',
            'password' => ['required', Password::min(8)],
            'role' => ['required', Rule::in(['admin', 'member', 'guest'])],
            'title' => 'nullable|string|max:100',
        ]);
        $user = User::create($data + ['organization_id' => $request->user()->organization_id, 'email_verified_at' => now()]);
        AuthController::onboard($user);

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    public function update(Request $request, User $user)
    {
        abort_unless($user->organization_id === $request->user()->organization_id, 404);
        $data = $request->validate([
            'name' => 'sometimes|string|max:100',
            'email' => ['sometimes', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', Password::min(8)],
            'role' => ['sometimes', Rule::in(['admin', 'member', 'guest'])],
            'title' => 'nullable|string|max:100',
        ]);
        if (empty($data['password'])) {
            unset($data['password']);
        }
        if (($data['role'] ?? $user->role) !== 'admin' && $user->isAdmin()
            && User::where('organization_id', $user->organization_id)->where('role', 'admin')->count() <= 1) {
            abort(422, 'Deve restare almeno un amministratore.');
        }
        $user->update($data);

        return new UserResource($user->fresh());
    }

    public function destroy(Request $request, User $user)
    {
        abort_unless($user->organization_id === $request->user()->organization_id, 404);
        abort_if($user->id === $request->user()->id, 422, 'Non puoi eliminare il tuo account.');
        $user->delete();

        return response()->json(['ok' => true]);
    }
}
