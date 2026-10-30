<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Ai\AiException;
use App\Services\Ai\AiManager;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    private const SECRETS = ['openai_api_key', 'anthropic_api_key', 'fcm_service_account'];

    public function show()
    {
        return response()->json($this->present(Organization::current()));
    }

    public function update(Request $request)
    {
        $org = Organization::current();
        $data = $request->validate([
            'name' => 'sometimes|string|max:100',
            'domain' => ['sometimes', 'string', 'max:190', 'regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i'],
            'allow_domain_signup' => 'sometimes|boolean',
            'settings' => 'sometimes|array',
            'settings.ai_provider' => ['nullable', Rule::in(['ollama', 'openai', 'anthropic'])],
            'settings.ai_model' => 'nullable|string|max:100',
            'settings.ai_bot_name' => 'nullable|string|max:40',
            'settings.ai_system_prompt' => 'nullable|string|max:4000',
            'settings.ollama_base_url' => 'nullable|url',
            'settings.openai_api_key' => 'nullable|string|max:300',
            'settings.anthropic_api_key' => 'nullable|string|max:300',
            'settings.video_provider' => ['nullable', Rule::in(['jitsi', 'meet'])],
            'settings.jitsi_domain' => 'nullable|string|max:190',
            'settings.meet_link' => 'nullable|url',
            'settings.max_upload_mb' => 'nullable|integer|min:1|max:500',
            'settings.sidebar_dm_days' => 'nullable|integer|min:1|max:3650',
            'settings.sidebar_channel_days' => 'nullable|integer|min:1|max:3650',
            'settings.allow_guest_invites' => 'nullable|boolean',
            'settings.read_receipts' => 'nullable|boolean',
            'settings.emoji_autoconvert' => 'nullable|boolean',
            'settings.fcm_service_account' => 'nullable|string|max:20000',
        ]);
        if (! empty($data['settings']['fcm_service_account']) && ! str_starts_with($data['settings']['fcm_service_account'], '••')) {
            $sa = json_decode($data['settings']['fcm_service_account'], true);
            abort_unless(is_array($sa) && ! empty($sa['project_id']) && ! empty($sa['private_key']) && ! empty($sa['client_email']), 422,
                'Il JSON dell\'account di servizio Firebase non è valido: servono project_id, client_email e private_key.');
        }

        if (isset($data['settings'])) {
            $incoming = $data['settings'];
            // le chiavi segrete mascherate ("••••") non sovrascrivono il valore salvato
            foreach (self::SECRETS as $k) {
                if (array_key_exists($k, $incoming) && (str_starts_with((string) $incoming[$k], '••') || $incoming[$k] === null)) {
                    unset($incoming[$k]);
                }
            }
            foreach ((array) $request->input('clear_secrets', []) as $k) {
                if (in_array($k, self::SECRETS, true)) {
                    $incoming[$k] = null;
                }
            }
            if (array_key_exists('ai_model', $incoming) || array_key_exists('ai_provider', $incoming)) {
                $provider = $incoming['ai_provider'] ?? $org->setting('ai_provider') ?? config('ai.default');
                $incoming['ai_model'] = AiManager::modelFor((string) $provider, $incoming['ai_model'] ?? $org->setting('ai_model'));
            }
            $data['settings'] = array_merge($org->settings ?? [], $incoming);
        }
        $org->fill($data)->save();

        return response()->json($this->present($org->fresh()));
    }

    /**
     * Prova il provider AI con le impostazioni salvate o con quelle inserite nel form
     * (non ancora salvate) e restituisce l'errore esatto del provider.
     */
    public function testAi(Request $request, AiManager $ai)
    {
        $override = $request->validate([
            'ai_provider' => ['nullable', Rule::in(['ollama', 'openai', 'anthropic'])],
            'ai_model' => 'nullable|string|max:100',
            'ollama_base_url' => 'nullable|url',
            'openai_api_key' => 'nullable|string|max:300',
            'anthropic_api_key' => 'nullable|string|max:300',
        ]);
        @set_time_limit(150);
        $org = Organization::current();
        $driver = $ai->driver($org, $override);
        $started = microtime(true);
        try {
            $reply = $driver->chat([['role' => 'user', 'content' => 'Rispondi solo con: OK, AcidTalk è collegato.']], 'Sei un test di connessione. Rispondi in una riga.');
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'provider' => $driver->name(),
                'model' => $driver->model(),
                'error' => $e instanceof AiException ? $e->getMessage() : class_basename($e).': '.$e->getMessage(),
            ]);
        }

        return response()->json([
            'ok' => true,
            'provider' => $driver->name(),
            'model' => $driver->model(),
            'reply' => mb_substr($reply, 0, 300),
            'ms' => (int) ((microtime(true) - $started) * 1000),
            'queue_worker' => (bool) config('queue.worker'),
        ]);
    }

    private function present(Organization $org): array
    {
        $settings = $org->allSettings();
        foreach (self::SECRETS as $k) {
            $settings[$k] = ! empty($settings[$k]) ? '••••'.substr((string) $settings[$k], -4) : null;
        }
        if (! empty($settings['fcm_service_account'])) {
            $settings['fcm_service_account'] = '•••• account di servizio salvato';
        }
        $settings['fcm_project_id'] = app(\App\Services\Push\FcmSender::class)->projectId();
        $settings['fcm_from_env'] = (bool) config('services.firebase.credentials');
        $settings['ai_provider'] = $settings['ai_provider'] ?: config('ai.default');
        // il modello salvato vale solo se compatibile con il provider; il default è mostrato a parte
        $settings['ai_model'] = AiManager::modelFor($settings['ai_provider'], $settings['ai_model'] ?? null);
        $settings['ai_model_defaults'] = [
            'ollama' => config('ai.providers.ollama.model'),
            'openai' => config('ai.providers.openai.model'),
            'anthropic' => config('ai.providers.anthropic.model'),
        ];
        $settings['ollama_base_url'] = $settings['ollama_base_url'] ?? config('ai.providers.ollama.base_url');

        return [
            'name' => $org->name,
            'domain' => $org->domain,
            'allow_domain_signup' => $org->allow_domain_signup,
            'settings' => $settings,
            'env_defaults' => [
                'ai_provider' => config('ai.default'),
                'has_openai_key' => (bool) config('ai.providers.openai.api_key'),
                'has_anthropic_key' => (bool) config('ai.providers.anthropic.api_key'),
            ],
            'stats' => [
                'users' => $org->users()->count(),
                'channels' => $org->channels()->whereNull('archived_at')->count(),
                'messages' => \App\Models\Message::whereIn('channel_id', $org->channels()->select('id'))->count(),
                'invites_pending' => $org->invites()->whereNull('accepted_at')->count(),
            ],
        ];
    }
}
