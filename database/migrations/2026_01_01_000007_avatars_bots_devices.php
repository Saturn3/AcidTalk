<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2.4: avatar con immagine, utenti bot comandabili via API (con webhook in uscita)
 * e token dei dispositivi per le notifiche push Firebase (FCM).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('avatar_path')->nullable()->after('avatar_color');
            $t->boolean('is_bot')->default(false)->after('role');
            $t->foreignId('bot_owner_id')->nullable()->after('is_bot');
            $t->string('bot_description', 500)->nullable()->after('bot_owner_id');
            $t->string('bot_webhook_url', 500)->nullable()->after('bot_description');
            $t->string('bot_webhook_secret', 100)->nullable()->after('bot_webhook_url');
        });

        Schema::create('device_tokens', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('token', 500)->unique();
            $t->string('platform', 20)->default('android'); // android | ios | macos | web
            $t->string('device_name')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['avatar_path', 'is_bot', 'bot_owner_id', 'bot_description', 'bot_webhook_url', 'bot_webhook_secret']);
        });
    }
};
