<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corrispondenze tra oggetti di sistemi esterni (es. Zulip) e record AcidTalk.
 * Permettono di rilanciare un'importazione senza creare duplicati.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('import_mappings', function (Blueprint $t) {
            $t->id();
            $t->string('source', 191);        // es. zulip:chat.example.com
            $t->string('type', 32);           // user | channel | message | topic | dm
            $t->string('external_id', 191);
            $t->unsignedBigInteger('local_id');
            $t->timestamps();
            $t->unique(['source', 'type', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_mappings');
    }
};
