<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The YouTube restream's live chat as the caster summarises it
     * (App\Support\Caster\YouTubeChat): YouTube's message id (deduplication),
     * the speakable display name, the sanitised text (at most 200
     * characters) and its time. No channel id. Kept `retention_hours`, at
     * most `max_rows` (caster:prune).
     */
    public function up(): void
    {
        Schema::create('youtube_chat_lines', function (Blueprint $table) {
            $table->id();
            $table->string('message_id', 191)->unique();
            $table->string('author', 64)->nullable();
            $table->string('message', 210);
            $table->unsignedBigInteger('said_at');
            $table->timestamps();

            $table->index('said_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('youtube_chat_lines');
    }
};
