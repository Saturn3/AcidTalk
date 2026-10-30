<?php

namespace App\Console\Commands;

use App\Services\MessageTasks;
use Illuminate\Console\Command;

/** Recupera risposte AI, anteprime e webhook dei bot rimasti in sospeso (eseguito dal cron ogni minuto). */
class ProcessPending extends Command
{
    protected $signature = 'acidtalk:pending {--age=30 : secondi minimi dalla creazione del messaggio}';

    protected $description = 'Esegue le attività in sospeso dei messaggi (AI, anteprime link, bot)';

    public function handle(MessageTasks $tasks): int
    {
        $n = $tasks->sweep((int) $this->option('age'));
        $this->info("Attività eseguite: {$n}");

        return self::SUCCESS;
    }
}
