<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MessagesTest extends TestCase
{
    use RefreshDatabase;

    private function channelWith(User $user): Channel
    {
        $c = Channel::create(['organization_id' => $user->organization_id, 'name' => 'generale', 'type' => 'public', 'created_by' => $user->id]);
        $c->members()->attach($user->id, ['role' => 'owner']);

        return $c;
    }

    public function test_user_can_post_edit_and_delete_own_message(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $channel = $this->channelWith($user);

        $res = $this->actingAs($user)->postJson("/api/channels/{$channel->id}/messages", ['body' => 'Ciao **mondo**'])
            ->assertCreated()->assertJsonPath('data.can_edit', true);
        $id = $res->json('data.id');
        $this->assertStringContainsString('<strong>mondo</strong>', $res->json('data.html'));

        $this->actingAs($user)->putJson("/api/messages/{$id}", ['body' => 'Modificato'])
            ->assertOk()->assertJsonPath('data.body', 'Modificato')->assertJsonPath('data.edited_at', fn ($v) => $v !== null);

        $this->actingAs($user)->deleteJson("/api/messages/{$id}")->assertOk();
        $this->assertSoftDeleted('messages', ['id' => $id]);
        $this->actingAs($user)->getJson("/api/channels/{$channel->id}/messages")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_other_user_cannot_edit_but_admin_can_delete(): void
    {
        Queue::fake();
        $author = User::factory()->create();
        $other = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $channel = $this->channelWith($author);
        $channel->members()->attach([$other->id, $admin->id]);
        $id = $this->actingAs($author)->postJson("/api/channels/{$channel->id}/messages", ['body' => 'Mio'])->json('data.id');

        $this->actingAs($other)->putJson("/api/messages/{$id}", ['body' => 'Hack'])->assertForbidden();
        $this->actingAs($other)->deleteJson("/api/messages/{$id}")->assertForbidden();
        $this->actingAs($admin)->putJson("/api/messages/{$id}", ['body' => 'Hack'])->assertForbidden();
        $this->actingAs($admin)->deleteJson("/api/messages/{$id}")->assertOk();
    }

    public function test_thread_replies_and_reactions(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $channel = $this->channelWith($user);
        $root = $this->actingAs($user)->postJson("/api/channels/{$channel->id}/messages", ['body' => 'Radice'])->json('data.id');
        $this->actingAs($user)->postJson("/api/channels/{$channel->id}/messages", ['body' => 'Risposta', 'parent_id' => $root])->assertCreated();
        $this->actingAs($user)->getJson("/api/messages/{$root}/thread")->assertOk()->assertJsonPath('root.reply_count', 1)->assertJsonCount(1, 'data');
        $this->actingAs($user)->postJson("/api/messages/{$root}/react", ['emoji' => '👍'])->assertOk()->assertJsonPath('data.reactions.0.count', 1);
        $this->actingAs($user)->postJson("/api/messages/{$root}/react", ['emoji' => '👍'])->assertOk()->assertJsonCount(0, 'data.reactions');
    }

    public function test_ai_mention_dispatches_job(): void
    {
        config(['queue.worker' => true]);
        Queue::fake();
        $user = User::factory()->create();
        $channel = $this->channelWith($user);
        $this->actingAs($user)->postJson("/api/channels/{$channel->id}/messages", ['body' => '@ai riassumi'])->assertCreated();
        Queue::assertPushed(\App\Jobs\GenerateAiReply::class);
    }
}
