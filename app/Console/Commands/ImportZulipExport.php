<?php

namespace App\Console\Commands;

use App\Services\Import\ZulipExportImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ImportZulipExport extends Command
{
    protected $signature = 'acidtalk:import-zulip-export
        {path : Cartella estratta o file .tar.gz dell\'esportazione Zulip (manage.py export)}
        {--url= : URL del server Zulip originale: se l\'hai già importato via API, evita i duplicati}
        {--streams= : Solo questi stream, separati da virgola (default: tutti)}
        {--topics=flat : flat = tutti i messaggi nel canale, con il nome del topic quando cambia (default); none = senza nome del topic; threads = ogni topic diventa un thread}
        {--no-dms : Non importare i messaggi diretti}
        {--no-uploads : Non importare gli allegati}
        {--since= : Importa solo i messaggi da questa data (YYYY-MM-DD)}
        {--include-archived : Includi anche gli stream archiviati}
        {--default-password= : Password iniziale per gli utenti creati}
        {--dry-run : Simula l\'importazione e annulla tutte le modifiche}';

    protected $description = 'Importa l\'esportazione completa di un server Zulip (tutti gli stream, i DM e i file)';

    public function handle(): int
    {
        if (! in_array($this->option('topics'), ['flat', 'none', 'threads'], true)) {
            $this->error('--topics deve essere "flat", "none" oppure "threads".');

            return self::FAILURE;
        }
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');

        $dry = (bool) $this->option('dry-run');
        $started = microtime(true);
        if ($dry) {
            DB::beginTransaction();
            $this->warn('Modalità simulazione: nessuna modifica verrà salvata.');
        }
        try {
            $importer = new ZulipExportImporter($this->argument('path'), [
                'streams' => array_filter(array_map('trim', explode(',', (string) $this->option('streams')))),
                'topics' => $this->option('topics'),
                'dms' => ! $this->option('no-dms'),
                'uploads' => ! $this->option('no-uploads') && ! $dry,
                'since' => $this->option('since') ? Carbon::parse($this->option('since'))->startOfDay() : null,
                'include_archived' => (bool) $this->option('include-archived'),
                'default_password' => $this->option('default-password'),
            ], $this->option('url') ?: null);
            $importer->onLog(fn ($level, $msg) => $level === 'warn' ? $this->warn($msg) : $this->line($msg));
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
            ['Allegati importati', $stats['attachments']],
            ['Reazioni', $stats['reactions']],
            ['Errori', $stats['errors']],
        ]);
        $this->info(($dry ? 'Simulazione completata' : 'Importazione completata').' in '.round(microtime(true) - $started).' s.');
        if (! $dry && $stats['users'] && ! $this->option('default-password')) {
            $this->line('Gli utenti importati non hanno password: impostala con  php artisan acidtalk:password email@dominio');
        }

        return self::SUCCESS;
    }
}
