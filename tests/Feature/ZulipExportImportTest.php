<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ZulipExportImportTest extends TestCase
{
    use RefreshDatabase;

    protected string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/zulip-export-test-'.uniqid();
        $this->makeExport($this->dir.'/zulip-export-abc');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** Esportazione minima con la struttura di manage.py export. */
    protected function makeExport(string $d): void
    {
        File::ensureDirectoryExists($d.'/uploads/2/ab/cd');
        $put = fn ($f, $data) => file_put_contents("{$d}/{$f}", json_encode($data));
        $put('realm.json', [
            'zerver_realm' => [['id' => 2, 'string_id' => 'acidsoft', 'name' => 'AcidSoft']],
            'zerver_userprofile' => [
                ['id' => 10, 'email' => 'user10@zulip.acidsoft.net', 'delivery_email' => 'alessandro@acidsoft.net', 'full_name' => 'Alessandro', 'role' => 100, 'is_bot' => false, 'is_active' => true],
                ['id' => 11, 'email' => 'user11@zulip.acidsoft.net', 'delivery_email' => 'giulia@acidsoft.net', 'full_name' => 'Giulia', 'role' => 400, 'is_bot' => false, 'is_active' => true],
                ['id' => 12, 'email' => 'user12@zulip.acidsoft.net', 'delivery_email' => 'marco@acidsoft.net', 'full_name' => 'Marco', 'role' => 400, 'is_bot' => false, 'is_active' => false],
                ['id' => 13, 'email' => 'user13@zulip.acidsoft.net', 'delivery_email' => 'cliente@esterno.com', 'full_name' => 'Cliente', 'role' => 600, 'is_bot' => false, 'is_active' => true],
            ],
            'zerver_userprofile_crossrealm' => [['id' => 1, 'email' => 'notification-bot@zulip.com', 'full_name' => 'Notification Bot', 'is_bot' => true]],
            'zerver_stream' => [
                ['id' => 5, 'name' => 'Generale Zulip', 'description' => 'Tutti', 'invite_only' => false, 'date_created' => 1690000000.5, 'deactivated' => false, 'recipient' => 105],
                ['id' => 6, 'name' => 'Riservato HR', 'description' => 'HR', 'invite_only' => true, 'date_created' => 1690000000, 'deactivated' => false, 'recipient' => 106],
                ['id' => 7, 'name' => 'Vecchio', 'description' => '', 'invite_only' => false, 'deactivated' => true, 'recipient' => 107],
            ],
            'zerver_recipient' => [
                ['id' => 105, 'type' => 2, 'type_id' => 5], ['id' => 106, 'type' => 2, 'type_id' => 6], ['id' => 107, 'type' => 2, 'type_id' => 7],
                ['id' => 210, 'type' => 1, 'type_id' => 10], ['id' => 211, 'type' => 1, 'type_id' => 11],
                ['id' => 300, 'type' => 3, 'type_id' => 1],
            ],
            'zerver_subscription' => [
                ['user_profile' => 10, 'recipient' => 105, 'active' => true], ['user_profile' => 11, 'recipient' => 105, 'active' => true],
                ['user_profile' => 12, 'recipient' => 105, 'active' => false],
                ['user_profile' => 11, 'recipient' => 106, 'active' => true], ['user_profile' => 12, 'recipient' => 106, 'active' => true],
                ['user_profile' => 10, 'recipient' => 300, 'active' => true], ['user_profile' => 11, 'recipient' => 300, 'active' => true], ['user_profile' => 13, 'recipient' => 300, 'active' => true],
            ],
            'zerver_huddle' => [['id' => 1, 'huddle_hash' => 'x', 'recipient' => 300]],
            'zerver_reaction' => [['message' => 1001, 'user_profile' => 11, 'emoji_name' => 'heart', 'emoji_code' => '2764', 'reaction_type' => 'unicode_emoji']],
        ]);
        $m = fn ($id, $sender, $rcpt, $topic, $content, $ts, $edit = null) => ['id' => $id, 'sender' => $sender, 'recipient' => $rcpt, 'subject' => $topic, 'content' => $content, 'rendered_content' => '<p>…</p>', 'date_sent' => $ts + 0.25, 'last_edit_time' => $edit, 'realm' => 2];
        $put('messages-000001.json', ['zerver_message' => [
            $m(1001, 10, 105, 'Benvenuti', 'Ciao a tutti!', 1700000000),
            $m(1002, 11, 105, 'Benvenuti', "Grazie :tada:\n[logo.png](/user_uploads/2/ab/cd/logo.png)", 1700000100, 1700000200),
            $m(1003, 11, 106, 'stipendi', 'Riservato: messaggio HR (admin non iscritto)', 1700000300),
            $m(1004, 11, 210, '', 'DM privato tra Giulia e Alessandro', 1700000400),
        ], 'zerver_usermessage' => []]);
        $put('messages-000002.json', ['zerver_message' => [
            $m(1005, 10, 211, '', 'risposta di Alessandro a Giulia', 1700000500),
            $m(1006, 13, 300, '', 'messaggio di gruppo con il cliente', 1700000600),
            $m(1007, 10, 107, 'x', 'stream archiviato', 1700000700),
            $m(1008, 12, 106, 'stipendi', 'risposta nel topic HR', 1700000800),
        ], 'zerver_usermessage' => []]);
        $put('attachment.json', ['zerver_attachment' => [['id' => 1, 'file_name' => 'logo.png', 'path_id' => '2/ab/cd/logo.png', 'owner' => 11, 'messages' => [1002]]]]);
        // PNG 1x1
        file_put_contents($d.'/uploads/2/ab/cd/logo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
        $put('uploads/records.json', [['path' => '2/ab/cd/logo.png', 's3_path' => '2/ab/cd/logo.png', 'realm_id' => 2, 'user_profile_id' => 11, 'size' => 68]]);
    }

    public function test_full_export_import_including_private_streams_and_all_dms(): void
    {
        Storage::fake('public');
        $this->artisan('acidtalk:import-zulip-export', ['path' => $this->dir, '--topics' => 'threads'])->assertSuccessful();

        $this->assertSame(5, User::count());
        $this->assertSame('guest', User::where('email', 'cliente@esterno.com')->value('role'));

        $gen = Channel::where('slug', 'generale-zulip')->firstOrFail();
        $hr = Channel::where('slug', 'riservato-hr')->firstOrFail();
        $this->assertSame('private', $hr->type);
        $this->assertFalse(Channel::where('slug', 'vecchio')->exists(), 'stream archiviato escluso di default');
        $this->assertEqualsCanonicalizing(['alessandro@acidsoft.net', 'giulia@acidsoft.net'], $gen->members()->pluck('email')->all());

        // topic -> thread, reazione, allegato, modifica
        $root = Message::where('channel_id', $gen->id)->whereNull('parent_id')->firstOrFail();
        $this->assertSame("**Benvenuti**\n\nCiao a tutti!", $root->body);
        $this->assertSame(1, $root->reply_count);
        $reply = Message::where('parent_id', $root->id)->firstOrFail();
        $this->assertSame('Grazie 🎉', $reply->body);
        $this->assertNotNull($reply->edited_at);
        $this->assertSame('❤', Reaction::where('message_id', $root->id)->value('emoji'));
        $att = Attachment::where('message_id', $reply->id)->firstOrFail();
        $this->assertSame('image', $att->kind);
        Storage::disk('public')->assertExists($att->path);

        // stream privato: thread con risposta
        $hrRoot = Message::where('channel_id', $hr->id)->whereNull('parent_id')->firstOrFail();
        $this->assertSame(1, Message::where('parent_id', $hrRoot->id)->count());

        // tutti i DM: 1:1 nei due sensi nello stesso DM + gruppo
        $dm = Channel::where('type', 'dm')->firstOrFail();
        $this->assertSame(2, $dm->messages()->count());
        $group = Channel::where('slug', 'like', 'gruppo-%')->firstOrFail();
        $this->assertSame(3, $group->members()->count());
        $this->assertSame(7, Message::count());

        // rilancio idempotente, anche da .tar.gz
        $tar = $this->dir.'/export.tar.gz';
        exec('tar -czf '.escapeshellarg($tar).' -C '.escapeshellarg($this->dir).' zulip-export-abc');
        $this->artisan('acidtalk:import-zulip-export', ['path' => $tar, '--topics' => 'threads'])->assertSuccessful();
        $this->assertSame(7, Message::count());
        $this->assertSame(1, Attachment::count());
        $this->assertSame([], glob(storage_path('app/zulip-export-*')) ?: [], 'cartella temporanea rimossa');
    }

    public function test_options_streams_no_dms_and_archived(): void
    {
        $this->artisan('acidtalk:import-zulip-export', ['path' => $this->dir.'/zulip-export-abc', '--streams' => 'Vecchio', '--include-archived' => true, '--no-dms' => true, '--no-uploads' => true])->assertSuccessful();
        $old = Channel::where('slug', 'vecchio')->firstOrFail();
        $this->assertNotNull($old->archived_at, 'stream archiviato su Zulip -> canale archiviato');
        $this->assertSame(1, Channel::whereIn('type', ['public', 'private'])->whereNotIn('slug', ['generale', 'random'])->count());
        $this->assertSame(0, Channel::where('type', 'dm')->count());
        $this->assertSame(1, Message::count());
    }

    public function test_default_export_import_is_flat(): void
    {
        Storage::fake('public');
        $this->artisan('acidtalk:import-zulip-export', ['path' => $this->dir])->assertSuccessful();
        $this->assertSame(0, Message::whereNotNull('parent_id')->count());
        $gen = Channel::where('slug', 'generale-zulip')->firstOrFail();
        $this->assertSame(['**[Benvenuti]** Ciao a tutti!', 'Grazie 🎉'], Message::where('channel_id', $gen->id)->orderBy('id')->pluck('body')->all());
    }

    public function test_invalid_path_fails_cleanly(): void
    {
        $this->artisan('acidtalk:import-zulip-export', ['path' => '/percorso/inesistente'])->assertFailed();
    }
}
