<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ChannelArchiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_archive_makes_channel_read_only_and_restorable(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $c = Channel::create(['organization_id' => $owner->organization_id, 'name' => 'progetto-alfa', 'type' => 'public', 'created_by' => $owner->id]);
        $c->members()->attach([$owner->id => ['role' => 'owner'], $other->id => ['role' => 'member']]);
        $msg = $this->actingAs($other)->postJson("/api/channels/{$c->id}/messages", ['body' => 'messaggio storico'])->assertCreated()->json('data.id');

        // solo chi gestisce il canale può archiviarlo
        $this->actingAs($other)->postJson("/api/channels/{$c->id}/archive")->assertForbidden();
        $this->actingAs($owner)->postJson("/api/channels/{$c->id}/archive")->assertOk()
            ->assertJsonPath('data.is_archived', true);

        // fuori dalla lista, presente tra gli archiviati
        $this->actingAs($other)->getJson('/api/channels')->assertOk()->assertJsonMissing(['slug' => 'progetto-alfa']);
        $this->actingAs($other)->getJson('/api/channels?archived=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'progetto-alfa');

        // leggibile e ricercabile
        $this->actingAs($other)->getJson("/api/channels/{$c->id}")->assertOk();
        $this->actingAs($other)->getJson("/api/channels/{$c->id}/messages")->assertOk()->assertSee('messaggio storico')->assertSee('ha archiviato il canale');
        $this->actingAs($other)->getJson('/api/search?q=storico')->assertOk()->assertSee('messaggio storico');

        // sola lettura
        $this->actingAs($other)->postJson("/api/channels/{$c->id}/messages", ['body' => 'nuovo'])->assertStatus(423)->assertSee('archiviato');
        $this->actingAs($other)->putJson("/api/messages/{$msg}", ['body' => 'modificato'])->assertStatus(423);
        $this->actingAs($other)->deleteJson("/api/messages/{$msg}")->assertStatus(423);
        $this->actingAs($other)->postJson("/api/messages/{$msg}/react", ['emoji' => '👍'])->assertStatus(423);
        $this->actingAs($owner)->putJson("/api/channels/{$c->id}", ['topic' => 'x'])->assertStatus(423);
        $this->actingAs($owner)->postJson("/api/channels/{$c->id}/archive")->assertUnprocessable();

        // ripristino
        $this->actingAs($owner)->postJson("/api/channels/{$c->id}/unarchive")->assertOk()->assertJsonPath('data.is_archived', false);
        $this->actingAs($other)->postJson("/api/channels/{$c->id}/messages", ['body' => 'di nuovo attivo'])->assertCreated();
        $this->assertTrue(Message::where('body', 'like', '%ha ripristinato il canale%')->exists());
        $this->actingAs($other)->getJson('/api/channels')->assertSee('progetto-alfa');
    }

    public function test_generale_and_dm_cannot_be_archived_and_delete_is_archive(): void
    {
        $admin = User::factory()->admin()->create();
        $gen = Channel::create(['organization_id' => $admin->organization_id, 'name' => 'generale', 'type' => 'public', 'created_by' => $admin->id]);
        $this->actingAs($admin)->postJson("/api/channels/{$gen->id}/archive")->assertUnprocessable();

        $c = Channel::create(['organization_id' => $admin->organization_id, 'name' => 'vecchio', 'type' => 'private', 'created_by' => $admin->id]);
        $c->members()->attach($admin->id);
        $this->actingAs($admin)->deleteJson("/api/channels/{$c->id}")->assertOk();
        $this->assertNotNull($c->fresh()->archived_at);
        $this->assertDatabaseHas('channels', ['id' => $c->id]);

        // MCP: leggibile, ma non si può scrivere
        $token = $admin->createToken('mcp')->plainTextToken;
        $call = fn ($name, $args) => $this->withToken($token)->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $args]]);
        $call('list_channels', ['include_archived' => true])->assertOk()->assertSee('archiviato');
        $call('read_channel', ['channel' => 'vecchio'])->assertOk()->assertSee('ha archiviato il canale');
        $call('post_message', ['channel' => 'vecchio', 'body' => 'ciao'])->assertSee('sola lettura');
    }
}
