<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mcp\McpServer;
use Illuminate\Http\Request;

class McpController extends Controller
{
    public function __construct(private McpServer $server) {}

    /** JSON-RPC via POST (Streamable HTTP). */
    public function handle(Request $request)
    {
        $response = $this->server->handle($request);
        if ($response === null) {
            return response()->noContent(202);
        }

        return response()->json($response)->header('Mcp-Session-Id', $request->header('Mcp-Session-Id', (string) $request->user()->id));
    }

    /** GET informativo (alcuni client fanno una probe; lo stream SSE non è necessario per le richieste sincrone). */
    public function info(Request $request)
    {
        return response()->json([
            'name' => 'acidtalk', 'protocol' => McpServer::PROTOCOL, 'transport' => 'streamable-http',
            'endpoint' => url('/api/mcp'), 'tools' => array_column($this->server->tools(), 'name'),
            'auth' => 'Authorization: Bearer <token personale creato in Profilo › Token API>',
        ]);
    }
}
