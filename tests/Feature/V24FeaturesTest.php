<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\DeviceToken;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Services\BotService;
use App\Services\MessageRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class V24FeaturesTest extends TestCase
{
    use RefreshDatabase;

    private function anthropic(): void
    {
        Organization::current()->update(['settings' => ['ai_provider' => 'anthropic', 'ai_model' => 'claude-sonnet-5-5', 'anthropic_api_key' => 'sk-ant-ok']]);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Risposta di Claude']]])]);
    }

    public function test_client_driven_ai_reply_is_synchronous_and_idempotent(): void
    {
        Cache::flush();
        $this->anthropic();
        $user = User::factory()->create();
        $dm = Channel::aiDirectFor($user);

        $res = $this->actingAs($user)->withHeader('X-AcidTalk-Tasks', 'client')
            ->postJson("/api/channels/{$dm->id}/messages", ['body' => 'ciao'])->assertCreated();
        $id = $res->json('data.id');
        $this->assertSame(['ai'], $res->json('data.meta.pending'));
        $this->assertSame(0, Message::where('channel_id', $dm->id)->where('is_ai', true)->count(), 'con il client non parte nulla in background');

        $first = $this->actingAs($user)->postJson("/api/messages/{$id}/tasks/ai")->assertOk();
        $this->assertSame('Risposta di Claude', $first->json('replies.0.body'));
        $this->assertSame([], $first->json('pending'));
        $second = $this->actingAs($user)->postJson("/api/messages/{$id}/tasks/ai")->assertOk();
        $this->assertSame($first->json('replies.0.id'), $second->json('replies.0.id'));
        $this->assertSame(1, Message::where('channel_id', $dm->id)->where('is_ai', true)->count());
    }

    public function test_cron_sweep_recovers_pending_ai_reply(): void
    {
        $this->anthropic();
        $user = User::factory()->create();
        $dm = Channel::aiDirectFor($user);
        $this->actingAs($user)->withHeader('X-AcidTalk-Tasks', 'client')
            ->postJson("/api/channels/{$dm->id}/messages", ['body' => '@ai ciao'])->assertCreated();
        $this->travel(2)->minutes();
        $this->artisan('acidtalk:pending')->assertSuccessful();
        $this->assertSame('Risposta di Claude', Message::where('channel_id', $dm->id)->where('is_ai', true)->value('body'));
    }

    public function test_notifications_are_created_without_queue_worker(): void
    {
        config(['queue.default' => 'database']);
        $a = User::factory()->create();
        $b = User::factory()->create();
        $dm = Channel::create(['organization_id' => $a->organization_id, 'name' => 'dm', 'type' => 'dm', 'created_by' => $a->id]);
        $dm->members()->attach([$a->id, $b->id]);
        $this->actingAs($a)->postJson("/api/channels/{$dm->id}/messages", ['body' => 'ciao Bea'])->assertCreated();
        $this->assertSame(1, $b->fresh()->unreadNotifications()->count(), 'la notifica in app non deve dipendere dalla coda');
    }

    public function test_read_receipts_are_returned_with_messages(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create(['name' => 'Bea Rossi']);
        $c = Channel::create(['organization_id' => $a->organization_id, 'name' => 'generale', 'type' => 'public', 'created_by' => $a->id]);
        $c->members()->attach([$a->id, $b->id]);
        $this->actingAs($a)->postJson("/api/channels/{$c->id}/messages", ['body' => 'letto?'])->assertCreated();

        $reads = $this->actingAs($a)->getJson("/api/channels/{$c->id}/messages")->json('meta.reads');
        $this->assertSame($b->id, $reads[0]['user_id']);
        $this->assertNull($reads[0]['last_read_at']);

        $this->travel(3)->seconds();
        $this->actingAs($b)->postJson("/api/channels/{$c->id}/read")->assertOk();
        $reads = $this->actingAs($a)->getJson("/api/channels/{$c->id}/reads")->json('data');
        $this->assertNotNull($reads[0]['last_read_at']);
        $this->assertSame('Bea Rossi', $reads[0]['name']);

        Organization::current()->update(['settings' => ['read_receipts' => false]]);
        $this->assertSame([], $this->actingAs($a)->getJson("/api/channels/{$c->id}/messages")->json('meta.reads'));
    }

    public function test_avatar_upload_is_cropped_and_served(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('foto.jpg', 800, 400);
        $res = $this->actingAs($user)->post('/api/me/avatar', ['avatar' => $file], ['Accept' => 'application/json'])->assertOk();
        $url = $res->json('data.avatar_url');
        $this->assertStringContainsString('/media/avatars/', $url);
        $path = $user->fresh()->avatar_path;
        Storage::disk('public')->assertExists($path);
        [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($path));
        $this->assertSame([256, 256], [$w, $h]);

        $this->actingAs($user)->deleteJson('/api/me/avatar')->assertOk()->assertJsonPath('data.avatar_url', null);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_emoji_shortcodes_emoticons_and_jumbo(): void
    {
        $r = app(MessageRenderer::class);
        $this->assertStringContainsString('👍', $r->render('ok :+1: grazie'));
        $this->assertStringContainsString('❤️', $r->render('ti voglio bene <3'));
        $this->assertStringContainsString('🙂', $r->render('ciao :)'));
        $this->assertStringContainsString('🎉', $r->render('evviva :festa:'));
        $html = $r->render('vedi http://example.com/a:)b e `:)` in codice');
        $this->assertStringNotContainsString('🙂', $html);
        $this->assertStringContainsString('<code>:)</code>', $html);
        $this->assertStringContainsString('class="jumbo"', $r->render(':fuoco: :fuoco:'));
        $this->assertStringNotContainsString('jumbo', $r->render('ciao 🔥'));
        $this->assertStringContainsString('<span class="mention">@mario</span>', $r->render('ciao @mario'));
    }

    public function test_bot_lifecycle_api_and_webhook(): void
    {
        $admin = User::factory()->admin()->create();
        $c = Channel::create(['organization_id' => $admin->organization_id, 'name' => 'generale', 'type' => 'public', 'created_by' => $admin->id]);
        $c->members()->attach($admin->id);

        $res = $this->actingAs($admin)->postJson('/api/admin/bots', [
            'name' => 'Meteo', 'description' => 'Previsioni', 'webhook_url' => 'https://bot.example.com/hook', 'channel_ids' => [$c->id],
        ])->assertCreated();
        $token = $res->json('token');
        $secret = $res->json('webhook_secret');
        $botId = $res->json('data.id');
        $this->assertTrue($res->json('data.is_bot'));
        $this->assertSame([$c->id], $res->json('data.channel_ids'));
        $this->assertNotContains($botId, collect($this->actingAs($admin)->getJson('/api/admin/users')->json('data'))->pluck('id'));

        // il bot scrive con il proprio token
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson("/api/channels/{$c->id}/messages", ['body' => 'Buongiorno, oggi sole'])
            ->assertCreated()->assertJsonPath('data.user.is_bot', true);
        // ma non può accedere all'interfaccia web
        $this->assertNull(User::find($botId)->password);
        $this->app['auth']->forgetGuards();
        $this->withoutToken()->postJson('/api/auth/login', ['email' => User::find($botId)->email, 'password' => 'qualsiasi'])->assertStatus(422);

        // menzione → webhook firmato → risposta pubblicata come bot
        Http::fake(['bot.example.com/*' => Http::response(['text' => 'Domani pioggia'])]);
        $this->app['auth']->forgetGuards();
        $this->actingAs($admin)->postJson("/api/channels/{$c->id}/messages", ['body' => '@meteo domani?'])->assertCreated();
        Http::assertSent(function (Request $r) use ($secret) {
            return $r->url() === 'https://bot.example.com/hook'
                && $r->header('X-AcidTalk-Signature')[0] === BotService::sign($r->body(), $secret)
                && $r['message']['body'] === '@meteo domani?';
        });
        $this->assertSame('Domani pioggia', Message::where('user_id', $botId)->latest('id')->value('body'));

        // nuovo token: il vecchio non vale più
        $this->actingAs($admin)->postJson("/api/admin/bots/{$botId}/token")->assertOk()->assertJsonStructure(['token']);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_fcm_push_with_service_account(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $sa = json_encode(['type' => 'service_account', 'project_id' => 'acidtalk-test', 'private_key_id' => 'k1', 'private_key' => $pem, 'client_email' => 'push@acidtalk-test.iam.gserviceaccount.com', 'token_uri' => 'https://oauth2.googleapis.com/token']);
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->putJson('/api/admin/settings', ['settings' => ['fcm_service_account' => $sa]])->assertOk()
            ->assertJsonPath('settings.fcm_project_id', 'acidtalk-test');

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::sequence()->push(['name' => 'projects/acidtalk-test/messages/1'])->push(['error' => ['status' => 'NOT_FOUND', 'message' => 'Requested entity was not found.', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404),
        ]);
        $b = User::factory()->create();
        $this->actingAs($b)->postJson('/api/push/devices', ['token' => 'tok-good', 'platform' => 'android'])->assertOk()->assertJson(['push_enabled' => true]);
        $this->actingAs($b)->postJson('/api/push/devices', ['token' => 'tok-old', 'platform' => 'ios'])->assertOk();

        $dm = Channel::create(['organization_id' => $admin->organization_id, 'name' => 'dm', 'type' => 'dm', 'created_by' => $admin->id]);
        $dm->members()->attach([$admin->id, $b->id]);
        $this->actingAs($admin)->postJson("/api/channels/{$dm->id}/messages", ['body' => 'push di prova'])->assertCreated();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'fcm.googleapis.com/v1/projects/acidtalk-test/messages:send')
            && $r->header('Authorization')[0] === 'Bearer ya29.test' && $r['message']['notification']['body'] === 'push di prova'
            && $r['message']['data']['channel_id'] === (string) $dm->id);
        $this->assertSame(1, DeviceToken::count(), 'il token non più registrato viene eliminato');
    }

    public function test_admin_system_status(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->getJson('/api/admin/system')->assertOk()
            ->assertJsonStructure(['version', 'cron' => ['last_run', 'ok'], 'queue' => ['worker', 'jobs'], 'tasks' => ['pending'], 'push' => ['configured']]);
        $this->actingAs($admin)->postJson('/api/admin/system/run')->assertOk()->assertJson(['ok' => true]);
    }

    public function test_notifications_can_be_marked_read_and_deleted(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        foreach ([1, 2, 3] as $i) {
            $user->notifications()->create([
                'id' => (string) \Illuminate\Support\Str::uuid(), 'type' => 'App\\Notifications\\NewMessageNotification',
                'data' => ['reason' => 'dm', 'from' => 'X', 'excerpt' => "m$i", 'channel_id' => 1, 'message_id' => $i],
            ]);
        }
        $other->notifications()->create(['id' => (string) \Illuminate\Support\Str::uuid(), 'type' => 'x', 'data' => ['reason' => 'dm']]);
        $first = $user->notifications()->first();

        $this->actingAs($user)->postJson('/api/notifications/read-all')->assertOk();
        $this->assertSame(0, $user->unreadNotifications()->count());

        $this->actingAs($user)->deleteJson("/api/notifications/{$first->id}")->assertOk();
        $this->assertSame(2, $user->notifications()->count());

        $this->actingAs($user)->deleteJson('/api/notifications')->assertOk()->assertJsonPath('deleted', 2);
        $this->assertSame(0, $user->notifications()->count());
        $this->assertSame(1, $other->notifications()->count(), 'le notifiche degli altri utenti restano');
    }

    public function test_client_config_alias(): void
    {
        $user = User::factory()->create();
        $a = $this->actingAs($user)->getJson('/api/client-config')->assertOk()->assertJsonStructure(['org' => ['name', 'push_enabled', 'read_receipts'], 'realtime', 'version'])->json();
        $this->assertSame($a, $this->actingAs($user)->getJson('/api/config')->assertOk()->json());
        $this->assertSame(\App\Support\ClientConfig::VERSION, $a['version']);
    }

    public function test_chat_lists_close_on_plain_text(): void
    {
        $r = app(MessageRenderer::class);
        $html = $r->render("Da fare\n- uno\n- due\nFine");
        $this->assertStringContainsString('<li>due</li>', $html);
        $this->assertStringContainsString('<p>Fine</p>', $html);
        $this->assertStringContainsString('<ol start="2">', $r->render("Lista:\n2. due\n3. tre"));
        $this->assertStringContainsString("<li>tre\ncontinua</li>", $r->render("1. uno\n2. tre\n  continua"));
        $this->assertStringContainsString("- a\nb", $r->render("```\n- a\nb\n```"));
        $this->assertStringContainsString('<li>punto</li>', $r->render("• punto\n• altro"));
    }

    public function test_channel_references_become_links(): void
    {
        $user = User::factory()->create();
        $c = Channel::create(['organization_id' => Organization::current()->id, 'name' => 'progetto alfa', 'type' => 'public', 'created_by' => $user->id]);
        $this->assertSame('progetto-alfa', $c->slug);
        $r = app(MessageRenderer::class);
        $html = $r->render("Vedi #progetto-alfa, non #inesistente né #128 e `#progetto-alfa` in codice; l'apostrofo resta");
        $this->assertStringContainsString('<a class="chan-ref" href="/app/c/'.$c->id.'" data-channel-id="'.$c->id.'">#progetto-alfa</a>,', $html);
        $this->assertStringContainsString('#inesistente', $html);
        $this->assertStringNotContainsString('chan-ref" href="/app/c/'.$c->id.'" data-channel-id="'.$c->id.'">#progetto-alfa</a></code>', $html);
        $this->assertSame(1, substr_count($html, 'chan-ref'));
        $this->assertStringContainsString('#128', $html);
        // un canale creato dopo viene riconosciuto subito
        $n = Channel::create(['organization_id' => Organization::current()->id, 'name' => 'nuovo', 'type' => 'private', 'created_by' => $user->id]);
        $this->assertStringContainsString('/app/c/'.$n->id, $r->render('vai in #nuovo'));
    }
}
