<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\Response;
use RuntimeException;

/** Errore del provider AI con un messaggio comprensibile per l'amministratore. */
class AiException extends RuntimeException
{
    public static function fromResponse(string $provider, Response $res, string $model = ''): self
    {
        $json = $res->json();
        $detail = is_array($json)
            ? (data_get($json, 'error.message') ?? (is_string($json['error'] ?? null) ? $json['error'] : null) ?? data_get($json, 'message'))
            : null;
        $detail = $detail ?: mb_substr(trim(strip_tags($res->body())), 0, 200);
        $status = $res->status();

        $hint = match (true) {
            $status === 401 || $status === 403 => 'chiave API non valida o senza permessi',
            $status === 404 => "modello \"{$model}\" non trovato",
            $status === 429 => 'limite di richieste o di spesa raggiunto',
            $status === 529 || $status === 503 => 'servizio temporaneamente sovraccarico',
            $status === 400 && stripos((string) $detail, 'credit') !== false => 'credito API esaurito',
            default => 'richiesta rifiutata',
        };

        return new self("{$provider}: {$hint} (HTTP {$status}). {$detail}", $status);
    }
}
