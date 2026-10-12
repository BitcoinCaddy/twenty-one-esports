<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The latest EINUNDZWANZIG podcast episode as a transcript (App\Support\Caster\PodcastTranscripts): uploaded by
     * the workstation (`caster:podcast-transcript`), one row at a time (a newer episode replaces it), with its digest
     * for the TV voice's cached system prompt once Haiku wrote it.
     */
    public function up(): void
    {
        Schema::create('caster_podcast_transcripts', function (Blueprint $table) {
            $table->id();
            $table->string('guid', 255)->unique();
            $table->unsignedInteger('episode_number')->nullable();
            $table->string('title', 255);
            $table->unsignedBigInteger('published_at');
            $table->longText('transcript');
            $table->text('digest')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caster_podcast_transcripts');
    }
};
