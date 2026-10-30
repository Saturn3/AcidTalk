<?php

use App\Models\Attachment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * I vocali registrati dal browser (.webm solo audio) venivano salvati come "video/webm" → tipo "video"/"file",
 * quindi mostrati come allegato da scaricare. Qui li riclassifichiamo come audio.
 */
return new class extends Migration
{
    public function up(): void
    {
        $voiceMessages = DB::table('messages')->where('kind', 'voice')->pluck('id')->all();

        DB::table('attachments')->whereIn('kind', ['video', 'file'])->orderBy('id')->chunkById(500, function ($rows) use ($voiceMessages) {
            foreach ($rows as $a) {
                $hint = in_array($a->message_id, $voiceMessages, false) ? 'voice' : null;
                if (Attachment::kindFromMime($a->mime, $a->name, $hint) === 'audio') {
                    DB::table('attachments')->where('id', $a->id)->update([
                        'kind' => 'audio',
                        'mime' => Attachment::audioMime((string) $a->mime, $a->name),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        // nessun rollback: la riclassificazione è corretta anche con il codice precedente
    }
};
