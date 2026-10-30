<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Channel;
use App\Models\Invite;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** Registrazione self-service per email del dominio aziendale (o con token di invito). */
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', Password::min(8)],
            'invite_token' => ['nullable', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $org = Organization::current();
        $invite = $data['invite_token'] ?? null ? Invite::where('token', $data['invite_token'])->first() : null;

        if ($invite && (! $invite->isValid() || strcasecmp($invite->email, $data['email']) !== 0)) {
            throw ValidationException::withMessages(['invite_token' => 'Invito non valido, scaduto o associato a un altro indirizzo email.']);
        }
        if (! $invite && ! ($org->allow_domain_signup && $org->emailMatchesDomain($data['email']))) {
            throw ValidationException::withMessages(['email' => "Solo gli indirizzi @{$org->domain} possono iscriversi autonomamente. Chiedi un invito a un amministratore."]);
        }

        $user = User::create([
            'organization_id' => $org->id,
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'password' => $data['password'],
            'role' => $invite?->role ?? 'member',
            'email_verified_at' => $invite ? now() : null,
        ]);

        $this->onboard($user, $invite);

        return $this->issue($request, $user, 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required', 'device_name' => 'nullable|string|max:100']);
        $user = User::where('email', strtolower($data['email']))->first();
        if (! $user || ! $user->password || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Credenziali non valide.']);
        }

        return $this->issue($request, $user);
    }

    public function logout(Request $request)
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['ok' => true]);
    }

    public function me(Request $request)
    {
        return new UserResource($request->user());
    }

    public function updateMe(Request $request)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:100',
            'title' => 'nullable|string|max:100',
            'status_text' => 'nullable|string|max:100',
            'status_emoji' => 'nullable|string|max:16',
            'avatar_color' => 'nullable|string|size:7',
            'notification_prefs' => 'nullable|array',
            'notification_prefs.theme' => 'sometimes|in:light,dark,system',
            'password' => ['nullable', 'confirmed', Password::min(8)],
            'current_password' => 'nullable|string',
        ]);
        $user = $request->user();
        if (! empty($data['password'])) {
            if (! Hash::check($data['current_password'] ?? '', $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'Password attuale errata.']);
            }
            $user->password = $data['password'];
        }
        unset($data['password'], $data['current_password']);
        if (isset($data['notification_prefs'])) {
            $data['notification_prefs'] = array_merge($user->prefs(), $data['notification_prefs']);
        }
        $user->fill($data)->save();

        return new UserResource($user->fresh());
    }

    /** Token personali per client esterni (MCP, mobile) creati dall'utente stesso. */
    public function createToken(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:60']);
        $token = $request->user()->createToken($data['name']);

        return response()->json(['token' => $token->plainTextToken, 'name' => $data['name']], 201);
    }

    public function tokens(Request $request)
    {
        return $request->user()->tokens()->get(['id', 'name', 'last_used_at', 'created_at']);
    }

    public function revokeToken(Request $request, int $id)
    {
        $request->user()->tokens()->whereKey($id)->delete();

        return response()->json(['ok' => true]);
    }

    private function issue(Request $request, User $user, int $status = 200)
    {
        // Login da browser (SPA): sessione; da app mobile / API: token Sanctum.
        if ($request->expectsJson() && $request->filled('device_name')) {
            $token = $user->createToken($request->input('device_name'))->plainTextToken;

            return response()->json(['token' => $token, 'user' => (new UserResource($user))->resolve($request)], $status);
        }
        Auth::guard('web')->login($user, true);
        $request->session()->regenerate();

        return response()->json(['user' => (new UserResource($user))->resolve($request)], $status);
    }

    /** Aggiunge l'utente ai canali di default, al DM con l'AI e ai canali dell'invito. */
    public static function onboard(User $user, ?Invite $invite = null): void
    {
        $defaults = Channel::where('organization_id', $user->organization_id)->where('type', 'public')->whereIn('slug', ['generale', 'random'])->pluck('id');
        $extra = collect($invite?->channel_ids ?? []);
        $user->channels()->syncWithoutDetaching($user->isGuest() ? $extra->all() : $defaults->merge($extra)->unique()->all());
        Channel::aiDirectFor($user);
        $invite?->forceFill(['accepted_at' => now()])->save();
    }
}
