<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('slug')->nullable();
            $t->string('type')->default('public');       // public | private | dm | ai_dm
            $t->string('topic')->nullable();
            $t->text('description')->nullable();
            $t->boolean('ai_enabled')->default(true);    // il bot risponde a @ai in questo canale
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('last_message_at')->nullable();
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'slug']);
        });

        Schema::create('channel_user', function (Blueprint $t) {
            $t->id();
            $t->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('role')->default('member');       // owner | member
            $t->string('notify')->default('all');        // all | mentions | none
            $t->timestamp('last_read_at')->nullable();
            $t->timestamps();
            $t->unique(['channel_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_user');
        Schema::dropIfExists('channels');
    }
};
