<?php

namespace App\Services\Ai;

interface AiProviderInterface
{
    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     *
     * @throws AiException
     */
    public function chat(array $messages, ?string $systemPrompt = null): string;

    public function name(): string;

    /** Modello effettivamente usato (dopo l'eventuale scelta automatica). */
    public function model(): string;
}
