<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('invites', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('email')->index();
            $t->string('role')->default('guest');         // guest | member
            $t->string('token', 64)->unique();
            $t->json('channel_ids')->nullable();          // canali a cui aggiungere l'ospite
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('accepted_at')->nullable();
            $t->timestamps();
        });

        Schema::create('notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('type');
            $t->morphs('notifiable');
            $t->text('data');
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });

        Schema::create('video_calls', function (Blueprint $t) {
            $t->id();
            $t->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('started_by')->constrained('users')->cascadeOnDelete();
            $t->string('provider');                       // jitsi | meet | custom
            $t->string('url', 2048);
            $t->timestamp('ended_at')->nullable();
            $t->timestamps();
        });

        Schema::create('push_subscriptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('endpoint', 500)->unique();
            $t->string('public_key')->nullable();
            $t->string('auth_token')->nullable();
            $t->string('platform')->default('web');       // web | android | ios
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
        Schema::dropIfExists('video_calls');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('invites');
    }
};
