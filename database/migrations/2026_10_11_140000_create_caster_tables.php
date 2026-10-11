<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TWENTY ONE TV commentary (plan "KI-Stimme der Liga", P6).
     *
     * caster_scripts: the queue of scene scripts the league voice wrote ahead
     * (App\Support\Caster\ScriptQueue); text only, one sentence per line.
     * caster_clips: the server's audio spool (App\Support\Caster\ClipSpool);
     * the audio itself is an Opus file on disk, never a database blob, and
     * the row goes with its file.
     *
     * chatter_*: the database cache behind spoken chat reactions (plan
     * "Zwischenspeicher in der DB"): the public kind 0 snapshot and persona
     * card per trusted chatter, their newest public notes (rolling, capped per
     * pubkey and in total), the last exchanges as keywords, and the
     * web-of-trust levels of the league's trust anchors (pubkey => 1 or 2).
     */
    public function up(): void
    {
        Schema::create('caster_scripts', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 12);
            $table->string('phase', 20);
            $table->string('scene', 12)->nullable();
            $table->unsignedSmallInteger('priority')->default(0);
            $table->text('text');
            $table->string('facts_hash', 64);
            $table->string('status', 10)->default('queued');
            $table->float('estimated_seconds');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['status', 'priority', 'id']);
            $table->index(['phase', 'status']);
        });

        Schema::create('caster_clips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('caster_script_id')->constrained()->cascadeOnDelete();
            $table->string('phase', 20);
            $table->string('kind', 12);
            $table->string('path', 120)->unique();
            $table->float('duration_seconds');
            $table->unsignedInteger('bytes');
            $table->json('sentence_ends');
            $table->string('status', 10)->default('ready');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['status', 'phase']);
        });

        Schema::create('chatter_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('pubkey', 64)->unique();
            $table->string('name', 80)->nullable();
            $table->string('about', 300)->nullable();
            $table->unsignedBigInteger('profile_created_at')->nullable();
            $table->unsignedBigInteger('newest_note_at')->nullable();
            $table->string('persona', 400)->nullable();
            $table->timestamp('persona_at')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamp('last_chat_at')->nullable();
            $table->timestamp('last_reaction_at')->nullable();
            $table->timestamps();

            $table->index('last_chat_at');
        });

        Schema::create('chatter_notes', function (Blueprint $table) {
            $table->id();
            $table->string('pubkey', 64);
            $table->string('event_id', 64)->unique();
            $table->unsignedBigInteger('note_created_at');
            $table->string('content', 500);
            $table->timestamps();

            $table->index(['pubkey', 'note_created_at']);
        });

        Schema::create('chatter_exchanges', function (Blueprint $table) {
            $table->id();
            $table->string('pubkey', 64);
            $table->string('keywords', 200);
            $table->timestamps();

            $table->index(['pubkey', 'id']);
        });

        Schema::create('wot_levels', function (Blueprint $table) {
            $table->string('pubkey', 64)->primary();
            $table->unsignedTinyInteger('level');
            $table->timestamp('computed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wot_levels');
        Schema::dropIfExists('chatter_exchanges');
        Schema::dropIfExists('chatter_notes');
        Schema::dropIfExists('chatter_profiles');
        Schema::dropIfExists('caster_clips');
        Schema::dropIfExists('caster_scripts');
    }
};
