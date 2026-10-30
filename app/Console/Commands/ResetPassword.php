<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

use function Laravel\Prompts\password;

class ResetPassword extends Command
{
    protected $signature = 'acidtalk:password {email} {--password= : nuova password (altrimenti viene chiesta)} {--admin : promuove l\'utente ad amministratore}';

    protected $description = 'Reimposta la password di un utente AcidTalk';

    public function handle(): int
    {
        $user = User::where('email', strtolower($this->argument('email')))->first();
        if (! $user) {
            $this->error('Nessun utente con email '.$this->argument('email').'. Utenti presenti:');
            $this->call('acidtalk:users');

            return self::FAILURE;
        }
        $pass = $this->option('password') ?: password('Nuova password (min 8 caratteri)', required: true, validate: fn ($v) => strlen($v) < 8 ? 'Almeno 8 caratteri' : null);
        $user->password = $pass;          // cast "hashed" sul modello
        if ($this->option('admin')) {
            $user->role = 'admin';
        }
        $user->save();
        $this->info("Password aggiornata per {$user->email} ({$user->role}).");

        return self::SUCCESS;
    }
}
