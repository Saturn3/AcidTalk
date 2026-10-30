<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Ai\AiManager;
use Illuminate\Console\Command;

class AiTest extends Command
{
    protected $signature = 'acidtalk:ai-test {--provider= : ollama|openai|anthropic (default: quello configurato)} {--model=}';

    protected $description = 'Verifica la connessione al provider AI configurato e mostra l\'errore esatto';

    public function handle(AiManager $ai): int
    {
        $org = Organization::current();
        $driver = $ai->driver($org, array_filter(['ai_provider' => $this->option('provider'), 'ai_model' => $this->option('model')]));
        $this->line("Provider: <info>{$driver->name()}</info>  modello: <info>{$driver->model()}</info>");
        $this->line('Coda: '.(config('queue.worker') ? 'worker persistente (QUEUE_WORKER=true)' : 'risposte generate subito dopo la richiesta (QUEUE_WORKER=false)'));
        $t = microtime(true);
        try {
            $reply = $driver->chat([['role' => 'user', 'content' => 'Rispondi solo con: OK, AcidTalk è collegato.']]);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info('Risposta in '.round(microtime(true) - $t, 1).' s: '.$reply);
        $this->line("Modello usato: {$driver->model()}");

        return self::SUCCESS;
    }
}
