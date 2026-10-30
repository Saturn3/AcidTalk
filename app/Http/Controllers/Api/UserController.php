<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /** Rubrica dell'organizzazione (per DM, menzioni, aggiunta membri). */
    public function index(Request $request)
    {
        $q = User::where('organization_id', $request->user()->organization_id)->orderBy('name');
        if ($s = $request->string('q')->trim()->toString()) {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%"));
        }

        return UserResource::collection($q->limit(200)->get());
    }

    public function show(Request $request, User $user)
    {
        abort_unless($user->organization_id === $request->user()->organization_id, 404);

        return new UserResource($user);
    }
}
