<?php

use App\Http\Controllers\Api\Admin\SettingsController as AdminSettings;
use App\Http\Controllers\Api\Admin\UserController as AdminUsers;
use App\Http\Controllers\Api\Admin\BotController;
use App\Http\Controllers\Api\Admin\SystemController;
use App\Http\Controllers\Api\AttachmentController;
use App\Http\Controllers\Api\AvatarController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChannelController;
use App\Http\Controllers\Api\InviteController;
use App\Http\Controllers\Api\McpController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ReactionController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VideoCallController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API pubblica
|--------------------------------------------------------------------------
| Autenticazione: sessione (SPA web, con cookie + X-XSRF-TOKEN) oppure token
| Sanctum (app mobile, MCP, integrazioni) via header Authorization: Bearer.
*/

Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::get('/invites/{token}', [InviteController::class, 'show'])->middleware('throttle:30,1');
Route::get('/health', fn () => ['ok' => true, 'app' => 'AcidTalk', 'version' => \App\Support\ClientConfig::VERSION, 'time' => now()->toIso8601String()]);

Route::middleware(['auth:sanctum', 'presence'])->group(function () {
    // profilo
    Route::get('/me', [AuthController::class, 'me']);
    // configurazione per app mobile/desktop: organizzazione + realtime (reverb | pusher | null = polling)
    // /client-config è l'indirizzo usato dalle app: su alcuni hosting (es. SiteGround) il firewall blocca le URL che finiscono in /config.
    Route::get('/client-config', [\App\Support\ClientConfig::class, 'response']);
    Route::get('/config', [\App\Support\ClientConfig::class, 'response']);
    // autorizzazione dei canali privati WebSocket con token Bearer (app native)
    Route::post('/broadcasting/auth', fn (\Illuminate\Http\Request $r) => \Illuminate\Support\Facades\Broadcast::auth($r));
    Route::put('/me', [AuthController::class, 'updateMe']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/me/tokens', [AuthController::class, 'tokens']);
    Route::post('/me/tokens', [AuthController::class, 'createToken']);
    Route::delete('/me/tokens/{id}', [AuthController::class, 'revokeToken']);
    Route::post('/me/avatar', [AvatarController::class, 'update']);
    Route::delete('/me/avatar', [AvatarController::class, 'destroy']);

    // rubrica
    Route::get('/users', [UserController::class, 'index']);
    Route::get('/users/{user}', [UserController::class, 'show']);

    // canali
    Route::get('/channels', [ChannelController::class, 'index']);
    Route::post('/channels', [ChannelController::class, 'store']);
    Route::post('/channels/direct', [ChannelController::class, 'direct']);
    Route::post('/channels/ai', [ChannelController::class, 'ai']);
    Route::get('/channels/{channel}', [ChannelController::class, 'show'])->name('channels.show');
    Route::put('/channels/{channel}', [ChannelController::class, 'update']);
    Route::delete('/channels/{channel}', [ChannelController::class, 'destroy']);
    Route::post('/channels/{channel}/archive', [ChannelController::class, 'archive']);
    Route::post('/channels/{channel}/unarchive', [ChannelController::class, 'unarchive']);
    Route::post('/channels/{channel}/join', [ChannelController::class, 'join']);
    Route::post('/channels/{channel}/leave', [ChannelController::class, 'leave']);
    Route::post('/channels/{channel}/members', [ChannelController::class, 'addMembers']);
    Route::delete('/channels/{channel}/members/{user}', [ChannelController::class, 'removeMember']);
    Route::post('/channels/{channel}/read', [ChannelController::class, 'read']);
    Route::put('/channels/{channel}/notify', [ChannelController::class, 'notify']);

    // messaggi
    Route::get('/channels/{channel}/messages', [MessageController::class, 'index']);
    Route::post('/channels/{channel}/messages', [MessageController::class, 'store']);
    Route::get('/messages/{message}', [MessageController::class, 'show']);
    Route::put('/messages/{message}', [MessageController::class, 'update']);
    Route::delete('/messages/{message}', [MessageController::class, 'destroy']);
    Route::delete('/messages/{message}/previews/{preview}', [MessageController::class, 'removePreview']);
    Route::get('/messages/{message}/thread', [MessageController::class, 'thread']);
    Route::post('/messages/{message}/react', [ReactionController::class, 'toggle']);
    // attività del messaggio eseguite dal client subito dopo l'invio: ai | previews | bots
    Route::post('/messages/{message}/tasks/{task}', [MessageController::class, 'runTask'])->where('task', 'ai|previews|bots');
    Route::get('/channels/{channel}/reads', [ChannelController::class, 'reads']);
    Route::get('/search', SearchController::class);

    // allegati
    Route::post('/attachments', [AttachmentController::class, 'store']);
    Route::delete('/attachments/{attachment}', [AttachmentController::class, 'destroy']);

    // videochiamate
    Route::get('/channels/{channel}/calls', [VideoCallController::class, 'index']);
    Route::post('/channels/{channel}/calls', [VideoCallController::class, 'store']);
    Route::post('/calls/{call}/end', [VideoCallController::class, 'end']);

    // inviti
    Route::get('/invites', [InviteController::class, 'index']);
    Route::post('/invites', [InviteController::class, 'store']);
    Route::delete('/invites/{invite}', [InviteController::class, 'destroy']);

    // notifiche
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'read']);
    Route::delete('/notifications', [NotificationController::class, 'destroyAll']);
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);
    Route::post('/push/subscribe', [NotificationController::class, 'subscribePush']);
    Route::post('/push/unsubscribe', [NotificationController::class, 'unsubscribePush']);
    Route::post('/push/devices', [NotificationController::class, 'registerDevice']);
    Route::delete('/push/devices', [NotificationController::class, 'unregisterDevice']);

    // MCP (Model Context Protocol)
    Route::get('/mcp', [McpController::class, 'info']);
    Route::post('/mcp', [McpController::class, 'handle']);

    // amministrazione
    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::get('/settings', [AdminSettings::class, 'show']);
        Route::put('/settings', [AdminSettings::class, 'update']);
        Route::post('/ai/test', [AdminSettings::class, 'testAi']);
        Route::get('/users', [AdminUsers::class, 'index']);
        Route::post('/users', [AdminUsers::class, 'store']);
        Route::put('/users/{user}', [AdminUsers::class, 'update']);
        Route::delete('/users/{user}', [AdminUsers::class, 'destroy']);
        Route::post('/users/{user}/avatar', [AvatarController::class, 'adminUpdate']);
        Route::delete('/users/{user}/avatar', [AvatarController::class, 'adminDestroy']);
        // bot comandabili via API
        Route::get('/bots', [BotController::class, 'index']);
        Route::post('/bots', [BotController::class, 'store']);
        Route::put('/bots/{bot}', [BotController::class, 'update']);
        Route::delete('/bots/{bot}', [BotController::class, 'destroy']);
        Route::post('/bots/{bot}/token', [BotController::class, 'token']);
        Route::delete('/bots/{bot}/token', [BotController::class, 'revoke']);
        Route::post('/bots/{bot}/ping', [BotController::class, 'ping']);
        // stato del sistema e notifiche push
        Route::get('/system', [SystemController::class, 'show']);
        Route::post('/system/run', [SystemController::class, 'run']);
        Route::post('/push/test', [SystemController::class, 'testPush']);
    });
});
