<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Channel;
use App\Models\User;
use App\Services\BotService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Utenti bot comandabili via API: non possono accedere all'interfaccia (nessuna password),
 * scrivono con un token Bearer e ricevono menzioni/DM sul webhook opzionale.
 */
class BotController extends Controller
{
    public function index(Request $request)
    {
        $bots = User::where('organization_id', $request->user()->organization_id)->where('is_bot', true)->orderBy('name')->get();

        return response()->json(['data' => $bots->map(fn (User $b) => $this->present($request, $b))->values()]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $org = $request->user()->organization_id;
        $base = Str::slug($data['name']) ?: 'bot';
        $email = $base.'@bot.local';
        for ($i = 2; User::where('email', $email)->exists(); $i++) {
            $email = $base.'-'.$i.'@bot.local';
        }
        $bot = User::create([
            'organization_id' => $org, 'name' => $data['name'], 'email' => $email, 'password' => null,
            'role' => 'member', 'is_bot' => true, 'bot_owner_id' => $request->user()->id,
            'bot_description' => $data['description'] ?? null, 'bot_webhook_url' => $data['webhook_url'] ?? null,
            'bot_webhook_secret' => Str::random(40), 'title' => 'Bot', 'email_verified_at' => now(),
        ]);
        $this->syncChannels($bot, $data['channel_ids'] ?? null);
        $token = $bot->createToken('bot', ['*'])->plainTextToken;

        return response()->json(['data' => $this->present($request, $bot), 'token' => $token, 'webhook_secret' => $bot->bot_webhook_secret], 201);
    }

    public function update(Request $request, User $bot)
    {
        $this->own($request, $bot);
        $data = $this->validated($request, true);
        $bot->fill(array_filter([
            'name' => $data['name'] ?? null,
        ]) + (array_key_exists('description', $data) ? ['bot_description' => $data['description']] : [])
          + (array_key_exists('webhook_url', $data) ? ['bot_webhook_url' => $data['webhook_url']] : []))->save();
        $this->syncChannels($bot, $data['channel_ids'] ?? null);

        return response()->json(['data' => $this->present($request, $bot->fresh())]);
    }

    /** Nuovo token (i precedenti vengono revocati) e, a richiesta, nuovo segreto del webhook. */
    public function token(Request $request, User $bot)
    {
        $this->own($request, $bot);
        $bot->tokens()->delete();
        if ($request->boolean('rotate_secret')) {
            $bot->forceFill(['bot_webhook_secret' => Str::random(40)])->save();
        }

        return response()->json(['token' => $bot->createToken('bot', ['*'])->plainTextToken, 'webhook_secret' => $bot->bot_webhook_secret]);
    }

    public function revoke(Request $request, User $bot)
    {
        $this->own($request, $bot);
        $bot->tokens()->delete();

        return response()->json(['ok' => true]);
    }

    /** Invia al webhook un evento di prova (event=ping) e mostra la risposta. */
    public function ping(Request $request, User $bot)
    {
        $this->own($request, $bot);
        abort_unless($bot->bot_webhook_url, 422, 'Il bot non ha un webhook configurato.');
        $body = json_encode(['event' => 'ping', 'bot' => ['id' => $bot->id, 'name' => $bot->name, 'handle' => $bot->handle()], 'sent_at' => now()->toIso8601String()]);
        try {
            $res = Http::withHeaders(['X-AcidTalk-Event' => 'ping', 'X-AcidTalk-Signature' => BotService::sign($body, (string) $bot->bot_webhook_secret)])
                ->withBody($body, 'application/json')->timeout(10)->post($bot->bot_webhook_url);

            return response()->json(['ok' => $res->successful(), 'status' => $res->status(), 'body' => mb_substr($res->body(), 0, 500)]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    public function destroy(Request $request, User $bot)
    {
        $this->own($request, $bot);
        $bot->tokens()->delete();
        $bot->channels()->detach();
        $bot->delete();

        return response()->json(['ok' => true]);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => "{$req}|string|max:60",
            'description' => 'nullable|string|max:500',
            'webhook_url' => 'nullable|url|max:500',
            'channel_ids' => 'nullable|array',
            'channel_ids.*' => 'integer',
        ]);
    }

    private function own(Request $request, User $bot): void
    {
        abort_unless($bot->is_bot && $bot->organization_id === $request->user()->organization_id, 404);
    }

    private function syncChannels(User $bot, ?array $ids): void
    {
        if ($ids === null) {
            return;
        }
        $valid = Channel::where('organization_id', $bot->organization_id)->whereIn('type', ['public', 'private'])->whereIn('id', $ids)->pluck('id');
        $current = $bot->channels()->whereIn('type', ['public', 'private'])->pluck('channels.id');
        $bot->channels()->detach($current->diff($valid)->all());
        $bot->channels()->syncWithoutDetaching($valid->all());
    }

    private function present(Request $request, User $bot): array
    {
        return (new UserResource($bot))->resolve($request) + [
            'webhook_url' => $bot->bot_webhook_url,
            'channel_ids' => $bot->channels()->whereIn('type', ['public', 'private'])->pluck('channels.id')->values(),
            'tokens' => $bot->tokens()->count(),
            'last_used_at' => $bot->tokens()->max('last_used_at'),
            'last_delivery' => Cache::get('bot.'.$bot->id.'.last_delivery'),
        ];
    }
}
