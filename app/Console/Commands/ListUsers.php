<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Console\Command;

class ListUsers extends Command
{
    protected $signature = 'acidtalk:users';

    protected $description = 'Elenca gli utenti e il dominio dell\'organizzazione';

    public function handle(): int
    {
        $org = Organization::current();
        $this->line("Organizzazione: <info>{$org->name}</info> · dominio nel database: <info>{$org->domain}</info> · ORG_DOMAIN in config: <info>".config('organization.domain').'</info>');
        $this->table(['ID', 'Nome', 'Email', 'Ruolo'], User::orderBy('id')->get(['id', 'name', 'email', 'role'])->toArray());

        return self::SUCCESS;
    }
}
