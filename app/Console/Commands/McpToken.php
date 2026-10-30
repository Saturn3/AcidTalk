<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class McpToken extends Command
{
    protected $signature = 'acidtalk:token {email} {--name=mcp}';

    protected $description = 'Genera un token API personale (per MCP / app mobile) per un utente';

    public function handle(): int
    {
        $user = User::where('email', strtolower($this->argument('email')))->first();
        if (! $user) {
            $this->error('Utente non trovato.');

            return self::FAILURE;
        }
        $token = $user->createToken($this->option('name'))->plainTextToken;
        $this->line($token);
        $this->comment('Usa: Authorization: Bearer <token>  su '.url('/api/mcp'));

        return self::SUCCESS;
    }
}
