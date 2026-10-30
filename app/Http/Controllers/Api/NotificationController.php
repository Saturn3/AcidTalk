<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'data' => $user->notifications()->limit(50)->get()->map(fn ($n) => [
                'id' => $n->id, 'read_at' => $n->read_at?->toIso8601String(), 'created_at' => $n->created_at->toIso8601String(),
            ] + $n->data),
            'unread' => $user->unreadNotifications()->count(),
        ]);
    }

    public function read(Request $request, string $id)
    {
        $request->user()->notifications()->whereKey($id)->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function readAll(Request $request)
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    /** Elimina una notifica. */
    public function destroy(Request $request, string $id)
    {
        $request->user()->notifications()->whereKey($id)->delete();

        return response()->json(['ok' => true]);
    }

    /** Elimina tutte le notifiche dell'utente (oppure solo quelle già lette con ?read=1). */
    public function destroyAll(Request $request)
    {
        $q = $request->user()->notifications();
        if ($request->boolean('read')) {
            $q->whereNotNull('read_at');
        }

        return response()->json(['ok' => true, 'deleted' => $q->delete()]);
    }

    public function subscribePush(Request $request)
    {
        $data = $request->validate(['endpoint' => 'required|string|max:500', 'public_key' => 'nullable|string', 'auth_token' => 'nullable|string', 'platform' => 'nullable|in:web,android,ios']);
        $sub = PushSubscription::updateOrCreate(['endpoint' => $data['endpoint']], $data + ['user_id' => $request->user()->id]);

        return response()->json($sub, 201);
    }

    public function unsubscribePush(Request $request)
    {
        PushSubscription::where('user_id', $request->user()->id)->where('endpoint', $request->input('endpoint'))->delete();

        return response()->json(['ok' => true]);
    }

    /** Registra il token FCM del dispositivo (app Android/iOS/macOS) per le notifiche push. */
    public function registerDevice(Request $request)
    {
        $data = $request->validate([
            'token' => 'required|string|max:500',
            'platform' => 'required|in:android,ios,macos,web',
            'device_name' => 'nullable|string|max:120',
        ]);
        $device = \App\Models\DeviceToken::updateOrCreate(['token' => $data['token']], $data + ['user_id' => $request->user()->id, 'last_used_at' => now()]);

        return response()->json(['ok' => true, 'id' => $device->id, 'push_enabled' => app(\App\Services\Push\FcmSender::class)->configured()]);
    }

    public function unregisterDevice(Request $request)
    {
        $request->validate(['token' => 'required|string']);
        \App\Models\DeviceToken::where('user_id', $request->user()->id)->where('token', $request->input('token'))->delete();

        return response()->json(['ok' => true]);
    }
}
