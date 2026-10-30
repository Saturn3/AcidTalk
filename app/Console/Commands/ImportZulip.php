<?php

namespace App\Console\Commands;

use App\Services\Import\ZulipImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class ImportZulip extends Command
{
    protected $signature = 'acidtalk:import-zulip
        {--url= : Indirizzo del server Zulip, es. https://chat.miaazienda.it (oppure ZULIP_URL nel .env)}
        {--email= : Email dell\'account Zulip (meglio un amministratore) (oppure ZULIP_EMAIL)}
        {--key= : API key dell\'account (Impostazioni personali → Account e privacy → API key) (oppure ZULIP_API_KEY)}
        {--zuliprc= : In alternativa: percorso del file zuliprc scaricato da Zulip}
        {--streams= : Solo questi stream, separati da virgola (default: tutti)}
        {--topics=flat : flat = tutti i messaggi nel canale, con il nome del topic quando cambia (default); none = senza nome del topic; threads = ogni topic diventa un thread}
        {--dms : Importa anche i messaggi diretti visibili all\'account usato}
        {--no-uploads : Non scaricare gli allegati}
        {--since= : Importa solo i messaggi da questa data (YYYY-MM-DD)}
        {--include-archived : Includi anche gli stream archiviati}
        {--default-password= : Password iniziale per gli utenti creati (altrimenti nessuna: usa acidtalk:password)}
        {--dry-run : Simula l\'importazione e annulla tutte le modifiche}';

    protected $description = 'Importa utenti, canali, topic, messaggi, reazioni e allegati da un server Zulip';

    public function handle(): int
    {
        [$url, $email, $key] = $this->credentials();
        if (! $url || ! $email || ! $key) {
            $this->error('Servono URL, email e API key di Zulip.');

            return self::FAILURE;
        }
        if (! in_array($this->option('topics'), ['flat', 'none', 'threads'], true)) {
            $this->error('--topics deve essere "flat", "none" oppure "threads".');

            return self::FAILURE;
        }

        $importer = new ZulipImporter($url, $email, $key, [
            'streams' => array_filter(array_map('trim', explode(',', (string) $this->option('streams')))),
            'topics' => $this->option('topics'),
            'dms' => (bool) $this->option('dms'),
            'uploads' => ! $this->option('no-uploads') && ! $this->option('dry-run'),
            'since' => $this->option('since') ? Carbon::parse($this->option('since'))->startOfDay() : null,
            'include_archived' => (bool) $this->option('include-archived'),
            'default_password' => $this->option('default-password'),
        ]);
        $importer->onLog(fn ($level, $msg) => $level === 'warn' ? $this->warn($msg) : $this->line($msg));

        $dry = (bool) $this->option('dry-run');
        if ($dry) {
            DB::beginTransaction();
            $this->warn('Modalità simulazione: nessuna modifica verrà salvata.');
        }
        $started = microtime(true);
        try {
            $stats = $importer->run();
        } catch (\Throwable $e) {
            if ($dry) {
                DB::rollBack();
            }
            $this->error('Importazione interrotta: '.$e->getMessage());
            $this->line('Puoi rilanciare il comando: i dati già importati non verranno duplicati.');

            return self::FAILURE;
        }
        if ($dry) {
            DB::rollBack();
        }

        $this->newLine();
        $this->table(['Elemento', 'Quantità'], [
            ['Utenti creati', $stats['users']],
            ['Canali creati', $stats['channels']],
            ['Conversazioni dirette create', $stats['dm_channels']],
            ['Messaggi importati', $stats['messages']],
            ['Messaggi già presenti / saltati', $stats['skipped']],
            ['Allegati scaricati', $stats['attachments']],
            ['Reazioni', $stats['reactions']],
            ['Errori', $stats['errors']],
        ]);
        $this->info(($dry ? 'Simulazione completata' : 'Importazione completata').' in '.round(microtime(true) - $started).' s.');
        if (! $dry && $stats['users'] && ! $this->option('default-password')) {
            $this->line('Gli utenti importati non hanno password: impostala con  php artisan acidtalk:password email@dominio');
        }

        return self::SUCCESS;
    }

    /** @return array{0:?string,1:?string,2:?string} */
    protected function credentials(): array
    {
        $url = $this->option('url') ?: config('services.zulip.url');
        $email = $this->option('email') ?: config('services.zulip.email');
        $key = $this->option('key') ?: config('services.zulip.key');

        if ($rc = $this->option('zuliprc')) {
            if (! is_readable($rc)) {
                $this->error("File {$rc} non leggibile.");

                return [null, null, null];
            }
            $ini = parse_ini_file($rc, true, INI_SCANNER_RAW) ?: [];
            $api = $ini['api'] ?? [];
            $url = $url ?: ($api['site'] ?? null);
            $email = $email ?: ($api['email'] ?? null);
            $key = $key ?: ($api['key'] ?? null);
        }
        if ($this->input->isInteractive()) {
            $url = $url ?: text('URL del server Zulip', placeholder: 'https://chat.miaazienda.it', required: true);
            $email = $email ?: text('Email account Zulip', required: true);
            $key = $key ?: password('API key Zulip', required: true);
        }
        if ($url && ! preg_match('~^https?://~', $url)) {
            $url = 'https://'.$url;
        }

        return [$url, $email, $key];
    }
}
