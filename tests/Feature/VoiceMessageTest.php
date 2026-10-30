<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VoiceMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_browser_webm_voice_note_is_stored_as_playable_audio(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $c = Channel::create(['organization_id' => $user->organization_id, 'name' => 'generale', 'type' => 'public', 'created_by' => $user->id]);
        $c->members()->attach($user->id, ['role' => 'owner']);

        // file reale prodotto come da MediaRecorder: finfo lo vede come "video/webm"
        $file = new UploadedFile(base_path('tests/fixtures/vocale.webm'), 'vocale-2026-10-25-08-00-00.webm', 'audio/webm', null, true);
        $att = $this->actingAs($user)->post('/api/attachments', ['file' => $file, 'duration' => '1.5', 'voice' => '1'], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('kind', 'audio')->json();
        $this->assertSame('audio/webm', $att['mime']);
        $this->assertEquals(1.5, $att['meta']['duration']);

        $this->actingAs($user)->postJson("/api/channels/{$c->id}/messages", ['body' => '', 'kind' => 'voice', 'attachment_ids' => [$att['id']]])
            ->assertCreated()->assertJsonPath('data.attachments.0.kind', 'audio');
    }

    public function test_kind_detection_for_ambiguous_containers(): void
    {
        $this->assertSame('audio', Attachment::kindFromMime('video/webm', 'x.webm', 'voice'));
        $this->assertSame('audio', Attachment::kindFromMime('video/webm', 'x.webm', 'audio/webm'));
        $this->assertSame('audio', Attachment::kindFromMime('video/mp4', 'nota.m4a'));
        $this->assertSame('audio', Attachment::kindFromMime('application/ogg', 'nota.ogg'));
        $this->assertSame('video', Attachment::kindFromMime('video/webm', 'clip.webm', 'video/webm'));
        $this->assertSame('video', Attachment::kindFromMime('video/mp4', 'demo.mp4'));
        $this->assertSame('audio/mp4', Attachment::audioMime('video/mp4', 'nota.m4a'));
    }
}
