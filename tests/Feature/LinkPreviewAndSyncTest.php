<?php

namespace Tests\Feature;

use App\Jobs\FetchLinkPreview;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Services\LinkPreviewFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LinkPreviewAndSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        LinkPreviewFetcher::$resolveUsing = fn (string $host) => match ($host) {
            'intranet.acme.test' => ['10.0.0.5'],
            default => ['93.184.215.14'],
        };
    }

    protected function tearDown(): void
    {
        LinkPreviewFetcher::$resolveUsing = null;
        parent::tearDown();
    }

    private function setupChannel(): array
    {
        $user = User::factory()->create();
        $c = Channel::create(['organization_id' => $user->organization_id, 'name' => 'generale', 'type' => 'public', 'created_by' => $user->id]);
        $c->members()->attach($user->id, ['role' => 'owner']);

        return [$user, $c];
    }

    public function test_open_graph_preview_is_built_and_synced_via_changed_since(): void
    {
        Http::fake([
            'blog.example.com/*' => Http::response('<html><head><meta charset="utf-8"><title>Fallback</title>
                <meta content="Il titolo &amp; più" property="og:title"><meta name="description" content="Una descrizione">
                <meta property="og:image" content="/img/cover.jpg"><meta name="twitter:card" content="summary_large_image">
                <meta property="og:site_name" content="Blog Example"></head><body></body></html>', 200, ['Content-Type' => 'text/html; charset=utf-8']),
        ]);
        Bus::fake([FetchLinkPreview::class]);
        [$user, $c] = $this->setupChannel();
        $res = $this->actingAs($user)->getJson("/api/channels/{$c->id}/messages")->assertOk();
        $since = $res->json('meta.server_time');
        $this->assertNotEmpty($since);

        $this->travel(5)->seconds();
        $id = $this->actingAs($user)->postJson("/api/channels/{$c->id}/messages", ['body' => 'Guarda https://blog.example.com/post/1.'])->assertCreated()->json('data.id');
        Bus::assertDispatched(FetchLinkPreview::class);
        (new FetchLinkPreview(Message::find($id)))->handle(app(\App\Services\MessageTasks::class));

        $sync = $this->actingAs($user)->getJson("/api/channels/{$c->id}/messages?changed_since=".urlencode($since))->assertOk();
        $m = collect($sync->json('data'))->firstWhere('id', $id);
        $this->assertNotNull($m);
        $p = $m['link_previews'][0];
        $this->assertSame('https://blog.example.com/post/1', $p['url']); // punto finale rimosso
        $this->assertSame('Il titolo & più', $p['title']);
        $this->assertSame('Una descrizione', $p['description']);
        $this->assertSame('https://blog.example.com/img/cover.jpg', $p['image']);
        $this->assertSame('Blog Example', $p['site_name']);
        $this->assertSame('large', $p['provider']);

        // rimozione dell'anteprima da parte dell'autore
        $this->actingAs($user)->deleteJson("/api/messages/{$id}/previews/{$p['id']}")->assertOk()->assertJsonCount(0, 'data.link_previews');

        // eliminazioni restituite in "deleted"
        $this->travel(5)->seconds();
        $since2 = now()->subSecond()->toIso8601String();
        $this->actingAs($user)->deleteJson("/api/messages/{$id}")->assertOk();
        $this->actingAs($user)->getJson("/api/channels/{$c->id}/messages?changed_since=".urlencode($since2))->assertOk()->assertJsonPath('deleted', [$id]);
    }

    public function test_youtube_uses_oembed_and_private_hosts_are_blocked(): void
    {
        Http::fake([
            'www.youtube.com/oembed*' => Http::response(['title' => 'Demo AcidTalk', 'author_name' => 'AcidSoft']),
            '*' => Http::response('nope', 500),
        ]);
        $f = new LinkPreviewFetcher;
        $yt = $f->fetch('https://youtu.be/dQw4w9WgXcQ');
        $this->assertSame('video', $yt['kind']);
        $this->assertSame('youtube', $yt['provider']);
        $this->assertSame('Demo AcidTalk', $yt['title']);
        $this->assertStringContainsString('dQw4w9WgXcQ', $yt['image']);

        $this->assertNull($f->fetch('http://127.0.0.1/admin'));
        $this->assertNull($f->fetch('http://intranet.acme.test/wiki'));
        $this->assertNull($f->fetch('http://localhost:8080/'));
        $this->assertNull($f->fetch('file:///etc/passwd'));
        $this->assertSame('video_call', $f->fetch('https://meet.google.com/abc-defg-hij')['kind']);
    }

    public function test_sidebar_settings_and_client_config(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->putJson('/api/admin/settings', ['settings' => ['sidebar_dm_days' => 30, 'sidebar_channel_days' => 120]])->assertOk();
        $this->actingAs($admin)->getJson('/api/config')->assertOk()
            ->assertJsonPath('org.sidebar_dm_days', 30)->assertJsonPath('org.sidebar_channel_days', 120)->assertJsonPath('realtime', null);
        $this->actingAs($admin)->putJson('/api/admin/settings', ['settings' => ['sidebar_dm_days' => 0]])->assertUnprocessable();
    }
}
