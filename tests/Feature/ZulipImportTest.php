<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ZulipImportTest extends TestCase
{
    use RefreshDatabase;

    protected function fakeZulip(): void
    {
        $users = [
            ['user_id' => 1, 'email' => 'user1@zulip.local', 'delivery_email' => 'alessandro@acidsoft.net', 'full_name' => 'Alessandro L', 'is_bot' => false, 'is_active' => true, 'role' => 100],
            ['user_id' => 2, 'email' => 'user2@zulip.local', 'delivery_email' => 'giulia@acidsoft.net', 'full_name' => 'Giulia Rossi', 'is_bot' => false, 'is_active' => true, 'role' => 400],
            ['user_id' => 3, 'email' => 'ospite@cliente.com', 'full_name' => 'Ospite Cliente', 'is_bot' => false, 'is_active' => true, 'role' => 600],
            ['user_id' => 4, 'email' => 'notifiche-bot@zulip.local', 'full_name' => 'Notifiche', 'is_bot' => true, 'is_active' => true, 'role' => 400],
        ];
        $streams = [
            ['stream_id' => 10, 'name' => 'Sviluppo', 'description' => 'Team di sviluppo', 'invite_only' => false, 'date_created' => 1700000000],
            ['stream_id' => 11, 'name' => 'Direzione', 'description' => 'Riservato', 'invite_only' => true],
        ];
        $base = ['type' => 'stream', 'reactions' => []];
        $dev = [
            ['id' => 101, 'sender_id' => 1, 'sender_email' => 'user1@zulip.local', 'subject' => 'Rilascio 2.0', 'timestamp' => 1700000100, 'content' => 'Ciao @**Giulia Rossi|2**, ecco le note :rocket:'] + $base,
            ['id' => 102, 'sender_id' => 2, 'sender_email' => 'user2@zulip.local', 'subject' => 'Rilascio 2.0', 'timestamp' => 1700000200, 'content' => "Allego il documento\n[specifiche.pdf](/user_uploads/2/ab/xyz/specifiche.pdf)",
                'reactions' => [['emoji_name' => 'thumbs_up', 'emoji_code' => '1f44d', 'reaction_type' => 'unicode_emoji', 'user_id' => 1]]] + $base,
            ['id' => 103, 'sender_id' => 1, 'sender_email' => 'user1@zulip.local', 'subject' => 'Bug login', 'timestamp' => 1700000300, 'content' => "```quote\nnon funziona\n```\nvedi #**Direzione>budget**", 'last_edit_timestamp' => 1700000400] + $base,
            ['id' => 104, 'sender_id' => 4, 'sender_email' => 'notifiche-bot@zulip.local', 'subject' => 'Rilascio 2.0', 'timestamp' => 1700000500, 'content' => 'Build completata'] + $base,
        ];
        $dir = [['id' => 201, 'sender_id' => 1, 'sender_email' => 'user1@zulip.local', 'subject' => 'budget', 'timestamp' => 1700000600, 'content' => 'Budget approvato'] + $base];
        $dms = [
            ['id' => 301, 'type' => 'private', 'sender_id' => 2, 'sender_email' => 'user2@zulip.local', 'subject' => '', 'timestamp' => 1700000700, 'content' => 'ciao, ci sentiamo?', 'reactions' => [],
                'display_recipient' => [['id' => 1, 'email' => 'user1@zulip.local', 'full_name' => 'Alessandro L'], ['id' => 2, 'email' => 'user2@zulip.local', 'full_name' => 'Giulia Rossi']]],
            ['id' => 302, 'type' => 'private', 'sender_id' => 1, 'sender_email' => 'user1@zulip.local', 'subject' => '', 'timestamp' => 1700000800, 'content' => 'riunione con il cliente', 'reactions' => [],
                'display_recipient' => [['id' => 1, 'email' => 'u1'], ['id' => 2, 'email' => 'u2'], ['id' => 3, 'email' => 'u3']]],
        ];

        Http::fake(function (Request $r) use ($users, $streams, $dev, $dir, $dms) {
            $url = $r->url();
            $path = parse_url($url, PHP_URL_PATH);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            if (! $r->hasHeader('Authorization') && ! str_contains($path, '/temporary/')) {
                return Http::response(['result' => 'error', 'msg' => 'auth'], 401);
            }

            return match (true) {
                $path === '/api/v1/users/me' => Http::response(['result' => 'success', 'full_name' => 'Alessandro L', 'role' => 100]),
                $path === '/api/v1/users' => Http::response(['result' => 'success', 'members' => $users]),
                $path === '/api/v1/streams' => Http::response(['result' => 'success', 'streams' => $streams]),
                $path === '/api/v1/streams/10/members' => Http::response(['result' => 'success', 'subscribers' => [1, 2, 4]]),
                $path === '/api/v1/streams/11/members' => Http::response(['result' => 'success', 'subscribers' => [1]]),
                $path === '/api/v1/messages' => (function () use ($q, $dev, $dir, $dms) {
                    $narrow = json_decode($q['narrow'], true)[0];
                    $all = match ($narrow['operand']) { 'Sviluppo' => $dev, 'Direzione' => $dir, 'private' => $dms, default => [] };
                    // paginazione a 2 messaggi per pagina
                    if ($q['anchor'] !== 'oldest') {
                        $all = array_values(array_filter($all, fn ($m) => $m['id'] > (int) $q['anchor']));
                    }
                    $page = array_slice($all, 0, 2);

                    return Http::response(['result' => 'success', 'messages' => $page, 'found_newest' => count($all) <= 2]);
                })(),
                str_starts_with($path, '/api/v1/user_uploads/') => Http::response(['result' => 'success', 'url' => '/user_uploads/temporary/abc/specifiche.pdf']),
                str_starts_with($path, '/user_uploads/temporary/') => Http::response("%PDF-1.4\n%fake pdf\n", 200, ['Content-Type' => 'application/pdf']),
                default => Http::response(['result' => 'error', 'msg' => 'not found '.$path], 404),
            };
        });
    }

    public function test_full_zulip_import_is_mapped_and_idempotent(): void
    {
        Storage::fake('public');
        $this->fakeZulip();

        $this->artisan('acidtalk:import-zulip', [
            '--url' => 'https://chat.example.com', '--email' => 'admin@acidsoft.net', '--key' => 'secret', '--dms' => true, '--topics' => 'threads',
        ])->assertSuccessful();

        // utenti e ruoli
        $this->assertSame('admin', User::where('email', 'alessandro@acidsoft.net')->value('role'));
        $this->assertSame('guest', User::where('email', 'ospite@cliente.com')->value('role'));
        $this->assertTrue(User::where('email', 'notifiche-bot@zulip.local')->exists());

        // canali
        $dev = Channel::where('slug', 'sviluppo')->firstOrFail();
        $this->assertSame('public', $dev->type);
        $this->assertSame('private', Channel::where('slug', 'direzione')->value('type'));
        $this->assertSame(3, $dev->members()->count());

        // topic -> thread
        $root = Message::where('channel_id', $dev->id)->whereNull('parent_id')->orderBy('id')->first();
        $this->assertStringStartsWith('**Rilascio 2.0**', $root->body);
        $this->assertStringContainsString('**@Giulia Rossi**', $root->body);
        $this->assertStringContainsString('🚀', $root->body);
        $this->assertSame(2, $root->fresh()->reply_count);
        $this->assertSame(2, Message::where('parent_id', $root->id)->count());
        $this->assertSame('2023-11-14 22:15:00', $root->created_at->utc()->format('Y-m-d H:i:s'));

        // allegato e reazione
        $att = Attachment::firstOrFail();
        $this->assertSame('pdf', $att->kind);
        $this->assertSame('specifiche.pdf', $att->name);
        Storage::disk('public')->assertExists($att->path);
        $this->assertStringNotContainsString('user_uploads', Message::find($att->message_id)->body);
        $this->assertSame('👍', Reaction::value('emoji'));

        // quote, link a stream, modifica
        $bug = Message::where('body', 'like', '%Bug login%')->firstOrFail();
        $this->assertStringContainsString('> non funziona', $bug->body);
        $this->assertStringContainsString('**#direzione › budget**', $bug->body);
        $this->assertNotNull($bug->edited_at);

        // messaggi diretti
        $this->assertSame(1, Channel::where('type', 'dm')->count());
        $group = Channel::where('slug', 'like', 'gruppo-%')->firstOrFail();
        $this->assertSame('private', $group->type);
        $this->assertSame(3, $group->members()->count());

        $count = Message::count();
        $this->assertSame(7, $count);

        // rilancio: nessun duplicato
        $this->artisan('acidtalk:import-zulip', [
            '--url' => 'https://chat.example.com', '--email' => 'admin@acidsoft.net', '--key' => 'secret', '--dms' => true, '--topics' => 'threads',
        ])->assertSuccessful();
        $this->assertSame($count, Message::count());
        $this->assertSame(1, Attachment::count());
        $this->assertSame(2, Channel::whereIn('slug', ['sviluppo', 'direzione'])->count());

        // le API mostrano i messaggi importati
        $user = User::where('email', 'giulia@acidsoft.net')->first();
        $this->actingAs($user)->getJson("/api/channels/{$dev->id}/messages")->assertOk()->assertSee('Rilascio 2.0');
    }

    public function test_default_import_keeps_all_messages_in_channel_and_flatten_command(): void
    {
        Storage::fake('public');
        $this->fakeZulip();
        $this->artisan('acidtalk:import-zulip', ['--url' => 'https://chat.example.com', '--email' => 'a@b.c', '--key' => 'k'])->assertSuccessful();

        $dev = Channel::where('slug', 'sviluppo')->firstOrFail();
        $this->assertSame(0, Message::whereNotNull('parent_id')->count(), 'nessuna risposta nei thread');
        $bodies = Message::where('channel_id', $dev->id)->orderBy('id')->pluck('body')->all();
        $this->assertCount(4, $bodies);
        $this->assertStringStartsWith('**[Rilascio 2.0]** Ciao', $bodies[0]);
        $this->assertSame('Allego il documento', $bodies[1], 'stesso topic: nessuna ripetizione del nome');
        $this->assertStringStartsWith('**[Bug login]**', $bodies[2]);
        $this->assertStringStartsWith('**[Rilascio 2.0]** Build', $bodies[3]);
        $this->assertSame(0, (int) Message::sum('reply_count'));

        // --topics=none: senza nome del topic
        $this->artisan('acidtalk:import-zulip', ['--url' => 'https://altro.example.com', '--email' => 'a@b.c', '--key' => 'k', '--topics' => 'none'])->assertSuccessful();
    }

    public function test_flatten_command_converts_previous_thread_import(): void
    {
        Storage::fake('public');
        $this->fakeZulip();
        $this->artisan('acidtalk:import-zulip', ['--url' => 'https://chat.example.com', '--email' => 'a@b.c', '--key' => 'k', '--topics' => 'threads'])->assertSuccessful();
        $this->assertSame(2, Message::whereNotNull('parent_id')->count());

        $this->artisan('acidtalk:zulip-flatten', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(2, Message::whereNotNull('parent_id')->count());

        $this->artisan('acidtalk:zulip-flatten')->assertSuccessful();
        $this->assertSame(0, Message::whereNotNull('parent_id')->count());
        $this->assertSame(0, (int) Message::sum('reply_count'));
        $dev = Channel::where('slug', 'sviluppo')->firstOrFail();
        $user = User::where('email', 'giulia@acidsoft.net')->first();
        $ids = collect($this->actingAs($user)->getJson("/api/channels/{$dev->id}/messages")->assertOk()->json('data'))->pluck('id');
        $this->assertCount(4, $ids);
    }

    public function test_dry_run_saves_nothing(): void
    {
        $this->fakeZulip();
        $before = Message::count();
        $this->artisan('acidtalk:import-zulip', [
            '--url' => 'https://chat.example.com', '--email' => 'a@b.c', '--key' => 'k', '--dry-run' => true,
        ])->assertSuccessful();
        $this->assertSame($before, Message::count());
        $this->assertFalse(Channel::where('slug', 'sviluppo')->exists());
    }
}
