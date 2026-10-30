<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Invite;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChannelsAndAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_domain_signup_allowed_and_foreign_rejected(): void
    {
        $org = Organization::current();
        $org->update(['domain' => 'acidsoft.it', 'allow_domain_signup' => true]);
        $this->postJson('/api/auth/register', ['name' => 'Anna', 'email' => 'anna@acidsoft.it', 'password' => 'password123', 'device_name' => 'test'])
            ->assertCreated()->assertJsonStructure(['token', 'user']);
        $this->postJson('/api/auth/register', ['name' => 'Bob', 'email' => 'bob@gmail.com', 'password' => 'password123'])
            ->assertUnprocessable();
    }

    public function test_guest_invite_flow(): void
    {
        $admin = User::factory()->admin()->create();
        $private = Channel::create(['organization_id' => $admin->organization_id, 'name' => 'progetto-x', 'type' => 'private', 'created_by' => $admin->id]);
        $invite = $this->actingAs($admin)->postJson('/api/invites', ['email' => 'guest@example.com', 'channel_ids' => [$private->id]])->assertCreated();
        $token = Invite::first()->token;
        $this->getJson("/api/invites/{$token}")->assertOk()->assertJsonPath('valid', true);
        $this->postJson('/api/auth/register', ['name' => 'Guest', 'email' => 'guest@example.com', 'password' => 'password123', 'invite_token' => $token, 'device_name' => 'x'])->assertCreated();
        $guest = User::where('email', 'guest@example.com')->first();
        $this->assertSame('guest', $guest->role);
        $this->actingAs($guest)->getJson('/api/channels')->assertOk()->assertJsonFragment(['name' => 'progetto-x']);
    }

    public function test_channels_crud_and_direct_messages(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($user)->postJson('/api/channels', ['name' => 'Design Team', 'type' => 'public', 'topic' => 'UI'])
            ->assertCreated()->assertJsonPath('data.name', 'design team')->assertJsonPath('data.is_member', true);
        $this->actingAs($user)->postJson('/api/channels/direct', ['user_id' => $other->id])->assertSuccessful()->assertJsonPath('data.type', 'dm');
        $this->actingAs($user)->postJson('/api/channels/ai')->assertSuccessful()->assertJsonPath('data.type', 'ai_dm');
        $this->actingAs($user)->getJson('/api/channels')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_admin_settings_endpoint(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();
        $this->actingAs($member)->getJson('/api/admin/settings')->assertForbidden();
        $this->actingAs($admin)->getJson('/api/admin/settings')->assertOk()->assertJsonStructure(['name', 'domain', 'settings', 'stats']);
        $this->actingAs($admin)->putJson('/api/admin/settings', ['settings' => ['ai_provider' => 'anthropic', 'anthropic_api_key' => 'sk-ant-1234']])
            ->assertOk()->assertJsonPath('settings.ai_provider', 'anthropic')->assertJsonPath('settings.anthropic_api_key', '••••1234');
        $this->assertSame('sk-ant-1234', Organization::current()->setting('anthropic_api_key'));
    }

    public function test_mcp_tools(): void
    {
        $user = User::factory()->create();
        $c = Channel::create(['organization_id' => $user->organization_id, 'name' => 'generale', 'type' => 'public', 'created_by' => $user->id]);
        $c->members()->attach($user->id);
        $c->messages()->create(['user_id' => $user->id, 'body' => 'Nota importante sul deploy']);
        $token = $user->createToken('mcp')->plainTextToken;
        $rpc = fn ($method, $params = []) => $this->withToken($token)->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);

        $rpc('initialize')->assertOk()->assertJsonPath('result.serverInfo.name', 'acidtalk');
        $rpc('tools/list')->assertOk()->assertJsonCount(6, 'result.tools');
        $res = $rpc('tools/call', ['name' => 'search_messages', 'arguments' => ['query' => 'deploy']])->assertOk();
        $this->assertStringContainsString('Nota importante', $res->json('result.content.0.text'));
        $rpc('tools/call', ['name' => 'post_message', 'arguments' => ['channel' => 'generale', 'body' => 'Da MCP']])->assertOk();
        $this->assertDatabaseHas('messages', ['body' => 'Da MCP']);
    }
}
