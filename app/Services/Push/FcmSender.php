<?php

namespace App\Services\Push;

use App\Models\DeviceToken;
use App\Models\Organization;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Notifiche push verso le app Android/iOS/macOS con Firebase Cloud Messaging (API HTTP v1).
 * Autenticazione OAuth2 con l'account di servizio (JWT RS256 firmato con openssl): nessuna
 * libreria aggiuntiva, funziona anche su hosting condiviso.
 */
class FcmSender
{
    private ?array $account = null;

    private bool $loaded = false;

    public function account(): ?array
    {
        if ($this->loaded) {
            return $this->account;
        }
        $this->loaded = true;
        $json = null;
        try {
            $json = Organization::current()->setting('fcm_service_account');
        } catch (\Throwable) {
        }
        if (! $json && ($path = config('services.firebase.credentials'))) {
            $path = str_starts_with($path, '/') ? $path : base_path($path);
            $json = is_readable($path) ? file_get_contents($path) : null;
        }
        $data = $json ? json_decode($json, true) : null;
        if (is_array($data) && ! empty($data['project_id']) && ! empty($data['private_key']) && ! empty($data['client_email'])) {
            $this->account = $data;
        }

        return $this->account;
    }

    public function configured(): bool
    {
        return $this->account() !== null;
    }

    public function projectId(): ?string
    {
        return $this->account()['project_id'] ?? null;
    }

    /** Token di accesso OAuth2 (valido 1 ora, in cache per 50 minuti). */
    public function accessToken(): string
    {
        $sa = $this->account() ?? throw new \RuntimeException('Firebase non configurato.');

        return Cache::remember('fcm.token.'.md5($sa['client_email'].($sa['private_key_id'] ?? '')), now()->addMinutes(50), function () use ($sa) {
            $now = time();
            $b64 = fn ($v) => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
            $head = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claims = $b64(json_encode([
                'iss' => $sa['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => $sa['token_uri'] ?? 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ]));
            $key = openssl_pkey_get_private($sa['private_key']);
            if (! $key || ! openssl_sign($head.'.'.$claims, $signature, $key, OPENSSL_ALGO_SHA256)) {
                throw new \RuntimeException('Chiave privata dell\'account di servizio non valida.');
            }
            $jwt = $head.'.'.$claims.'.'.$b64($signature);
            $res = Http::asForm()->timeout(15)->post($sa['token_uri'] ?? 'https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);
            if (! $res->successful() || ! $res->json('access_token')) {
                throw new \RuntimeException('Google OAuth: '.($res->json('error_description') ?? $res->body()));
            }

            return $res->json('access_token');
        });
    }

    /**
     * Invia la notifica a tutti i dispositivi degli utenti indicati.
     *
     * @param  array<int,int>  $userIds
     * @return array{sent:int,failed:int,removed:int,errors:array<int,string>}
     */
    public function sendToUsers(array $userIds, string $title, string $body, array $data = []): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'removed' => 0, 'errors' => []];
        if (! $userIds || ! $this->configured()) {
            return $result;
        }
        $tokens = DeviceToken::whereIn('user_id', $userIds)->where('platform', '!=', 'web')->get();
        if ($tokens->isEmpty()) {
            return $result;
        }
        $access = $this->accessToken();
        $url = 'https://fcm.googleapis.com/v1/projects/'.$this->projectId().'/messages:send';
        $data = array_map('strval', array_filter($data, fn ($v) => $v !== null));

        $responses = Http::pool(fn (Pool $pool) => $tokens->map(fn (DeviceToken $t) => $pool->as((string) $t->id)
            ->withToken($access)->timeout(10)->post($url, ['message' => [
                'token' => $t->token,
                'notification' => ['title' => $title, 'body' => $body],
                'data' => $data,
                'android' => ['priority' => 'high', 'notification' => ['channel_id' => 'messages', 'sound' => 'default', 'tag' => 'channel-'.($data['channel_id'] ?? '0')]],
                'apns' => ['headers' => ['apns-priority' => '10'], 'payload' => ['aps' => ['sound' => 'default', 'thread-id' => 'channel-'.($data['channel_id'] ?? '0')]]],
            ]]))->all());

        foreach ($tokens as $t) {
            $res = $responses[(string) $t->id] ?? null;
            if ($res instanceof \Illuminate\Http\Client\Response && $res->successful()) {
                $result['sent']++;
                $t->forceFill(['last_used_at' => now()])->save();

                continue;
            }
            $result['failed']++;
            $err = $res instanceof \Illuminate\Http\Client\Response
                ? ($res->json('error.status') ?? 'HTTP '.$res->status()).': '.($res->json('error.message') ?? '')
                : ($res instanceof \Throwable ? $res->getMessage() : 'nessuna risposta');
            $result['errors'][] = $err;
            // token non più valido (app disinstallata, token ruotato)
            if ($res instanceof \Illuminate\Http\Client\Response && (in_array($res->status(), [404, 410]) || str_contains($res->body(), 'UNREGISTERED') || (str_contains($res->body(), 'INVALID_ARGUMENT') && str_contains($res->body(), 'token')))) {
                $t->delete();
                $result['removed']++;
            }
        }
        if ($result['failed']) {
            Log::warning('FCM: invii non riusciti', $result);
            Cache::put('acidtalk.last_push_error', ['at' => now()->toIso8601String(), 'error' => $result['errors'][0] ?? ''], now()->addDays(7));
        }
        if ($result['sent']) {
            Cache::put('acidtalk.last_push', now()->toIso8601String(), now()->addDays(30));
        }

        return $result;
    }
}
