<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invite;
use App\Models\Organization;
use App\Notifications\InviteNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

class InviteController extends Controller
{
    public function index(Request $request)
    {
        $q = Invite::where('organization_id', $request->user()->organization_id)->with('inviter:id,name')->latest();
        if (! $request->user()->isAdmin()) {
            $q->where('invited_by', $request->user()->id);
        }

        return $q->get()->map(fn (Invite $i) => $this->present($i));
    }

    /** Crea un invito per un ospite esterno (o un membro) e invia la mail. */
    public function store(Request $request)
    {
        $user = $request->user();
        $org = Organization::current();
        abort_unless($user->isAdmin() || ($org->setting('allow_guest_invites') && ! $user->isGuest()), 403, 'Non puoi inviare inviti.');
        $data = $request->validate([
            'email' => 'required|email|max:190|unique:users,email',
            'role' => ['nullable', Rule::in(['guest', 'member'])],
            'channel_ids' => 'array',
            'channel_ids.*' => 'integer|exists:channels,id',
            'message' => 'nullable|string|max:500',
        ]);
        $role = $data['role'] ?? ($org->emailMatchesDomain($data['email']) ? 'member' : 'guest');
        abort_if($role === 'member' && ! $user->isAdmin(), 403, 'Solo gli amministratori possono invitare membri interni.');

        Invite::where('organization_id', $org->id)->where('email', strtolower($data['email']))->whereNull('accepted_at')->delete();
        $invite = Invite::create([
            'organization_id' => $org->id,
            'invited_by' => $user->id,
            'email' => strtolower($data['email']),
            'role' => $role,
            'channel_ids' => $data['channel_ids'] ?? [],
        ]);

        try {
            Notification::route('mail', $invite->email)->notify(new InviteNotification($invite, $data['message'] ?? null));
        } catch (\Throwable $e) {
            report($e); // la mail è best-effort: il link resta disponibile nel pannello
        }

        return response()->json($this->present($invite), 201);
    }

    public function destroy(Request $request, Invite $invite)
    {
        abort_unless($request->user()->isAdmin() || $invite->invited_by === $request->user()->id, 403);
        $invite->delete();

        return response()->json(['ok' => true]);
    }

    /** Dettagli pubblici di un invito (per la pagina di accettazione). */
    public function show(string $token)
    {
        $invite = Invite::where('token', $token)->firstOrFail();

        return response()->json([
            'email' => $invite->email, 'role' => $invite->role, 'valid' => $invite->isValid(),
            'organization' => Organization::current()->name, 'invited_by' => $invite->inviter?->name,
        ]);
    }

    private function present(Invite $i): array
    {
        return [
            'id' => $i->id, 'email' => $i->email, 'role' => $i->role, 'url' => $i->url(), 'valid' => $i->isValid(),
            'invited_by' => $i->inviter?->name, 'channel_ids' => $i->channel_ids ?? [],
            'expires_at' => $i->expires_at?->toIso8601String(), 'accepted_at' => $i->accepted_at?->toIso8601String(), 'created_at' => $i->created_at?->toIso8601String(),
        ];
    }
}
