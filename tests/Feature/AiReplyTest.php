<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiReplyTest extends TestCase
{
    use RefreshDatabase;

    protected function fakeAnthropic(string $validKey = 'sk-ant-ok'): void
    {
        Http::fake(function (Request $r) use ($validKey) {
            if ($r->header('x-api-key')[0] !== $validKey) {
                return Http::response(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']], 401);
            }
            if (str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/v1/models')) {
                return Http::response(['data' => [['id' => 'claude-opus-5-5'], ['id' => 'claude-sonnet-5-5'], ['id' => 'claude-haiku-5-5']]]);
            }
            if ($r['model'] !== 'claude-sonnet-5-5') {
                return Http::response(['type' => 'error', 'error' => ['type' => 'not_found_error', 'message' => 'model: '.$r['model']]], 404);
            }

            return Http::response(['content' => [['type' => 'text', 'text' => 'Ciao, sono Claude.']]]);
        });
    }

    public function test_anthropic_reply_without_queue_worker_and_stale_model(): void
    {
        Cache::flush();
        config(['queue.worker' => false, 'ai.providers.anthropic.model' => 'claude-sonnet-4-20250514']);
        // modello rimasto dal provider precedente: va ignorato
        Organization::current()->update(['settings' => ['ai_provider' => 'anthropic', 'ai_model' => 'llama3.1', 'anthropic_api_key' => 'sk-ant-ok']]);
        $this->fakeAnthropic();

        $user = User::factory()->create();
        $dm = Channel::aiDirectFor($user);
        $this->actingAs($user)->postJson("/api/channels/{$dm->id}/messages", ['body' => 'ciao'])->assertCreated();

        $reply = Message::where('channel_id', $dm->id)->where('is_ai', true)->first();
        $this->assertNotNull($reply, 'la risposta AI deve essere generata senza cron/worker');
        $this->assertSame('Ciao, sono Claude.', $reply->body);
        $this->assertSame('claude-sonnet-5-5', $reply->meta['model']);
        $this->assertSame('claude-sonnet-5-5', Cache::get('ai.anthropic.auto_model'));
    }

    public function test_invalid_key_posts_readable_error(): void
    {
        config(['queue.worker' => false]);
        Organization::current()->update(['settings' => ['ai_provider' => 'anthropic', 'anthropic_api_key' => 'sk-ant-wrong']]);
        $this->fakeAnthropic();
        $user = User::factory()->create();
        $dm = Channel::aiDirectFor($user);
        $this->actingAs($user)->postJson("/api/channels/{$dm->id}/messages", ['body' => 'ciao'])->assertCreated();

        $reply = Message::where('channel_id', $dm->id)->where('is_ai', true)->firstOrFail();
        $this->assertTrue($reply->meta['error']);
        $this->assertStringContainsString('chiave API non valida', $reply->body);
        $this->assertStringContainsString('invalid x-api-key', $reply->body);
    }

    public function test_admin_can_test_unsaved_settings_and_saving_drops_foreign_model(): void
    {
        Cache::flush();
        $this->fakeAnthropic();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson('/api/admin/ai/test', ['ai_provider' => 'anthropic', 'anthropic_api_key' => 'sk-ant-wrong'])
            ->assertOk()->assertJson(['ok' => false])->assertJsonPath('provider', 'anthropic');
        $this->actingAs($admin)->postJson('/api/admin/ai/test', ['ai_provider' => 'anthropic', 'ai_model' => 'claude-sonnet-5-5', 'anthropic_api_key' => 'sk-ant-ok'])
            ->assertOk()->assertJson(['ok' => true, 'model' => 'claude-sonnet-5-5']);

        $this->actingAs($admin)->putJson('/api/admin/settings', ['settings' => ['ai_provider' => 'anthropic', 'ai_model' => 'llama3.1', 'anthropic_api_key' => 'sk-ant-ok']])
            ->assertOk()->assertJsonPath('settings.ai_model', null)->assertJsonPath('settings.anthropic_api_key', '••••t-ok');
        $this->assertNull(Organization::current()->fresh()->setting('ai_model'));
    }
}
