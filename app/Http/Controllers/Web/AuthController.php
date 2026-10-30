<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\AuthController as ApiAuth;
use App\Http\Controllers\Controller;
use App\Models\Invite;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('auth.login', ['org' => Organization::current()]);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate(['email' => 'required|email', 'password' => 'required']);
        if (! Auth::attempt(['email' => strtolower($credentials['email']), 'password' => $credentials['password']], $request->boolean('remember'))) {
            return back()->withErrors(['email' => 'Credenziali non valide.'])->onlyInput('email');
        }
        $request->session()->regenerate();

        return redirect()->intended('/app');
    }

    public function showRegister(Request $request)
    {
        $org = Organization::current();
        $invite = $request->query('invite') ? Invite::where('token', $request->query('invite'))->first() : null;

        return view('auth.register', ['org' => $org, 'invite' => $invite]);
    }

    public function register(Request $request)
    {
        $org = Organization::current();
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|max:190|unique:users,email',
            'password' => ['required', 'confirmed', Password::min(8)],
            'invite_token' => 'nullable|string',
        ]);
        $invite = $data['invite_token'] ?? null ? Invite::where('token', $data['invite_token'])->first() : null;
        if ($invite && (! $invite->isValid() || strcasecmp($invite->email, $data['email']) !== 0)) {
            return back()->withErrors(['email' => 'Invito non valido, scaduto o relativo a un altro indirizzo.'])->withInput();
        }
        if (! $invite && ! ($org->allow_domain_signup && $org->emailMatchesDomain($data['email']))) {
            return back()->withErrors(['email' => "L'iscrizione autonoma è riservata agli indirizzi @{$org->domain}. Per gli ospiti serve un invito."])->withInput();
        }
        $user = User::create([
            'organization_id' => $org->id, 'name' => $data['name'], 'email' => strtolower($data['email']),
            'password' => $data['password'], 'role' => $invite?->role ?? 'member', 'email_verified_at' => $invite ? now() : null,
        ]);
        ApiAuth::onboard($user, $invite);
        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect('/app');
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
