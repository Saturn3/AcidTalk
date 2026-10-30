<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Invite;
use App\Models\Organization;
use Illuminate\Http\Request;

class PageController extends Controller
{
    public function welcome()
    {
        $org = Organization::current();

        return view('welcome', ['org' => $org]);
    }

    /** Shell della SPA: inietta utente, organizzazione e configurazione realtime. */
    public function app(Request $request)
    {
        $org = Organization::current();
        $user = $request->user();

        return view('app', [
            'bootstrap' => [
                'user' => (new UserResource($user))->resolve($request),
                'org' => \App\Support\ClientConfig::org($org),
                'csrf' => csrf_token(),
                'reverb' => config('broadcasting.default') === 'reverb' ? [
                    'key' => config('broadcasting.connections.reverb.key'),
                    'host' => config('broadcasting.connections.reverb.options.host'),
                    'port' => (int) config('broadcasting.connections.reverb.options.port'),
                    'scheme' => config('broadcasting.connections.reverb.options.scheme'),
                ] : null,
                'pusher' => config('broadcasting.default') === 'pusher' ? [
                    'key' => config('broadcasting.connections.pusher.key'),
                    'cluster' => config('broadcasting.connections.pusher.options.cluster'),
                ] : null,
                'version' => \App\Support\ClientConfig::VERSION,
            ],
        ]);
    }

    public function invite(string $token)
    {
        $invite = Invite::where('token', $token)->first();

        return view('auth.invite', ['invite' => $invite, 'org' => Organization::current(), 'token' => $token]);
    }
}
