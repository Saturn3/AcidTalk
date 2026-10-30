<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Models\Message;
use App\Services\MessageTasks;
use App\Services\Push\FcmSender;
use App\Support\ClientConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Diagnostica: cron, coda, attività in sospeso, realtime, push e ultimi errori. */
class SystemController extends Controller
{
    public function show()
    {
        $heartbeat = Cache::get('acidtalk.cron_heartbeat');
        $jobs = Schema::hasTable('jobs') ? DB::table('jobs')->count() : null;
        $failed = Schema::hasTable('failed_jobs')
            ? DB::table('failed_jobs')->orderByDesc('id')->limit(5)->get(['id', 'queue', 'failed_at', 'exception'])
                ->map(fn ($j) => ['id' => $j->id, 'failed_at' => $j->failed_at, 'error' => mb_substr(strtok((string) $j->exception, "\n"), 0, 300)])
            : collect();
        $pending = Message::whereNotNull('meta')->where('meta', 'like', '%"pending"%')->where('created_at', '>=', now()->subDay())->count();
        $fcm = app(FcmSender::class);

        return response()->json([
            'version' => ClientConfig::VERSION,
            'php' => PHP_VERSION,
            'cron' => [
                'last_run' => $heartbeat,
                'ok' => $heartbeat && Carbon::parse($heartbeat)->gt(now()->subMinutes(3)),
            ],
            'queue' => [
                'connection' => config('queue.default'),
                'worker' => (bool) config('queue.worker'),
                'jobs' => $jobs,
                'failed' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : null,
                'last_failed' => $failed,
            ],
            'tasks' => ['pending' => $pending],
            'broadcast' => config('broadcasting.default'),
            'mail' => config('mail.default'),
            'ai' => [
                'last_reply' => Cache::get('acidtalk.last_ai_reply'),
                'last_error' => Cache::get('acidtalk.last_ai_error'),
            ],
            'push' => [
                'configured' => $fcm->configured(),
                'project_id' => $fcm->projectId(),
                'devices' => Schema::hasTable('device_tokens') ? DeviceToken::count() : 0,
                'last_sent' => Cache::get('acidtalk.last_push'),
                'last_error' => Cache::get('acidtalk.last_push_error'),
            ],
            'extensions' => ['gd' => extension_loaded('gd'), 'openssl' => extension_loaded('openssl'), 'curl' => extension_loaded('curl')],
        ]);
    }

    /** "Esegui ora": attività in sospeso + job in coda, senza aspettare il cron. */
    public function run(MessageTasks $tasks)
    {
        @set_time_limit(120);
        $done = $tasks->sweep(0);
        $jobs = 0;
        if (Schema::hasTable('jobs') && DB::table('jobs')->exists()) {
            Artisan::call('queue:work', ['--stop-when-empty' => true, '--max-time' => 60, '--tries' => 3]);
            $jobs = 1;
        }

        return response()->json(['ok' => true, 'tasks' => $done, 'queue_ran' => (bool) $jobs] + $this->show()->getData(true));
    }

    /** Invia una notifica push di prova ai dispositivi dell'amministratore. */
    public function testPush(Request $request, FcmSender $fcm)
    {
        abort_unless($fcm->configured(), 422, 'Firebase non è configurato: incolla il JSON dell\'account di servizio.');
        $count = $request->user()->deviceTokens()->count();
        abort_unless($count > 0, 422, 'Nessun dispositivo registrato per il tuo account: accedi dall\'app Android o iOS compilata con Firebase.');
        try {
            $r = $fcm->sendToUsers([$request->user()->id], 'AcidTalk', 'Notifica di prova: Firebase è configurato correttamente.', ['type' => 'test']);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }

        return response()->json(['ok' => $r['sent'] > 0] + $r);
    }
}
