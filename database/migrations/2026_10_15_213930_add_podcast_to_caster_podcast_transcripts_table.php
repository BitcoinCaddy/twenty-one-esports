<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The podcast an episode belongs to (user 2026-10-16: transcripts of several podcasts - EINUNDZWANZIG,
// Blocktrainer, Münzweg): names the roast material's source. Older rows keep null (the EINUNDZWANZIG podcast).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('caster_podcast_transcripts', function (Blueprint $table) {
            $table->string('podcast', 80)->nullable()->after('title')->index();
        });
    }

    public function down(): void
    {
        Schema::table('caster_podcast_transcripts', function (Blueprint $table) {
            $table->dropIndex(['podcast']);
            $table->dropColumn('podcast');
        });
    }
};
