<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The league voice's counters (App\Support\Voice\VoiceUsage): one row per
     * Berlin day and channel, numbers only, never a text or a name. An attempt
     * is counted before the model is asked, so the daily cap also holds when
     * the request fails; the tokens come from the provider's usage report.
     */
    public function up(): void
    {
        Schema::create('voice_usage', function (Blueprint $table) {
            $table->id();
            $table->date('day');
            $table->string('channel', 40);
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('accepted')->default(0);
            $table->unsignedInteger('rejected')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('cache_read_tokens')->default(0);
            $table->unsignedBigInteger('cache_write_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->timestamps();

            $table->unique(['day', 'channel']);
        });

        // Why texts were rejected: a reason code (VoiceGuard, VoiceHygiene, VoiceMentions) and a count, never the text.
        Schema::create('voice_rejections', function (Blueprint $table) {
            $table->id();
            $table->date('day');
            $table->string('channel', 40);
            $table->string('reason', 40);
            $table->unsignedInteger('count')->default(0);
            $table->timestamps();

            $table->unique(['day', 'channel', 'reason']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_rejections');
        Schema::dropIfExists('voice_usage');
    }
};
