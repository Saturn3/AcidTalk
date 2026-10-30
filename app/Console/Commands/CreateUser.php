<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\AuthController;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Console\Command;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class CreateUser extends Command
{
    protected $signature = 'acidtalk:user {--name=} {--email=} {--password=} {--role=member : admin|member|guest}';

    protected $description = 'Crea un utente AcidTalk (interattivo se mancano le opzioni)';

    public function handle(): int
    {
        $name = $this->option('name') ?: text('Nome completo', required: true);
        $email = $this->option('email') ?: text('Email', required: true, validate: fn ($v) => filter_var($v, FILTER_VALIDATE_EMAIL) ? null : 'Email non valida');
        $pass = $this->option('password') ?: password('Password (min 8 caratteri)', required: true);
        $role = $this->option('role') ?: select('Ruolo', ['admin' => 'Amministratore', 'member' => 'Membro', 'guest' => 'Ospite'], 'member');

        if (User::where('email', strtolower($email))->exists()) {
            $this->error("Esiste già un utente con email {$email}.");

            return self::FAILURE;
        }
        $user = User::create(['organization_id' => Organization::current()->id, 'name' => $name, 'email' => strtolower($email), 'password' => $pass, 'role' => $role, 'email_verified_at' => now()]);
        AuthController::onboard($user);
        $this->info("Utente #{$user->id} creato: {$user->name} <{$user->email}> ({$user->role}). Accedi su ".url('/login'));

        return self::SUCCESS;
    }
}
