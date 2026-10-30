<?php

namespace App\Console\Commands;

use App\Models\Message;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Per importazioni Zulip già eseguite con --topics=threads: riporta tutte le risposte
 * nei thread come normali messaggi del canale, in ordine cronologico.
 */
class ZulipFlattenThreads extends Command
{
    protected $signature = 'acidtalk:zulip-flatten
        {--channel= : Solo questo canale (slug o id)}
        {--dry-run : Mostra quanti messaggi verrebbero spostati senza modificare nulla}';

    protected $description = 'Converte i thread creati dall\'importazione Zulip in messaggi del canale';

    public function handle(): int
    {
        $replies = Message::withTrashed()->whereNotNull('parent_id')->where('meta->source', 'zulip');
        if ($ref = $this->option('channel')) {
            $channel = \App\Models\Channel::where('slug', $ref)->orWhere('id', is_numeric($ref) ? (int) $ref : 0)->first();
            if (! $channel) {
                $this->error("Canale {$ref} non trovato.");

                return self::FAILURE;
            }
            $replies->where('channel_id', $channel->id);
        }
        $count = (clone $replies)->count();
        $roots = (clone $replies)->distinct()->pluck('parent_id');
        $this->line("Risposte importate da Zulip da riportare nel canale: <info>{$count}</info> (in {$roots->count()} thread)");
        if ($this->option('dry-run') || ! $count) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($replies, $roots) {
            $replies->update(['parent_id' => null]);
            Message::withTrashed()->whereIn('id', $roots)->update(['reply_count' => 0, 'last_reply_at' => null]);
        });
        // i thread non esistono più: le nuove importazioni non devono ricollegarsi ai vecchi messaggi radice
        DB::table('import_mappings')->where('type', 'topic')->where('source', 'like', 'zulip%')->delete();

        $this->info('Fatto: i messaggi sono ora nel canale, ordinati come su Zulip.');

        return self::SUCCESS;
    }
}
