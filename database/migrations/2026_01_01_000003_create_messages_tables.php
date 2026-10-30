<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // null = AI / sistema
            $t->foreignId('parent_id')->nullable()->constrained('messages')->cascadeOnDelete(); // thread
            $t->text('body')->nullable();                 // Markdown
            $t->string('kind')->default('text');          // text | ai | system | voice | call
            $t->boolean('is_ai')->default(false);
            $t->json('meta')->nullable();                 // provider, durata audio, call url, ...
            $t->unsignedInteger('reply_count')->default(0);
            $t->timestamp('last_reply_at')->nullable();
            $t->timestamp('edited_at')->nullable();
            $t->softDeletes();
            $t->timestamps();
            $t->index(['channel_id', 'created_at']);
            $t->index(['parent_id']);
        });

        Schema::create('attachments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('message_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('disk')->default('public');
            $t->string('path');
            $t->string('name');
            $t->string('mime')->nullable();
            $t->unsignedBigInteger('size')->default(0);
            $t->string('kind')->default('file');          // image | pdf | audio | video | file
            $t->json('meta')->nullable();                 // width/height, duration
            $t->timestamps();
        });

        Schema::create('reactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('message_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('emoji', 32);
            $t->timestamps();
            $t->unique(['message_id', 'user_id', 'emoji']);
        });

        Schema::create('link_previews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('message_id')->constrained()->cascadeOnDelete();
            $t->string('url', 2048);
            $t->string('kind')->default('link');          // link | video_call | image
            $t->string('provider')->nullable();           // meet | jitsi | zoom | teams | ...
            $t->string('title')->nullable();
            $t->text('description')->nullable();
            $t->string('image', 2048)->nullable();
            $t->string('site_name')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_previews');
        Schema::dropIfExists('reactions');
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('messages');
    }
};
